<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox;

use League\Flysystem\Visibility;
use Minhyung\Flysystem\Mybox\Cache\MemoryDirectoryCache;
use Minhyung\Flysystem\Mybox\Enum\DeletionMode;
use Minhyung\Flysystem\Mybox\Enum\UnknownSizeStrategy;
use Minhyung\Flysystem\Mybox\Path\ResourceLocator;
use Minhyung\Mybox\Request\ListOptions;

/**
 * Everything about {@see MyboxAdapter} that is a policy choice rather than a
 * consequence of the MYBOX API.
 */
final class MyboxAdapterOptions
{
    /**
     * @param string $visibility What `visibility()` reports, since MYBOX has no per-file
     *                           visibility model of any kind.
     * @param bool $failOnSetVisibility Whether `setVisibility()` throws (honest) or is a silent
     *                                  no-op (convenient behind Laravel's Storage facade).
     * @param int $bufferThresholdBytes Below this, a buffered upload stays in memory; above it,
     *                                  php://temp spills to disk and memory stays bounded.
     * @param bool $strictTemporaryUrlExpiry Whether to refuse a temporary URL whose requested
     *                                       lifetime is longer than the ten minutes MYBOX grants.
     * @param int $lockedRetries The SDK retries 429/502/503 but not 423, and an interrupted
     *                           upload locks its file for a second or two.
     */
    public function __construct(
        public readonly DeletionMode $deletionMode = DeletionMode::Trash,
        public readonly string $visibility = Visibility::PRIVATE,
        public readonly bool $failOnSetVisibility = true,
        public readonly UnknownSizeStrategy $unknownSize = UnknownSizeStrategy::Buffer,
        public readonly int $bufferThresholdBytes = 2 * 1024 * 1024,
        public readonly int $listPageSize = ListOptions::MAX_COUNT,
        public readonly int $cacheTtlSeconds = MemoryDirectoryCache::DEFAULT_TTL_SECONDS,
        public readonly int $cacheMaxDirectories = MemoryDirectoryCache::DEFAULT_MAX_DIRECTORIES,
        public readonly int $cacheMaxEntriesPerDirectory = ResourceLocator::DEFAULT_MAX_ENTRIES_PER_DIRECTORY,
        public readonly bool $strictTemporaryUrlExpiry = true,
        public readonly int $lockedRetries = 2,
        public readonly float $lockedRetryDelaySeconds = 1.5,
    ) {
    }
}
