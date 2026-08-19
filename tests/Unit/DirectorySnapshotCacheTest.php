<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use Minhyung\Flysystem\Mybox\Cache\DirectorySnapshot;
use Minhyung\Flysystem\Mybox\Cache\MemoryDirectoryCache;
use Minhyung\Flysystem\Mybox\Cache\NullDirectoryCache;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Path\PathTranslator;
use Minhyung\Flysystem\Mybox\Path\ResourceLocator;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The reason this adapter keeps directory listings rather than resource ids.
 */
#[CoversClass(MyboxAdapter::class)]
#[CoversClass(ResourceLocator::class)]
#[CoversClass(DirectorySnapshot::class)]
#[CoversClass(MemoryDirectoryCache::class)]
#[CoversClass(NullDirectoryCache::class)]
#[CoversClass(PathTranslator::class)]
final class DirectorySnapshotCacheTest extends TestCase
{
    public function testFourMetadataCallsOnOnePathCostOneListing(): void
    {
        $this->willList([
            ['name' => '보고서.pdf', 'id' => 'file-1', 'size' => 2048, 'modifiedAt' => '2026-08-11T09:00:00+09:00'],
        ]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('보고서.pdf'));
        self::assertSame(2048, $adapter->fileSize('보고서.pdf')->fileSize());
        self::assertSame(1786406400, $adapter->lastModified('보고서.pdf')->lastModified());
        self::assertSame('application/pdf', $adapter->mimeType('보고서.pdf')->mimeType());

        self::assertCount(1, $this->requests());
        $this->assertRequest(0, 'GET', '/v1/drive/resources?count=1000');
    }

    public function testAMissingSiblingIsAnsweredFromTheSameListing(): void
    {
        $this->willList([['name' => '보고서.pdf', 'id' => 'file-1']]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('보고서.pdf'));
        self::assertFalse($adapter->fileExists('없는파일.txt'));
        self::assertFalse($adapter->fileExists('또없음.txt'));

        self::assertCount(1, $this->requests(), 'A complete listing is authoritative about absence.');
    }

    public function testWalkingToADeepPathWarmsEveryAncestor(): void
    {
        $this->willList([['name' => '문서', 'type' => 'folder', 'id' => 'folder-docs']]);
        $this->willList([['name' => '2026', 'type' => 'folder', 'id' => 'folder-2026']]);
        $this->willList([
            ['name' => '1월.pdf', 'id' => 'file-jan'],
            ['name' => '2월.pdf', 'id' => 'file-feb'],
        ]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('문서/2026/1월.pdf'));
        self::assertCount(3, $this->requests());

        self::assertTrue($adapter->fileExists('문서/2026/2월.pdf'));
        self::assertTrue($adapter->directoryExists('문서/2026'));
        self::assertTrue($adapter->directoryExists('문서'));

        self::assertCount(3, $this->requests(), 'Every ancestor was warmed by the first walk.');
        $this->assertRequest(1, 'GET', '/v1/drive/folders/folder-docs/resources?count=1000');
        $this->assertRequest(2, 'GET', '/v1/drive/folders/folder-2026/resources?count=1000');
    }

    public function testResolutionNeverTouchesTheRateLimitedSearchEndpoint(): void
    {
        $this->willList([['name' => '문서', 'type' => 'folder', 'id' => 'folder-docs']]);
        $this->willList([]);

        self::assertFalse($this->adapter()->fileExists('문서/없음.txt'));

        $this->assertNoSearchRequests();
    }

    public function testDisablingTheCacheReListsEveryTime(): void
    {
        $this->willList([['name' => '보고서.pdf', 'id' => 'file-1']]);
        $this->willList([['name' => '보고서.pdf', 'id' => 'file-1']]);

        $adapter = $this->adapter(cache: new NullDirectoryCache());

        self::assertTrue($adapter->fileExists('보고서.pdf'));
        self::assertTrue($adapter->fileExists('보고서.pdf'));

        self::assertCount(2, $this->requests());
    }

    public function testClearCacheForcesAFreshListing(): void
    {
        $this->willList([['name' => '보고서.pdf', 'id' => 'file-1']]);
        $this->willList([]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('보고서.pdf'));
        $adapter->clearCache();
        self::assertFalse($adapter->fileExists('보고서.pdf'));

        self::assertCount(2, $this->requests());
    }

    public function testAFolderAndAFileOfTheSameNameDoNotShadowEachOther(): void
    {
        $this->willList([
            ['name' => 'report', 'type' => 'folder', 'id' => 'folder-1'],
            ['name' => 'report', 'type' => 'file', 'id' => 'file-1'],
        ]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('report'));
        self::assertTrue($adapter->directoryExists('report'));
        self::assertCount(1, $this->requests());
    }

    public function testALookupFindsAFileWhoseStoredNameDiffersOnlyInCase(): void
    {
        // MYBOX matches names case-insensitively and keeps the spelling it already
        // had, so writing "case.txt" over an existing "Case.txt" leaves the listing
        // reporting "Case.txt". An unfolded lookup would then miss its own write.
        $this->willList([['name' => 'Case.txt', 'id' => 'file-1', 'size' => 3]]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('case.txt'));
        self::assertSame(3, $adapter->fileSize('CASE.TXT')->fileSize());
        self::assertCount(1, $this->requests());
    }

    public function testADirectoryReachedThroughAnotherSpellingSharesOneCacheEntry(): void
    {
        $this->willList([['name' => 'Docs', 'type' => 'folder', 'id' => 'folder-docs']]);
        $this->willList([['name' => 'a.txt', 'id' => 'file-a']]);

        $adapter = $this->adapter();

        self::assertTrue($adapter->fileExists('Docs/a.txt'));
        self::assertTrue($adapter->fileExists('docs/a.txt'));

        self::assertCount(2, $this->requests(), 'Both spellings name the same directory.');
    }

    public function testAHangulNameDecomposedByMacosStillMatches(): void
    {
        if (!class_exists(\Normalizer::class)) {
            self::markTestSkipped('ext-intl is needed to fold NFD to NFC.');
        }

        $this->willList([['name' => 'íìë¡.pdf', 'id' => 'file-1', 'size' => 9]]);

        $decomposed = \Normalizer::normalize('íìë¡.pdf', \Normalizer::FORM_D);
        self::assertIsString($decomposed);

        self::assertTrue($this->adapter()->fileExists($decomposed));
    }

    public function testTheRootPrefixIsResolvedOnceAndStrippedFromResults(): void
    {
        $this->willList([['name' => 'app', 'type' => 'folder', 'id' => 'folder-app']]);
        $this->willList([['name' => 'a.txt', 'id' => 'file-a', 'size' => 3]]);

        $adapter = $this->adapter(root: 'app');

        $listing = iterator_to_array($adapter->listContents('', false));

        self::assertCount(1, $listing);
        self::assertSame('a.txt', $listing[0]->path());
        self::assertTrue($adapter->fileExists('a.txt'));
        self::assertCount(2, $this->requests());
    }
}
