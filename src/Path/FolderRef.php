<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Path;

/**
 * A folder MYBOX can address.
 *
 * This exists to keep two different nulls apart. A *null FolderRef* means "no
 * such folder"; a FolderRef whose {@see self::$id} is null means "the drive
 * root", which genuinely has no resource id and is listed through
 * {@see \Minhyung\Mybox\Api\DriveApi::listRoot()} instead.
 */
final class FolderRef
{
    private function __construct(public readonly ?string $id)
    {
    }

    public static function root(): self
    {
        return new self(null);
    }

    public static function of(string $id): self
    {
        return new self($id);
    }

    public function isRoot(): bool
    {
        return $this->id === null;
    }
}
