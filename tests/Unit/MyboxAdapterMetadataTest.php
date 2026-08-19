<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
#[CoversClass(MyboxAdapterOptions::class)]
final class MyboxAdapterMetadataTest extends TestCase
{
    public function testMimeTypeComesFromTheExtensionBecauseMyboxSendsNone(): void
    {
        $this->willList([['name' => 'drawing.svg', 'id' => 'file-1']]);

        self::assertSame('image/svg+xml', $this->adapter()->mimeType('drawing.svg')->mimeType());
        self::assertCount(1, $this->requests(), 'Detecting a mime type must never download the file.');
    }

    public function testAnUnrecognisedExtensionIsAnError(): void
    {
        $this->willList([['name' => 'checksum.md5', 'id' => 'file-1']]);

        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter()->mimeType('checksum.md5');
    }

    public function testFileSizeOfADirectoryIsAnError(): void
    {
        // MYBOX does report a size for folders, so the adapter has to reject this.
        $this->willList([['name' => 'docs', 'type' => 'folder', 'id' => 'folder-1', 'size' => 4096]]);

        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter()->fileSize('docs/');
    }

    public function testVisibilityIsWhateverTheAdapterWasToldToReport(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);

        $adapter = $this->adapter(options: new MyboxAdapterOptions(visibility: Visibility::PUBLIC));

        self::assertSame(Visibility::PUBLIC, $adapter->visibility('note.txt')->visibility());
    }

    public function testSettingVisibilityIsRefusedRatherThanFaked(): void
    {
        $this->expectException(UnableToSetVisibility::class);

        $this->adapter()->setVisibility('note.txt', Visibility::PUBLIC);
    }

    public function testSettingVisibilityCanBeDowngradedToANoOp(): void
    {
        $this->adapter(options: new MyboxAdapterOptions(failOnSetVisibility: false))
            ->setVisibility('note.txt', Visibility::PUBLIC);

        self::assertCount(0, $this->requests());
    }

    public function testMetadataOfAMissingFileIsAnError(): void
    {
        $this->willList([]);

        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter()->lastModified('missing.txt');
    }
}
