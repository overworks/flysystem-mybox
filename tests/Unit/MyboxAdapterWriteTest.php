<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\Config;
use League\Flysystem\UnableToWriteFile;
use Minhyung\Flysystem\Mybox\Enum\UnknownSizeStrategy;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use Minhyung\Flysystem\Mybox\Upload\ResolvedUpload;
use Minhyung\Flysystem\Mybox\Upload\UploadSize;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
#[CoversClass(UploadSize::class)]
#[CoversClass(ResolvedUpload::class)]
final class MyboxAdapterWriteTest extends TestCase
{
    public function testWritingAlwaysAsksMyboxToOverwrite(): void
    {
        $this->willAcceptUpload('file-1', 'note.txt', 5);

        $this->adapter()->write('note.txt', 'hello', new Config());

        // A write into the drive root resolves no path at all, so the reservation is
        // the very first request.
        $this->assertRequest(0, 'POST', '/v1/drive/files');
        $body = json_decode($this->capturing->bodies[0], true);

        self::assertIsArray($body);
        self::assertSame('note.txt', $body['fileName']);
        self::assertSame(5, $body['fileSize']);
        self::assertTrue($body['isOverwrite'], 'Flysystem requires a silent overwrite; MYBOX 409s without the flag.');
        self::assertArrayNotHasKey('parentId', $body, 'A root-level write must omit parentId, not send an empty one.');
    }

    public function testWritingCreatesEveryMissingIntermediateDirectory(): void
    {
        $this->willList([]);                                        // root
        $this->willRespond(['resourceId' => 'folder-a', 'name' => 'a']);
        $this->willRespond(['resourceId' => 'folder-b', 'name' => 'b']);
        $this->willAcceptUpload('file-1', 'c.txt', 3);

        $this->adapter()->write('a/b/c.txt', 'abc', new Config());

        $this->assertRequest(1, 'POST', '/v1/drive/folders');
        $this->assertRequest(2, 'POST', '/v1/drive/folders');
        self::assertSame(['folderName' => 'b', 'parentId' => 'folder-a'], json_decode($this->capturing->bodies[2], true));
    }

    public function testTheWrittenFileIsVisibleWithoutAnotherListing(): void
    {
        $this->willList([]);
        $this->willAcceptUpload('file-1', 'note.txt', 5);

        $adapter = $this->adapter();

        self::assertFalse($adapter->fileExists('note.txt'));   // warms the root listing
        $adapter->write('note.txt', 'hello', new Config());

        self::assertTrue($adapter->fileExists('note.txt'));
        self::assertSame(5, $adapter->fileSize('note.txt')->fileSize());
        self::assertCount(3, $this->requests(), 'The upload result patches the cached listing.');
    }

    public function testWriteStreamTakesTheExactSizeFromASeekableFile(): void
    {
        $this->willAcceptUpload('file-1', 'note.txt', 11);

        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'hello world');
        rewind($stream);

        $this->adapter()->writeStream('note.txt', $stream, new Config());
        fclose($stream);

        $body = json_decode($this->capturing->bodies[0], true);
        self::assertIsArray($body);
        self::assertSame(11, $body['fileSize']);
    }

    public function testWriteStreamBuffersAStreamOfUnknownLength(): void
    {
        $this->willAcceptUpload('file-1', 'note.txt', 5);

        $stream = self::pipe('hello');

        $this->adapter()->writeStream('note.txt', $stream, new Config());
        fclose($stream);

        $body = json_decode($this->capturing->bodies[0], true);
        self::assertIsArray($body);
        self::assertSame(5, $body['fileSize'], 'A pipe reports size 0, so it has to be buffered to be measured.');
    }

    public function testAnExplicitSizeHintSkipsBuffering(): void
    {
        $this->willAcceptUpload('file-1', 'note.txt', 5);

        $stream = self::pipe('hello');

        $this->adapter()->writeStream('note.txt', $stream, new Config([MyboxAdapter::OPTION_SIZE => 5]));
        fclose($stream);

        $body = json_decode($this->capturing->bodies[0], true);
        self::assertIsArray($body);
        self::assertSame(5, $body['fileSize']);
    }

    public function testRefusingToBufferSurfacesAsAWriteFailure(): void
    {
        $stream = self::pipe('hello');
        $adapter = $this->adapter(options: new MyboxAdapterOptions(unknownSize: UnknownSizeStrategy::Fail));

        $this->expectException(UnableToWriteFile::class);

        try {
            $adapter->writeStream('note.txt', $stream, new Config());
        } finally {
            fclose($stream);
            self::assertCount(0, $this->requests(), 'The size is resolved before any request is made.');
        }
    }

    /**
     * A non-seekable stream whose length fstat() cannot report, standing in for a
     * socket, a pipe, or an upload arriving over the network.
     *
     * @return resource
     */
    private static function pipe(string $contents)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            self::markTestSkipped('This platform has no socket pairs to make a non-seekable stream from.');
        }

        [$read, $write] = $pair;
        fwrite($write, $contents);
        fclose($write);

        return $read;
    }
}
