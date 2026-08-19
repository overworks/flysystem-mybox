<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Unit;

use League\Flysystem\Config;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\Support\FailureReason;
use Minhyung\Flysystem\Mybox\Tests\TestCase;
use Minhyung\Mybox\Exception\InsufficientStorageException;
use Minhyung\Mybox\Exception\MyboxException;
use Minhyung\Mybox\Exception\RateLimitException;
use Minhyung\Mybox\Exception\UnauthorizedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every failure has to arrive as the Flysystem exception the interface promises,
 * with the SDK exception still reachable underneath and a reason an operator can
 * act on.
 */
#[CoversClass(MyboxAdapter::class)]
#[CoversClass(FailureReason::class)]
final class MyboxAdapterExceptionMappingTest extends TestCase
{
    public function testAnExhaustedQuotaBecomesAWriteFailureThatKeepsTheCause(): void
    {
        $this->willError(507, 'PLAT-507', 'INSUFFICIENT_STORAGE');

        try {
            $this->adapter()->write('note.txt', 'hello', new Config());
            self::fail('Expected the write to fail.');
        } catch (UnableToWriteFile $exception) {
            self::assertInstanceOf(InsufficientStorageException::class, $exception->getPrevious());
            self::assertStringContainsString('quota is exhausted', $exception->reason());
            self::assertStringContainsString('requestId', $exception->reason());
        }
    }

    public function testARejectedTokenSurfacesOnAnExistenceCheck(): void
    {
        $this->willError(401, 'PLAT-401', 'UNAUTHORIZED');

        try {
            $this->adapter()->fileExists('note.txt');
            self::fail('Expected the existence check to fail.');
        } catch (UnableToCheckFileExistence $exception) {
            self::assertInstanceOf(UnauthorizedException::class, $exception->getPrevious());
        }
    }

    public function testARateLimitReasonNamesTheRetryDelay(): void
    {
        $this->willError(429, 'PLAT-429', 'TOO_MANY_REQUESTS', ['Retry-After' => '30']);

        try {
            $this->adapter()->read('note.txt');
            self::fail('Expected the read to fail.');
        } catch (UnableToReadFile $exception) {
            self::assertInstanceOf(RateLimitException::class, $exception->getPrevious());
            self::assertStringContainsString('30 seconds', $exception->reason());
        }
    }

    #[DataProvider('statuses')]
    public function testEveryApiFailureIsAFilesystemException(int $status, string $message): void
    {
        $this->willError($status, 'PLAT-' . $status, $message);

        try {
            $this->adapter()->fileExists('note.txt');
            self::fail('Expected a failure.');
        } catch (FilesystemException $exception) {
            self::assertInstanceOf(MyboxException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield 'bad request' => [400, 'BAD_REQUEST'];
        yield 'forbidden' => [403, 'FORBIDDEN'];
        yield 'conflict' => [409, 'ALREADY_EXISTS'];
        yield 'unprocessable' => [422, 'UNPROCESSABLE'];
        yield 'locked' => [423, 'LOCKED'];
        yield 'server error' => [500, 'INTERNAL_ERROR'];
    }

    /**
     * @param array<string, string> $headers
     */
    private function willError(int $status, string $code, string $message, array $headers = []): void
    {
        $this->willRespond([
            'code' => $code,
            'message' => $message,
            'requestId' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'timestamp' => '2026-08-19T16:30:00+09:00',
        ], $status, $headers);
    }
}
