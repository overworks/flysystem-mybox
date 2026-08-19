<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\UnableToDeleteFile;
use Minhyung\Flysystem\Mybox\Enum\DeletionMode;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
final class MyboxAdapterDeleteTest extends TestCase
{
    public function testDeletingTrashesTheFileByDefault(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willRespond([], 204);

        $this->adapter()->delete('note.txt');

        self::assertCount(2, $this->requests());
        $this->assertRequest(1, 'DELETE', '/v1/drive/resources/file-1');
    }

    public function testPurgeModeAlsoEmptiesItFromTheTrash(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willRespond([], 204);
        $this->willRespond([], 204);

        $this->adapter(options: new MyboxAdapterOptions(deletionMode: DeletionMode::Purge))->delete('note.txt');

        self::assertCount(3, $this->requests());
        $this->assertRequest(1, 'DELETE', '/v1/drive/resources/file-1');
        $this->assertRequest(2, 'DELETE', '/v1/drive/trash/file-1');
    }

    public function testDeletingAMissingFileMakesNoRequestAndDoesNotThrow(): void
    {
        $this->willList([]);

        $this->adapter()->delete('missing.txt');

        self::assertCount(1, $this->requests(), 'Only the listing that proved it was absent.');
    }

    public function testDeletingADirectoryThroughDeleteIsRefused(): void
    {
        $this->willList([['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1']]);

        $this->expectException(UnableToDeleteFile::class);

        $this->adapter()->delete('docs');
    }

    public function testDeletingAMissingDirectoryDoesNotThrow(): void
    {
        $this->willList([]);

        $this->adapter()->deleteDirectory('missing');

        self::assertCount(1, $this->requests());
    }

    public function testDeletingADirectoryDeletesItRecursivelyServerSide(): void
    {
        $this->willList([['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1']]);
        $this->willRespond([], 204);

        $this->adapter()->deleteDirectory('docs');

        self::assertCount(2, $this->requests());
        $this->assertRequest(1, 'DELETE', '/v1/drive/resources/folder-1');
    }

    public function testDeletingTheDriveRootEmptiesItChildByChild(): void
    {
        // The drive root has no resource id, so there is nothing to hand to DELETE.
        $this->willList([
            ['name' => 'a.txt', 'id' => 'file-1'],
            ['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1'],
        ]);
        $this->willRespond([], 204);
        $this->willRespond([], 204);

        $this->adapter()->deleteDirectory('');

        self::assertCount(3, $this->requests());
        $this->assertRequest(1, 'DELETE', '/v1/drive/resources/folder-1');
        $this->assertRequest(2, 'DELETE', '/v1/drive/resources/file-1');
    }

    public function testTheDeletedFileIsGoneWithoutAnotherListing(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willRespond([], 204);

        $adapter = $this->adapter();
        $adapter->delete('note.txt');

        self::assertFalse($adapter->fileExists('note.txt'));
        self::assertCount(2, $this->requests());
    }
}
