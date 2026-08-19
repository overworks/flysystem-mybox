<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Cache;

use Closure;

/**
 * The default cache: per-instance, per-process, bounded by a TTL and an LRU cap.
 *
 * The TTL is what bounds the one failure this design cannot detect — another
 * client renaming a folder, which leaves our id valid but pointing somewhere
 * else. A short default is nearly free, since a web request or queue job lives
 * well inside it, while a long-running worker sees at most that much staleness.
 */
final class MemoryDirectoryCache implements DirectoryCache
{
    public const DEFAULT_TTL_SECONDS = 10;
    public const DEFAULT_MAX_DIRECTORIES = 128;

    /** Insertion order doubles as LRU order; the root is pinned. */
    private const ROOT = '';

    /** @var array<string, DirectorySnapshot> */
    private array $snapshots = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int $ttlSeconds Zero disables reuse without disabling writes, which is
     *                        rarely what you want — prefer {@see NullDirectoryCache}.
     * @param (Closure(): int)|null $clock Injectable so TTL expiry is testable.
     */
    public function __construct(
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $maxDirectories = self::DEFAULT_MAX_DIRECTORIES,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $directory): ?DirectorySnapshot
    {
        $snapshot = $this->snapshots[$directory] ?? null;

        if ($snapshot === null) {
            return null;
        }

        if (($this->clock)() - $snapshot->fetchedAt >= $this->ttlSeconds) {
            unset($this->snapshots[$directory]);

            return null;
        }

        // Re-inserting moves the key to the end, which is what makes eviction LRU.
        unset($this->snapshots[$directory]);
        $this->snapshots[$directory] = $snapshot;

        return $snapshot;
    }

    public function put(string $directory, DirectorySnapshot $snapshot): void
    {
        unset($this->snapshots[$directory]);
        $this->snapshots[$directory] = $snapshot;

        $this->evict();
    }

    public function forget(string $directory): void
    {
        unset($this->snapshots[$directory]);
    }

    public function forgetSubtree(string $directory): void
    {
        if ($directory === self::ROOT) {
            $this->flush();

            return;
        }

        $prefix = $directory . '/';

        foreach (array_keys($this->snapshots) as $key) {
            // A directory literally named "0" arrives here as an int array key.
            $key = (string) $key;

            if ($key === $directory || str_starts_with($key, $prefix)) {
                unset($this->snapshots[$key]);
            }
        }
    }

    public function flush(): void
    {
        $this->snapshots = [];
    }

    private function evict(): void
    {
        while (count($this->snapshots) > $this->maxDirectories) {
            $oldest = array_key_first($this->snapshots);

            if ($oldest === null) {
                return;
            }

            // Every cold walk starts at the root, so it is never worth evicting.
            if ($oldest === self::ROOT && count($this->snapshots) > 1) {
                $root = $this->snapshots[self::ROOT];
                unset($this->snapshots[self::ROOT]);
                $this->snapshots[self::ROOT] = $root;

                $oldest = array_key_first($this->snapshots);

                if ($oldest === null || $oldest === self::ROOT) {
                    return;
                }
            }

            unset($this->snapshots[$oldest]);
        }
    }
}
