<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Path;

use Minhyung\Flysystem\Mybox\Cache\DirectoryCache;
use Minhyung\Flysystem\Mybox\Cache\DirectorySnapshot;
use Minhyung\Mybox\Api\DriveApi;
use Minhyung\Mybox\Api\FileApi;
use Minhyung\Mybox\Exception\ConflictException;
use Minhyung\Mybox\Model\ResourceItem;
use Minhyung\Mybox\Request\ListOptions;

/**
 * Turns locations into MYBOX resource ids, one directory listing at a time.
 *
 * MYBOX addresses everything by an opaque id and its only path-aware endpoint —
 * search — is capped at ten calls a minute and served from an index that lags a
 * just-created folder. Flysystem's contract is write-then-read, so this resolver
 * never touches search: it walks down from the drive root with listing calls and
 * keeps the *whole* listing rather than the one id it was after. That is why
 * `fileExists` + `fileSize` + `lastModified` + `mimeType` on one path cost a
 * single request, and why resolving `a/b/c/d.txt` leaves every ancestor and
 * every sibling of every ancestor warm.
 *
 * Nothing here throws a domain exception: a missing path is null, and SDK
 * exceptions pass through untouched so the adapter can decide which
 * `UnableTo*` to raise.
 */
final class ResourceLocator
{
    public const DEFAULT_MAX_ENTRIES_PER_DIRECTORY = 5000;

    public function __construct(
        private readonly DriveApi $drive,
        private readonly FileApi $files,
        private readonly DirectoryCache $cache,
        private readonly int $pageSize = ListOptions::MAX_COUNT,
        private readonly int $maxEntriesPerDirectory = self::DEFAULT_MAX_ENTRIES_PER_DIRECTORY,
    ) {
    }

    public function findFolder(string $location): ?FolderRef
    {
        if ($location === '') {
            return FolderRef::root();
        }

        $snapshot = $this->snapshot(PathTranslator::dirname($location));

        if ($snapshot === null) {
            return null;
        }

        $entry = $snapshot->folder(PathTranslator::basename($location));

        return $entry === null ? null : FolderRef::of($entry->id);
    }

    public function findFile(string $location): ?ResourceEntry
    {
        if ($location === '') {
            return null;
        }

        $snapshot = $this->snapshot(PathTranslator::dirname($location));

        return $snapshot?->file(PathTranslator::basename($location));
    }

    /**
     * A file or a folder, whichever is at this location.
     */
    public function findAny(string $location): ?ResourceEntry
    {
        if ($location === '') {
            return null;
        }

        $snapshot = $this->snapshot(PathTranslator::dirname($location));

        return $snapshot?->entry(PathTranslator::basename($location));
    }

    /**
     * The listing of a directory, or null when the directory does not exist.
     */
    public function snapshot(string $location): ?DirectorySnapshot
    {
        $cached = $this->cache->get(self::cacheKey($location));

        if ($cached !== null) {
            return $cached;
        }

        $folder = $this->findFolder($location);

        return $folder === null ? null : $this->fetch($location, $folder);
    }

    /**
     * Creates every missing segment of a directory path and returns the deepest one.
     */
    public function ensureFolder(string $location): FolderRef
    {
        if ($location === '') {
            return FolderRef::root();
        }

        $existing = $this->findFolder($location);

        if ($existing !== null) {
            return $existing;
        }

        $parentLocation = PathTranslator::dirname($location);
        $parent = $this->ensureFolder($parentLocation);
        $name = PathTranslator::basename($location);

        try {
            $created = $this->files->createFolder($name, $parent->id);
        } catch (ConflictException $exception) {
            // Someone else created it between our listing and our call. MYBOX has no
            // isOverwrite on createFolder, so a 409 here is a race, not a failure.
            $this->cache->forget(self::cacheKey($parentLocation));
            $found = $this->findFolder($location);

            if ($found === null) {
                throw $exception;
            }

            return $found;
        }

        $ref = FolderRef::of($created->resourceId);
        $now = time();

        $this->recordCreated($parentLocation, ResourceEntry::folder($created->resourceId, $created->name, $now));

        if ($created->name === $name) {
            // A folder MYBOX just made is provably empty, so this is the one place
            // where seeding a snapshot is exact rather than optimistic.
            $this->cache->put(self::cacheKey($location), DirectorySnapshot::empty($ref, $now));
        }

        return $ref;
    }

    /**
     * The drive root's own resource id, learned from what its children report.
     *
     * The root is not listable by id, but every {@see ResourceItem} carries a
     * non-null parentId, so a top-level child names it. Null when the drive root
     * is empty, in which case there is nothing to learn it from.
     */
    public function rootId(): ?string
    {
        return $this->snapshot('')?->childParentId;
    }

    public function recordCreated(string $directory, ResourceEntry $entry): void
    {
        $key = self::cacheKey($directory);
        $snapshot = $this->cache->get($key);

        if ($snapshot === null) {
            $this->cache->forget($key);

            return;
        }

        $this->cache->put($key, $snapshot->with($entry));
    }

    public function recordRemoved(string $directory, string $name, bool $isFolder): void
    {
        $key = self::cacheKey($directory);
        $snapshot = $this->cache->get($key);

        if ($snapshot === null) {
            $this->cache->forget($key);

            return;
        }

        $this->cache->put($key, $snapshot->without($name, $isFolder));
    }

    public function invalidate(string $directory): void
    {
        $this->cache->forget(self::cacheKey($directory));
    }

    public function invalidateSubtree(string $directory): void
    {
        $this->cache->forgetSubtree(self::cacheKey($directory));
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    /**
     * MYBOX matches names case-insensitively, so `Docs` and `docs` are one
     * directory. Folding the key keeps them one cache entry too — otherwise a
     * write through one spelling would leave the other spelling's snapshot stale.
     */
    private static function cacheKey(string $location): string
    {
        return NameKey::foldPath($location);
    }

    private function fetch(string $location, FolderRef $folder): DirectorySnapshot
    {
        $options = new ListOptions(count: $this->pageSize);
        $folderId = $folder->id;
        $pages = $folderId === null
            ? $this->drive->listRootAll($options)
            : $this->drive->listFolderAll($folderId, $options);

        $entries = [];
        $complete = true;
        $childParentId = null;

        foreach ($pages as $item) {
            if (count($entries) >= $this->maxEntriesPerDirectory) {
                $complete = false;
                break;
            }

            $childParentId ??= $item->parentId;
            $entries[] = ResourceEntry::fromResourceItem($item);
        }

        $snapshot = DirectorySnapshot::of($folder, $entries, $complete, time(), $childParentId);
        $this->cache->put(self::cacheKey($location), $snapshot);

        return $snapshot;
    }
}
