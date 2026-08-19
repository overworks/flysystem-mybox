<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox;

use DateTimeInterface;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Minhyung\Flysystem\Mybox\Cache\DirectoryCache;
use Minhyung\Flysystem\Mybox\Cache\MemoryDirectoryCache;
use Minhyung\Flysystem\Mybox\Enum\DeletionMode;
use Minhyung\Flysystem\Mybox\Path\FolderRef;
use Minhyung\Flysystem\Mybox\Path\PathTranslator;
use Minhyung\Flysystem\Mybox\Path\ResourceEntry;
use Minhyung\Flysystem\Mybox\Path\ResourceLocator;
use Minhyung\Flysystem\Mybox\Stream\PsrStreamResource;
use Minhyung\Flysystem\Mybox\Support\FailureReason;
use Minhyung\Flysystem\Mybox\Upload\UploadSize;
use Minhyung\Mybox\Exception\LockedException;
use Minhyung\Mybox\Exception\MyboxException;
use Minhyung\Mybox\Exception\NotFoundException;
use Minhyung\Mybox\MyboxClient;
use Minhyung\Mybox\Request\CopyOptions;
use Throwable;

/**
 * A Flysystem adapter over the Naver MYBOX Open API.
 *
 * Two facts about MYBOX shape everything here. It addresses resources by an
 * opaque id rather than a path, so a path has to be walked before it can be
 * used; and it has no visibility model, no public sharing endpoint and no
 * checksum, so three optional Flysystem interfaces are deliberately absent.
 * See {@see ResourceLocator} for why the walk is cheap in practice.
 */
final class MyboxAdapter implements FilesystemAdapter, TemporaryUrlGenerator
{
    /** Config key carrying the exact byte length of a `writeStream()` payload. */
    public const OPTION_SIZE = 'size';

    private readonly MyboxAdapterOptions $options;

    private readonly MimeTypeDetector $mimeTypeDetector;

    private readonly PathTranslator $paths;

    private readonly ResourceLocator $locator;

    /**
     * @param string $rootDirectory A folder to confine the adapter to. It is created on the
     *                              first write; no request is made from this constructor, so
     *                              the adapter stays cheap to build in a service provider.
     */
    public function __construct(
        private readonly MyboxClient $client,
        string $rootDirectory = '',
        ?MyboxAdapterOptions $options = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
        ?DirectoryCache $directoryCache = null,
    ) {
        $this->options = $options ?? new MyboxAdapterOptions();
        // Never a finfo detector: MYBOX sends no mime type, so sniffing content would
        // mean downloading the whole file to answer a metadata call.
        $this->mimeTypeDetector = $mimeTypeDetector ?? new ExtensionMimeTypeDetector();
        $this->paths = new PathTranslator($rootDirectory);
        $this->locator = new ResourceLocator(
            $client->drive(),
            $client->files(),
            $directoryCache ?? new MemoryDirectoryCache(
                $this->options->cacheTtlSeconds,
                $this->options->cacheMaxDirectories,
            ),
            $this->options->listPageSize,
            $this->options->cacheMaxEntriesPerDirectory,
        );
    }

    /**
     * Drops every cached listing. Call it after mutating the drive through the SDK
     * directly, or when a long-lived process must see another client's writes now.
     */
    public function clearCache(): void
    {
        $this->locator->flush();
    }

