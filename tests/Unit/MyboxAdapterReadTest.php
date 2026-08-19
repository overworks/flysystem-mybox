<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\UnableToReadFile;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Stream\PsrStreamResource;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
#[CoversClass(PsrStreamResource::class)]
final class MyboxAdapterReadTest extends TestCase
{
    public function testReadingMintsAFreshDownloadTicketAndFetchesIt(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1', 'size' => 5]]);
        $this->willTicket();
        $this->willRespond('hello');

        self::assertSame('hello', $this->adapter()->read('note.txt'));

        $this->assertRequest(1, 'GET', '/v1/drive/files/file-1/download');
        self::assertSame('storage.example.test', $this->requests()[2]->getUri()->getHost());
    }

    public function testEveryReadMintsItsOwnTicketBecauseTheUrlIsSingleUse(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1', 'size' => 5]]);
        $this->willTicket();
        $this->willRespond('hello');
        $this->willTicket();
        $this->willRespond('hello');

        $adapter = $this->adapter();
        $adapter->read('note.txt');
        $adapter->read('note.txt');

        $this->assertRequest(1, 'GET', '/v1/drive/files/file-1/download');
        $this->assertRequest(3, 'GET', '/v1/drive/files/file-1/download');
    }

    public function testReadStreamHandsBackARealResource(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1', 'size' => 11]]);
        $this->willTicket();
        $this->willRespond('hello world');

        $stream = $this->adapter()->readStream('note.txt');

        self::assertIsResource($stream);
        self::assertSame('hello world', stream_get_contents($stream));
        fclose($stream);
    }

    public function testReadingAMissingFileFails(): void
    {
        $this->willList([]);

        $this->expectException(UnableToReadFile::class);

        $this->adapter()->read('missing.txt');
    }

    public function testReadingADirectoryFails(): void
    {
        $this->willList([['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1']]);

        $this->expectException(UnableToReadFile::class);

        $this->adapter()->read('docs');
    }

    private function willTicket(): void
    {
        $this->willRespond([
            'downloadUrl' => 'https://storage.example.test/v1/storage/download?atoken=t',
            'expiresIn' => 600,
        ]);
    }
}
