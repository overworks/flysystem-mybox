<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * MYBOX splits what Flysystem calls a move: `move` changes only the parent,
 * `rename` changes only the name and has no overwrite flag. These assert the
 * exact call sequence each shape produces, because getting it wrong is silent.
 */
#[CoversClass(MyboxAdapter::class)]
final class MyboxAdapterMoveCopyTest extends TestCase
{
    public function testRenamingWithinADirectoryIssuesOnlyARename(): void
    {
        $this->willList([['name' => 'old.txt', 'id' => 'file-1']]);
        $this->willRespond(['name' => 'new.txt']);

        $this->adapter()->move('old.txt', 'new.txt', new Config());

        self::assertCount(2, $this->requests());
        $this->assertRequest(1, 'POST', '/v1/drive/resources/file-1/rename');
        self::assertSame(['name' => 'new.txt'], json_decode($this->capturing->bodies[1], true));
    }

    public function testMovingToAnotherDirectoryUnderTheSameNameIssuesOnlyAMove(): void
    {
        $this->willList([
            ['name' => 'note.txt', 'id' => 'file-1'],
            ['name' => 'archive', 'type' => 'folder', 'id' => 'folder-archive'],
        ]);
        $this->willList([]);                         // the destination directory
        $this->willRespond([], 204);                 // move

        $this->adapter()->move('note.txt', 'archive/note.txt', new Config());

        self::assertCount(3, $this->requests());
        $this->assertRequest(2, 'POST', '/v1/drive/resources/file-1/move');
        self::assertSame(
            ['parentId' => 'folder-archive', 'isOverwrite' => true],
            json_decode($this->capturing->bodies[2], true),
        );
    }

    public function testChangingBothParentAndNameMovesThenRenames(): void
    {
        $this->willList([
            ['name' => 'note.txt', 'id' => 'file-1'],
            ['name' => 'archive', 'type' => 'folder', 'id' => 'folder-archive'],
        ]);
        $this->willList([]);
        $this->willRespond([], 204);                 // move
        $this->willRespond(['name' => 'kept.txt']);  // rename

        $this->adapter()->move('note.txt', 'archive/kept.txt', new Config());

        self::assertCount(4, $this->requests());
        $this->assertRequest(2, 'POST', '/v1/drive/resources/file-1/move');
        $this->assertRequest(3, 'POST', '/v1/drive/resources/file-1/rename');
    }

    public function testAnOccupiedDestinationIsClearedBeforeTheRename(): void
    {
        $this->willList([
            ['name' => 'old.txt', 'id' => 'file-1'],
            ['name' => 'new.txt', 'id' => 'file-2'],
        ]);
        $this->willRespond([], 204);                 // delete of the occupant
        $this->willRespond(['name' => 'new.txt']);   // rename

        $this->adapter()->move('old.txt', 'new.txt', new Config());

        self::assertCount(3, $this->requests());
        $this->assertRequest(1, 'DELETE', '/v1/drive/resources/file-2');
        $this->assertRequest(2, 'POST', '/v1/drive/resources/file-1/rename');
    }

    public function testMovingToTheSamePathDoesNothing(): void
    {
        $this->adapter()->move('note.txt', 'note.txt', new Config());

        self::assertCount(0, $this->requests());
    }

    public function testMovingAMissingFileFails(): void
    {
        $this->willList([]);

        $this->expectException(UnableToMoveFile::class);

        $this->adapter()->move('missing.txt', 'other.txt', new Config());
    }

    public function testCopyingRenamesAtTheDestinationInOneCall(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1', 'size' => 4]]);
        $this->willRespond(['resourceId' => 'folder-a', 'name' => 'a']);
        $this->willRespond(['resourceId' => 'file-2', 'name' => 'copy.txt']);

        $this->adapter()->copy('note.txt', 'a/copy.txt', new Config());

        $this->assertRequest(2, 'POST', '/v1/drive/resources/file-1/copy');
        self::assertSame(
            ['parentId' => 'folder-a', 'name' => 'copy.txt', 'isOverwrite' => true],
            json_decode($this->capturing->bodies[2], true),
        );
    }

    public function testCopyingToTheDriveRootOmitsTheParent(): void
    {
        $this->willList([
            ['name' => 'a', 'type' => 'folder', 'id' => 'folder-a'],
        ]);
        $this->willList([['name' => 'note.txt', 'id' => 'file-1', 'size' => 4]]);
        $this->willRespond(['resourceId' => 'file-2', 'name' => 'note.txt']);

        $this->adapter()->copy('a/note.txt', 'note.txt', new Config());

        self::assertSame(
            ['name' => 'note.txt', 'isOverwrite' => true],
            json_decode($this->capturing->bodies[2], true),
            'CopyOptions omits a null parentId, which is how a copy reaches the root.',
        );
    }

    public function testCopyingAMissingFileFails(): void
    {
        $this->willList([]);

        $this->expectException(UnableToCopyFile::class);

        $this->adapter()->copy('missing.txt', 'other.txt', new Config());
    }
}
