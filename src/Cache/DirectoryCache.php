<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Cache;

/**
 * Holds directory listings between adapter calls.
 *
 * Keys are prefixed locations — the empty string is the drive root — so two
 * adapters with different root directories can share one instance without
 * colliding. Implementations never cache a negative result: absence is answered
 * by a complete snapshot, so a miss is always re-fetched.
 */
interface DirectoryCache
{
    public function get(string $directory): ?DirectorySnapshot;

    public function put(string $directory, DirectorySnapshot $snapshot): void;

    public function forget(string $directory): void;

    /**
     * Drops the directory and everything beneath it. Needed whenever a folder is
     * moved, renamed or deleted, because the ids underneath are still valid but
     * no longer reachable at those paths.
     */
    public function forgetSubtree(string $directory): void;

    public function flush(): void;
}
