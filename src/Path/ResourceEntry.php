<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Path;

use Minhyung\Mybox\Model\ResourceItem;

/**
 * One row of a directory listing, reduced to what Flysystem asks about.
 *
 * A listing already answers exists / size / last-modified / is-a-directory for
 * every child at once, so keeping this instead of a bare resource id is what
 * lets four consecutive metadata calls cost a single request. The SDK model is
 * projected rather than stored because a post-write entry has to be synthesised
 * from an upload result, and {@see ResourceItem} takes fourteen arguments.
 */
final class ResourceEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isFolder,
        public readonly int $fileSize,
        public readonly int $lastModified,
    ) {
    }

    public static function fromResourceItem(ResourceItem $item): self
    {
        return new self(
            id: $item->resourceId,
            name: $item->name,
            isFolder: $item->isFolder(),
            fileSize: $item->size,
            lastModified: $item->modifiedAt->getTimestamp(),
        );
    }

    public static function file(string $id, string $name, int $fileSize, int $lastModified): self
    {
        return new self($id, $name, false, $fileSize, $lastModified);
    }

    public static function folder(string $id, string $name, int $lastModified): self
    {
        return new self($id, $name, true, 0, $lastModified);
    }

    public function renamedTo(string $name): self
    {
        return new self($this->id, $name, $this->isFolder, $this->fileSize, $this->lastModified);
    }
}
