<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit\Cache;

use Minhyung\Flysystem\Mybox\Cache\DirectorySnapshot;
use Minhyung\Flysystem\Mybox\Cache\MemoryDirectoryCache;
use Minhyung\Flysystem\Mybox\Path\FolderRef;
use Minhyung\Flysystem\Mybox\Path\ResourceEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoryDirectoryCache::class)]
#[CoversClass(DirectorySnapshot::class)]
final class MemoryDirectoryCacheTest extends TestCase
{
    private int $now = 1_000;

    public function testASnapshotIsForgottenOnceItsTtlHasPassed(): void
    {
        $cache = $this->cache(ttlSeconds: 10);
        $cache->put('a', $this->snapshot());

        $this->now += 9;
        self::assertNotNull($cache->get('a'));

        $this->now += 1;
        self::assertNull($cache->get('a'), 'The TTL is what bounds a rename by another client.');
    }

    public function testTheOldestDirectoryIsEvictedFirstButTheRootIsPinned(): void
    {
        $cache = $this->cache(maxDirectories: 2);

        $cache->put('', $this->snapshot());
        $cache->put('a', $this->snapshot());
        $cache->put('b', $this->snapshot());

        self::assertNotNull($cache->get(''), 'Every cold walk starts at the root.');
        self::assertNull($cache->get('a'));
        self::assertNotNull($cache->get('b'));
    }

    public function testReadingADirectoryMakesItTheMostRecentlyUsed(): void
    {
        $cache = $this->cache(maxDirectories: 2);

        $cache->put('a', $this->snapshot());
        $cache->put('b', $this->snapshot());
        $cache->get('a');
        $cache->put('c', $this->snapshot());

        self::assertNotNull($cache->get('a'));
        self::assertNull($cache->get('b'));
    }

    public function testForgettingASubtreeDropsEveryDescendant(): void
    {
        $cache = $this->cache();

        foreach (['', 'a', 'a/b', 'a/b/c', 'ab'] as $directory) {
            $cache->put($directory, $this->snapshot());
        }

        $cache->forgetSubtree('a');

        self::assertNull($cache->get('a'));
        self::assertNull($cache->get('a/b'));
        self::assertNull($cache->get('a/b/c'));
        self::assertNotNull($cache->get('ab'), 'A sibling with a shared prefix is not a descendant.');
        self::assertNotNull($cache->get(''));
    }

    public function testForgettingTheRootSubtreeDropsEverything(): void
    {
        $cache = $this->cache();
        $cache->put('', $this->snapshot());
        $cache->put('a', $this->snapshot());

        $cache->forgetSubtree('');

        self::assertNull($cache->get(''));
        self::assertNull($cache->get('a'));
    }

    public function testADirectoryNamedZeroSurvivesArrayKeyCoercion(): void
    {
        // PHP turns the array key '0' into int 0, which used to blow up the prefix scan.
        $cache = $this->cache();
        $cache->put('0', $this->snapshot());
        $cache->put('0/inner', $this->snapshot());

        $cache->forgetSubtree('0');

        self::assertNull($cache->get('0'));
        self::assertNull($cache->get('0/inner'));
    }

    public function testAPatchedSnapshotReplacesTheStoredOne(): void
    {
        $cache = $this->cache();
        $cache->put('a', $this->snapshot());

        $stored = $cache->get('a');
        self::assertNotNull($stored);
        $cache->put('a', $stored->with(ResourceEntry::file('file-2', 'new.txt', 4, $this->now)));

        $patched = $cache->get('a');
        self::assertNotNull($patched);
        self::assertNotNull($patched->file('new.txt'));
        self::assertNotNull($patched->file('note.txt'));
    }

    private function cache(int $ttlSeconds = 300, int $maxDirectories = 128): MemoryDirectoryCache
    {
        return new MemoryDirectoryCache($ttlSeconds, $maxDirectories, fn (): int => $this->now);
    }

    private function snapshot(): DirectorySnapshot
    {
        return DirectorySnapshot::of(
            FolderRef::of('folder-1'),
            [ResourceEntry::file('file-1', 'note.txt', 3, $this->now)],
            true,
            $this->now,
        );
    }
}
