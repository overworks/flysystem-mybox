<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\Config;
use League\Flysystem\UnableToCreateDirectory;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
final class MyboxAdapterDirectoryTest extends TestCase
{
    public function testCreatingADirectoryCreatesEveryMissingSegment(): void
    {
        $this->willList([]);
        $this->willRespond(['resourceId' => 'folder-a', 'name' => 'a']);
        $this->willRespond(['resourceId' => 'folder-b', 'name' => 'b']);

        $this->adapter()->createDirectory('a/b', new Config());

        self::assertCount(3, $this->requests());
        $this->assertRequest(1, 'POST', '/v1/drive/folders');
        self::assertSame(['folderName' => 'a'], json_decode($this->capturing->bodies[1], true));
        self::assertSame(['folderName' => 'b', 'parentId' => 'folder-a'], json_decode($this->capturing->bodies[2], true));
    }

    public function testCreatingTheSameDirectoryTwiceIsFreeTheSecondTime(): void
    {
        $this->willList([]);
        $this->willRespond(['resourceId' => 'folder-a', 'name' => 'a']);

        $adapter = $this->adapter();
        $adapter->createDirectory('a', new Config());
        $adapter->createDirectory('a', new Config());

        self::assertCount(2, $this->requests(), 'The seeded snapshot answers the second call.');
    }

    public function testANewDirectoryIsKnownToBeEmpty(): void
    {
        $this->willList([]);
        $this->willRespond(['resourceId' => 'folder-a', 'name' => 'a']);

        $adapter = $this->adapter();
        $adapter->createDirectory('a', new Config());

        self::assertSame([], iterator_to_array($adapter->listContents('a', false)));
        self::assertTrue($adapter->directoryExists('a'));
        self::assertCount(2, $this->requests(), 'A folder MYBOX just made is provably empty.');
    }

    public function testLosingTheRaceToCreateAFolderAdoptsTheWinner(): void
    {
        // createFolder has no isOverwrite, so a concurrent creator makes it 409.
        $this->willList([]);
        $this->willRespond([
            'code' => 'PLAT-409',
            'message' => 'ALREADY_EXISTS',
            'requestId' => 'r',
            'timestamp' => '2026-08-19T16:30:00+09:00',
        ], 409);
        $this->willList([['name' => 'a', 'type' => 'folder', 'id' => 'folder-a']]);

        $this->adapter()->createDirectory('a', new Config());

        self::assertCount(3, $this->requests());
        $this->assertRequest(2, 'GET', '/v1/drive/resources?count=1000');
    }

    public function testAConflictThatIsNotARaceStillFails(): void
    {
        $this->willList([]);
        $this->willRespond([
            'code' => 'PLAT-409',
            'message' => 'ALREADY_EXISTS',
            'requestId' => 'r',
            'timestamp' => '2026-08-19T16:30:00+09:00',
        ], 409);
        $this->willList([]);

        $this->expectException(UnableToCreateDirectory::class);

        $this->adapter()->createDirectory('a', new Config());
    }
}
