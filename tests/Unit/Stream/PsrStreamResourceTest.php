<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit\Stream;

use GuzzleHttp\Psr7\Utils;
use Minhyung\Flysystem\Mybox\Stream\PsrStreamResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsrStreamResource::class)]
final class PsrStreamResourceTest extends TestCase
{
    public function testTheWrappedStreamReadsBackAsAResource(): void
    {
        $resource = PsrStreamResource::wrap(Utils::streamFor('hello world'));

        self::assertIsResource($resource);
        self::assertSame('hello world', stream_get_contents($resource));
        self::assertTrue(feof($resource));

        fclose($resource);
    }

    public function testItReadsInChunksWithoutBufferingTheWholeBody(): void
    {
        $resource = PsrStreamResource::wrap(Utils::streamFor('hello world'));

        self::assertSame('hello', fread($resource, 5));
        self::assertSame(5, ftell($resource));
        self::assertSame(' world', stream_get_contents($resource));

        fclose($resource);
    }

    public function testASeekableBodyCanBeRewound(): void
    {
        $resource = PsrStreamResource::wrap(Utils::streamFor('hello'));

        fread($resource, 5);
        self::assertSame(0, fseek($resource, 0));
        self::assertSame('hello', stream_get_contents($resource));

        fclose($resource);
    }

    public function testStatReportsTheSizeSoCallersThatFstatItGetAnAnswer(): void
    {
        $resource = PsrStreamResource::wrap(Utils::streamFor('hello'));
        $stat = fstat($resource);

        self::assertIsArray($stat);
        self::assertSame(5, $stat['size']);

        fclose($resource);
    }
}
