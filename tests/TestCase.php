<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use Minhyung\Flysystem\Mybox\Cache\DirectoryCache;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;
use Minhyung\Mybox\ClientConfig;
use Minhyung\Mybox\Http\RetryPolicy;
use Minhyung\Mybox\MyboxClient;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Wires an adapter onto an in-memory PSR-18 client.
 *
 * The mocking happens at the HTTP layer rather than around the SDK, because the
 * bugs worth catching in this package are request-shaped — did the cache save a
 * call, did the walk avoid search, did a move issue move-then-rename — and those
 * are only visible in the request log. The SDK's classes are `final` anyway.
 */
abstract class TestCase extends BaseTestCase
{
    protected MockClient $http;

    protected CapturingClient $capturing;

    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockClient();
        $this->capturing = new CapturingClient($this->http);
        $this->sleeper = new RecordingSleeper();
    }

    protected function adapter(
        string $root = '',
        ?MyboxAdapterOptions $options = null,
        ?DirectoryCache $cache = null,
    ): MyboxAdapter {
        return new MyboxAdapter($this->mybox(), $root, $options, null, $cache);
    }

    protected function mybox(): MyboxClient
    {
        $factory = new HttpFactory();

        // RetryPolicy::none(): the transport applies retries to storage-host traffic
        // too, and a retried request would silently consume the next queued response.
        return new MyboxClient(
            new ClientConfig('mbx_pat_test', retryPolicy: RetryPolicy::none()),
            $this->capturing,
            $factory,
            $factory,
            sleeper: $this->sleeper,
        );
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     */
    protected function willRespond(array|string $body = [], int $status = 200, array $headers = []): void
    {
        $payload = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE);

        $this->http->addResponse(new Response($status, $headers + ['Content-Type' => 'application/json'], $payload));
    }

    /**
     * One page of a drive listing.
     *
     * @param list<array{name: string, type?: string, id?: string, size?: int, modifiedAt?: string}> $rows
     */
    protected function willList(array $rows, ?string $nextCursor = null, string $parentId = 'parent-1'): void
    {
        $resources = array_map(static fn (array $row): array => [
            'accessedAt' => $row['modifiedAt'] ?? '2026-08-11T09:00:00+09:00',
            'createdAt' => $row['modifiedAt'] ?? '2026-08-11T09:00:00+09:00',
            'modifiedAt' => $row['modifiedAt'] ?? '2026-08-11T09:00:00+09:00',
            'isFavorite' => false,
            'isHidden' => false,
            'lastModifiedBy' => 'mybox_user',
            'name' => $row['name'],
            'parentId' => $parentId,
            'resourceId' => $row['id'] ?? 'id-' . $row['name'],
            'size' => $row['size'] ?? 0,
            'type' => $row['type'] ?? 'file',
        ], $rows);

        $this->willRespond([
            'fileCount' => count($rows),
            'subFolderCount' => 0,
            'resources' => $resources,
            'responseMetaData' => $nextCursor === null ? [] : ['nextCursor' => $nextCursor],
        ]);
    }

    /**
     * The two responses one upload needs: the reservation, then the storage host.
     */
    protected function willAcceptUpload(string $resourceId = 'new-file', string $name = 'file.txt', int $size = 8): void
    {
        $this->willRespond(['uploadUrl' => 'https://storage.example.com/v1/storage/upload?stoken=t', 'offset' => 0]);
        $this->willRespond(['resourceId' => $resourceId, 'name' => $name, 'fileSize' => $size]);
    }

    /**
     * @return list<RequestInterface>
     */
    protected function requests(): array
    {
        return array_values($this->http->getRequests());
    }

    protected function lastRequest(): RequestInterface
    {
        $requests = $this->requests();

        self::assertNotEmpty($requests, 'Expected the adapter to have made a request.');

        return $requests[count($requests) - 1];
    }

    protected function assertRequest(int $index, string $method, string $pathAndQuery): void
    {
        $requests = $this->requests();

        self::assertArrayHasKey($index, $requests, sprintf('No request was made at index %d.', $index));

        $request = $requests[$index];
        $uri = $request->getUri();
        $actual = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

        self::assertSame($method, $request->getMethod());
        self::assertSame($pathAndQuery, urldecode($actual));
    }

    protected function assertNoSearchRequests(): void
    {
        foreach ($this->requests() as $request) {
            self::assertStringStartsNotWith(
                '/v1/search',
                $request->getUri()->getPath(),
                'The adapter must never use the rate-limited search endpoint.',
            );
        }
    }
}
