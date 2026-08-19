<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use DateTimeImmutable;
use League\Flysystem\Config;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MyboxAdapter::class)]
final class MyboxAdapterUrlTest extends TestCase
{
    public function testATemporaryUrlIsTheSingleUseDownloadUrl(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willTicket();

        $url = $this->adapter()->temporaryUrl('note.txt', new DateTimeImmutable('+1 minute'), new Config());

        self::assertSame('https://storage.example.test/v1/storage/download?atoken=t', $url);
        $this->assertRequest(1, 'GET', '/v1/drive/files/file-1/download');
    }

    public function testAnExpiryBeyondWhatMyboxGrantsIsRefused(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willTicket();

        $this->expectException(UnableToGenerateTemporaryUrl::class);

        // Ten minutes is all MYBOX gives; handing back a shorter-lived URL than
        // asked for would be a silent surprise.
        $this->adapter()->temporaryUrl('note.txt', new DateTimeImmutable('+1 hour'), new Config());
    }

    public function testALongerExpiryIsAllowedWhenStrictnessIsTurnedOff(): void
    {
        $this->willList([['name' => 'note.txt', 'id' => 'file-1']]);
        $this->willTicket();

        $url = $this->adapter(options: new MyboxAdapterOptions(strictTemporaryUrlExpiry: false))
            ->temporaryUrl('note.txt', new DateTimeImmutable('+1 hour'), new Config());

        self::assertStringStartsWith('https://storage.example.test/', $url);
    }

    public function testATemporaryUrlForAMissingFileIsRefused(): void
    {
        $this->willList([]);

        $this->expectException(UnableToGenerateTemporaryUrl::class);

        $this->adapter()->temporaryUrl('missing.txt', new DateTimeImmutable('+1 minute'), new Config());
    }

    public function testTheAdapterDoesNotPretendToOfferPublicUrls(): void
    {
        // MYBOX has no sharing endpoint, so a "public" URL would be a single-use
        // link that dies on first fetch. Not implementing the interface is honest.
        self::assertNotContains(PublicUrlGenerator::class, class_implements(MyboxAdapter::class) ?: []);
    }

    private function willTicket(): void
    {
        $this->willRespond([
            'downloadUrl' => 'https://storage.example.test/v1/storage/download?atoken=t',
            'expiresIn' => 600,
        ]);
    }
}