    public function fileExists(string $path): bool
    {
        try {
            // Deliberately not DriveApi::get(): a trashed resource is still readable
            // by id, so only a parent listing can answer this after a delete.
            return $this->locator->findFile($this->paths->location($path)) !== null;
        } catch (MyboxException $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            return $this->locator->findFolder($this->paths->location($path)) !== null;
        } catch (MyboxException $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->paths->location($path);
        $directory = PathTranslator::dirname($location);

        try {
            $parent = $this->locator->ensureFolder($directory);
            $result = $this->retryWhileLocked(fn () => $this->client->upload()->fromString(
                $contents,
                PathTranslator::basename($location),
                $parent->id,
                isOverwrite: true,
            ));

            $this->locator->recordCreated(
                $directory,
                ResourceEntry::file($result->resourceId, $result->name, $result->fileSize, time()),
            );
        } catch (MyboxException $exception) {
            $this->locator->invalidate($directory);

            throw UnableToWriteFile::atLocation($path, FailureReason::of($exception), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->paths->location($path);
        $directory = PathTranslator::dirname($location);
        $payload = UploadSize::resolve($path, $contents, $config, $this->options);

        try {
            $parent = $this->locator->ensureFolder($directory);
            $result = $this->retryWhileLocked(fn () => $this->client->upload()->fromStream(
                $payload->stream,
                PathTranslator::basename($location),
                $payload->size,
                $parent->id,
                isOverwrite: true,
            ));

            $this->locator->recordCreated(
                $directory,
                ResourceEntry::file($result->resourceId, $result->name, $result->fileSize, time()),
            );
        } catch (MyboxException $exception) {
            $this->locator->invalidate($directory);

            throw UnableToWriteFile::atLocation($path, FailureReason::of($exception), $exception);
        } finally {
            $payload->release();
        }
    }

    public function read(string $path): string
    {
        $entry = $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToReadFile::fromLocation($path, $reason, $previous));

        try {
            return $this->client->download()->contents($entry->id);
        } catch (MyboxException $exception) {
            throw UnableToReadFile::fromLocation($path, FailureReason::of($exception), $exception);
        }
    }

    public function readStream(string $path)
    {
        $entry = $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToReadFile::fromLocation($path, $reason, $previous));

        try {
            return PsrStreamResource::wrap($this->client->download()->open($entry->id));
        } catch (MyboxException $exception) {
            throw UnableToReadFile::fromLocation($path, FailureReason::of($exception), $exception);
        }
    }

    public function delete(string $path): void
    {
        $location = $this->paths->location($path);
        $directory = PathTranslator::dirname($location);
        $name = PathTranslator::basename($location);

        try {
            $entry = $this->locator->findAny($location);

            if ($entry === null) {
                return; // Flysystem requires deleting a missing file to be a no-op.
            }

            if ($entry->isFolder) {
                throw UnableToDeleteFile::atLocation($path, 'The path is a directory.');
            }

            $this->remove($entry->id);
            $this->locator->recordRemoved($directory, $name, false);
        } catch (MyboxException $exception) {
            $this->locator->invalidate($directory);

            throw UnableToDeleteFile::atLocation($path, FailureReason::of($exception), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->paths->location($path);

        try {
            $folder = $this->locator->findFolder($location);

            if ($folder === null) {
                return; // As with delete(), a missing directory is not an error.
            }

            if ($folder->isRoot()) {
                // The drive root has no id to delete, so empty it child by child.
                $this->clearRoot($location);

                return;
            }

            $this->remove((string) $folder->id);
            $this->locator->recordRemoved(PathTranslator::dirname($location), PathTranslator::basename($location), true);
        } catch (MyboxException $exception) {
            $this->locator->invalidate(PathTranslator::dirname($location));

            throw UnableToDeleteDirectory::atLocation($path, FailureReason::of($exception), $exception);
        } finally {
            $this->locator->invalidateSubtree($location);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->paths->location($path);

        try {
            $this->locator->ensureFolder($location);
        } catch (MyboxException $exception) {
            $this->locator->invalidate(PathTranslator::dirname($location));

            throw UnableToCreateDirectory::atLocation($path, FailureReason::of($exception), $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($this->options->failOnSetVisibility) {
            throw UnableToSetVisibility::atLocation(
                $path,
                'MYBOX has no per-file visibility model, so this adapter reports a fixed visibility.',
            );
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToRetrieveMetadata::visibility($path, $reason, $previous));

        return new FileAttributes($path, visibility: $this->options->visibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToRetrieveMetadata::mimeType($path, $reason, $previous));

        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'MYBOX reports no mime type, and the file extension is not recognised.');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $entry = $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToRetrieveMetadata::lastModified($path, $reason, $previous));

        return new FileAttributes($path, lastModified: $entry->lastModified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $entry = $this->requireFile($path, static fn (string $reason, ?Throwable $previous = null) => UnableToRetrieveMetadata::fileSize($path, $reason, $previous));

        return new FileAttributes($path, fileSize: $entry->fileSize);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            // Deliberately not `yield from`: it restarts key numbering, so a nested
            // directory's entries would collide with their parent's under
            // iterator_to_array(), silently dropping items.
            foreach ($this->walk($this->paths->location($path), $deep) as $attributes) {
                yield $attributes;
            }
        } catch (MyboxException $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $sourceLocation = $this->paths->location($source);
        $destinationLocation = $this->paths->location($destination);

        if ($sourceLocation === $destinationLocation) {
            return;
        }

        $sourceDirectory = PathTranslator::dirname($sourceLocation);
        $destinationDirectory = PathTranslator::dirname($destinationLocation);
        $destinationName = PathTranslator::basename($destinationLocation);

        try {
            $entry = $this->locator->findFile($sourceLocation);

            if ($entry === null) {
                throw UnableToMoveFile::fromLocationTo($source, $destination);
            }

            $parent = $this->locator->ensureFolder($destinationDirectory);
            $this->clearDestination($destinationLocation);
            $this->relocate($entry, $sourceDirectory, $parent, $destinationDirectory, $destinationName);

            $this->locator->recordRemoved($sourceDirectory, $entry->name, false);
            $this->locator->recordCreated($destinationDirectory, $entry->renamedTo($destinationName));
        } catch (MyboxException $exception) {
            $this->locator->invalidate($sourceDirectory);
            $this->locator->invalidate($destinationDirectory);

            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $sourceLocation = $this->paths->location($source);
        $destinationLocation = $this->paths->location($destination);
        $destinationDirectory = PathTranslator::dirname($destinationLocation);

        try {
            $entry = $this->locator->findFile($sourceLocation);

            if ($entry === null) {
                throw UnableToCopyFile::fromLocationTo($source, $destination);
            }

            if ($sourceLocation === $destinationLocation) {
                return;
            }

            $parent = $this->locator->ensureFolder($destinationDirectory);
            $created = $this->client->files()->copy($entry->id, new CopyOptions(
                parentId: $parent->id,
                name: PathTranslator::basename($destinationLocation),
                isOverwrite: true,
            ));

            $this->locator->recordCreated(
                $destinationDirectory,
                ResourceEntry::file($created->resourceId, $created->name, $entry->fileSize, time()),
            );
        } catch (MyboxException $exception) {
            $this->locator->invalidate($destinationDirectory);

            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * A short-lived download URL for a file.
     *
     * MYBOX ignores `$expiresAt`: the URL lives for the ten minutes it grants, and
     * it is **single-use** — a second request for it fails. Hand it to one client
     * immediately; never put it in a cached page, an email, or a retryable job.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        $entry = $this->locator->findFile($this->paths->location($path));

        if ($entry === null) {
            throw new UnableToGenerateTemporaryUrl('MYBOX has no file at this path.', $path);
        }

        try {
            $ticket = $this->client->files()->createDownloadUrl($entry->id);
        } catch (MyboxException $exception) {
            throw new UnableToGenerateTemporaryUrl(FailureReason::of($exception), $path, $exception);
        }

        if ($this->options->strictTemporaryUrlExpiry && $expiresAt->getTimestamp() > time() + $ticket->expiresIn) {
            throw new UnableToGenerateTemporaryUrl(
                sprintf('MYBOX download URLs live for %d seconds, which is shorter than the requested expiry.', $ticket->expiresIn),
                $path,
            );
        }

        return $ticket->downloadUrl;
    }

    /**
     * @return \Generator<int, FileAttributes|DirectoryAttributes>
     */
    private function walk(string $location, bool $deep): \Generator
    {
        $snapshot = $this->locator->snapshot($location);

        if ($snapshot === null) {
            return; // Listing a directory that is not there yields nothing, and does not throw.
        }

        foreach ($snapshot->entries() as $entry) {
            $childLocation = PathTranslator::join($location, $entry->name);
            $childPath = $this->paths->path($childLocation);

            if ($entry->isFolder) {
                yield new DirectoryAttributes($childPath, null, $entry->lastModified);

                if ($deep) {
                    foreach ($this->walk($childLocation, true) as $nested) {
                        yield $nested;
                    }
                }

                continue;
            }

            yield new FileAttributes(
                $childPath,
                $entry->fileSize,
                $this->options->visibility,
                $entry->lastModified,
                $this->mimeTypeDetector->detectMimeTypeFromPath($childPath),
                ['resource_id' => $entry->id],
            );
        }
    }

    /**
     * Resolves a path to a file, or raises the caller's exception.
     *
     * @param callable(string, ?Throwable=): Throwable $failure
     */
    private function requireFile(string $path, callable $failure): ResourceEntry
    {
        $location = $this->paths->location($path);

        try {
            $entry = $this->locator->findAny($location);
        } catch (MyboxException $exception) {
            throw $failure(FailureReason::of($exception), $exception);
        }

        if ($entry === null) {
            throw $failure('MYBOX has no file at this path.');
        }

        if ($entry->isFolder) {
            throw $failure('The path is a directory.');
        }

        return $entry;
    }

    /**
     * Moves a file to a new parent and/or a new name.
     *
     * MYBOX splits what Flysystem treats as one operation: `move()` changes only
     * the parent, `rename()` changes only the name and has no overwrite flag. So
     * both may be needed, and the destination has to be free before the rename.
     */
    private function relocate(
        ResourceEntry $entry,
        string $sourceDirectory,
        FolderRef $destinationParent,
        string $destinationDirectory,
        string $destinationName,
    ): void {
        $files = $this->client->files();

        if ($sourceDirectory !== $destinationDirectory) {
            $parentId = $destinationParent->id ?? $this->locator->rootId();

            if ($parentId === null) {
                // The drive root is empty, so nothing has told us its id. Copy and
                // delete instead: copy() accepts a null parent, move() does not.
                $created = $files->copy($entry->id, new CopyOptions(name: $destinationName, isOverwrite: true));
                $this->remove($entry->id);
                $this->locator->recordCreated($destinationDirectory, $entry->renamedTo($created->name));

                return;
            }

            $this->retryWhileLocked(static fn () => $files->move($entry->id, $parentId, isOverwrite: true));
        }

        if ($entry->name !== $destinationName) {
            $files->rename($entry->id, $destinationName);
        }
    }

    /**
     * Frees a move destination, because `rename()` has no overwrite flag.
     */
    private function clearDestination(string $destinationLocation): void
    {
        $occupant = $this->locator->findFile($destinationLocation);

        if ($occupant === null) {
            return;
        }

        $this->remove($occupant->id);
        $this->locator->recordRemoved(
            PathTranslator::dirname($destinationLocation),
            $occupant->name,
            false,
        );
    }

    private function clearRoot(string $location): void
    {
        $snapshot = $this->locator->snapshot($location);

        if ($snapshot === null) {
            return;
        }

        foreach ($snapshot->entries() as $entry) {
            $this->remove($entry->id);
        }
    }

    private function remove(string $resourceId): void
    {
        $this->retryWhileLocked(fn () => $this->client->files()->delete($resourceId));

        if ($this->options->deletionMode !== DeletionMode::Purge) {
            return;
        }

        try {
            $this->retryWhileLocked(fn () => $this->client->trash()->purge($resourceId));
        } catch (NotFoundException) {
            // Already gone from the trash; the caller's intent is satisfied either way.
        }
    }

    /**
     * MYBOX locks a resource for a second or two after an interrupted upload, and
     * the SDK's retry policy deliberately leaves 423 alone.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function retryWhileLocked(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (LockedException $exception) {
                if ($attempt >= $this->options->lockedRetries) {
                    throw $exception;
                }

                ++$attempt;
                usleep((int) ($this->options->lockedRetryDelaySeconds * 1_000_000));
            }
        }
    }
}
