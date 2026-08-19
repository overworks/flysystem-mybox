<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Cache;

use Minhyung\Flysystem\Mybox\Path\FolderRef;
use Minhyung\Flysystem\Mybox\Path\NameKey;
use Minhyung\Flysystem\Mybox\Path\ResourceEntry;

/**
 * The children of one directory, as MYBOX last reported them.
 *
 * Files and folders are held in separate maps because MYBOX allows a file and a
 * folder of the same name under one parent; collapsing them would make
 * `fileExists()` answer true for a directory.
 */
final class DirectorySnapshot
{
    /**
     * @param array<string, ResourceEntry> $files   keyed by {@see self::key()}
     * @param array<string, ResourceEntry> $folders keyed by {@see self::key()}
     * @param bool $complete False when the listing was cut short by the entry cap, in
     *                       which case a miss means "unknown", not "absent".
     * @param string|null $childParentId The parent id MYBOX reports on these children. For the
     *                                   drive root this is the only way to learn its id, which
     *                                   {@see \Minhyung\Mybox\Api\FileApi::move()} needs.
     */
    private function __construct(
        public readonly FolderRef $folder,
        private readonly array $files,
        private readonly array $folders,
        private readonly bool $complete,
        public readonly int $fetchedAt,
        public readonly ?string $childParentId = null,
    ) {
    }

    /**
     * @param iterable<ResourceEntry> $entries
     */
    public static function of(
        FolderRef $folder,
        iterable $entries,
        bool $complete,
        int $fetchedAt,
        ?string $childParentId = null,
    ): self {
        $files = [];
        $folders = [];

        foreach ($entries as $entry) {
            if ($entry->isFolder) {
                $folders[self::key($entry->name)] = $entry;
            } else {
                $files[self::key($entry->name)] = $entry;
            }
        }

        return new self($folder, $files, $folders, $complete, $fetchedAt, $childParentId);
    }

    public static function empty(FolderRef $folder, int $fetchedAt): self
    {
        return new self($folder, [], [], true, $fetchedAt);
    }

    public function file(string $name): ?ResourceEntry
    {
        return $this->files[self::key($name)] ?? null;
    }

    public function folder(string $name): ?ResourceEntry
    {
        return $this->folders[self::key($name)] ?? null;
    }

    public function entry(string $name): ?ResourceEntry
    {
        return $this->file($name) ?? $this->folder($name);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return list<ResourceEntry>
     */
    public function entries(): array
    {
        return array_values(array_merge($this->folders, $this->files));
    }

    public function with(ResourceEntry $entry): self
    {
        $files = $this->files;
        $folders = $this->folders;

        if ($entry->isFolder) {
            $folders[self::key($entry->name)] = $entry;
        } else {
            $files[self::key($entry->name)] = $entry;
        }

        return new self($this->folder, $files, $folders, $this->complete, $this->fetchedAt, $this->childParentId);
    }

    public function without(string $name, bool $isFolder): self
    {
        $files = $this->files;
        $folders = $this->folders;

        if ($isFolder) {
            unset($folders[self::key($name)]);
        } else {
            unset($files[self::key($name)]);
        }

        return new self($this->folder, $files, $folders, $this->complete, $this->fetchedAt, $this->childParentId);
    }

    private static function key(string $name): string
    {
        return NameKey::fold($name);
    }
}
