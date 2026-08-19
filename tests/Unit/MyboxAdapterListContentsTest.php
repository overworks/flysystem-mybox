<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use Generator;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
final class MyboxAdapterListContentsTest extends TestCase
{
    public function testListingIsALazyGenerator(): void
    {
        $listing = $this->adapter()->listContents('', false);

        self::assertInstanceOf(Generator::class, $listing);
        self::assertCount(0, $this->requests(), 'Nothing is fetched until the listing is iterated.');
    }

    public function testAShallowListingCarriesSizeTimeAndMimeType(): void
    {
        $this->willList([
            ['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1'],
            ['name' => 'note.txt', 'id' => 'file-1', 'size' => 12],
        ]);

        $items = iterator_to_array($this->adapter()->listContents('', false));

        self::assertCount(2, $items);
        self::assertInstanceOf(DirectoryAttributes::class, $items[0]);
        self::assertSame('docs', $items[0]->path());

        $file = $items[1];
        self::assertInstanceOf(FileAttributes::class, $file);
        self::assertSame('note.txt', $file->path());
        self::assertSame(12, $file->fileSize());
        self::assertSame('text/plain', $file->mimeType());
        self::assertSame('file-1', $file->extraMetadata()['resource_id']);
    }

    public function testADeepListingKeepsEveryNestedEntry(): void
    {
        $this->willList([['name' => 'a', 'type' => 'folder', 'id' => 'folder-a']]);
        $this->willList([['name' => 'b', 'type' => 'folder', 'id' => 'folder-b']]);
        $this->willList([['name' => 'c.txt', 'id' => 'file-c', 'size' => 1]]);

        // iterator_to_array preserves keys by default, so a nested generator that
        // restarted its numbering would silently drop entries.
        $items = iterator_to_array($this->adapter()->listContents('', true));

        self::assertSame(
            ['a', 'a/b', 'a/b/c.txt'],
            array_map(static fn (StorageAttributes $item): string => $item->path(), array_values($items)),
        );
    }

    public function testListingAMissingDirectoryYieldsNothingAndDoesNotThrow(): void
    {
        $this->willList([]);

        self::assertSame([], iterator_to_array($this->adapter()->listContents('missing', false)));
    }

    public function testListingFollowsEveryCursorPage(): void
    {
        $this->willList([['name' => 'a.txt', 'id' => 'file-a']], nextCursor: 'page-2');
        $this->willList([['name' => 'b.txt', 'id' => 'file-b']]);

        $items = iterator_to_array($this->adapter()->listContents('', false));

        self::assertCount(2, $items);
        $this->assertRequest(1, 'GET', '/v1/drive/resources?count=1000&cursor=page-2');
    }

    public function testAListingWarmsTheCacheForSubsequentMetadataCalls(): void
    {
        $this->willList([['name' => 'a.txt', 'id' => 'file-a', 'size' => 7]]);

        $adapter = $this->adapter();
        iterator_to_array($adapter->listContents('', false));

        self::assertSame(7, $adapter->fileSize('a.txt')->fileSize());
        self::assertCount(1, $this->requests());
    }
}
