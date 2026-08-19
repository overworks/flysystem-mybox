<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Cache;

/**
 * Keeps nothing, so every path resolution goes back to MYBOX.
 *
 * Correct under concurrent writers at a steep price: a single
 * `fileExists('a/b/c.txt')` becomes three listing round-trips against a quota of
 * 60–240 calls a minute.
 */
final class NullDirectoryCache implements DirectoryCache
{
    public function get(string $directory): ?DirectorySnapshot
    {
        return null;
    }

    public function put(string $directory, DirectorySnapshot $snapshot): void
    {
    }

    public function forget(string $directory): void
    {
    }

    public function forgetSubtree(string $directory): void
    {
    }

    public function flush(): void
    {
    }
}
