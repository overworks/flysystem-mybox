<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests\Double;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client backed by an in-memory MYBOX drive.
 *
 * It exists so the upstream `FilesystemAdapterTestCase` can run in CI without a
 * real account and without spending quota. To be worth anything it has to
 * reproduce the API's documented surprises rather than an idealised version of
 * it, so it deliberately keeps:
 *
 * - a trashed resource readable by id, with only its parent changed;
 * - HTTP 409 when a folder is created with a name already taken, since
 *   `createFolder` has no overwrite flag;
 * - HTTP 409 on a rename into an occupied name, for the same reason;
 * - a download URL that works exactly once.
 *
 * Anything relying on those being lenient is a bug this double is meant to catch.
 */
final class InMemoryMyboxServer implements ClientInterface
{
    public const API_HOST = 'open-api.mybox.naver.com';
    public const STORAGE_HOST = 'storage.example.test';
    public const ROOT_ID = 'root-0';
    public const TRASH_ID = 'trash-0';

    /** @var array<string, array{id: string, name: string, parentId: string, type: string, contents: string, modifiedAt: int}> */
    private array $resources = [];

    /** @var array<string, string> upload token => target resource id */
    private array $uploads = [];

    /** @var array<string, string> download token => resource id; consumed on first use */
    private array $downloads = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private int $sequence = 0;

    public function __construct(private readonly int $pageSize = 1000)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $uri = $request->getUri();
        $path = $uri->getPath();
        $method = $request->getMethod();

        if ($uri->getHost() === self::STORAGE_HOST) {
            return $this->storage($request, $path);
        }

        parse_str($uri->getQuery(), $query);

        return match (true) {
            $method === 'GET' && $path === '/v1/drive/resources' => $this->listing(self::ROOT_ID, $query),
            $method === 'GET' && (bool) preg_match('#^/v1/drive/folders/([^/]+)/resources$#', $path, $m) => $this->listing(rawurldecode($m[1]), $query),
            $method === 'GET' && (bool) preg_match('#^/v1/drive/files/([^/]+)/download$#', $path, $m) => $this->downloadTicket(rawurldecode($m[1])),
            $method === 'GET' && (bool) preg_match('#^/v1/drive/resources/([^/]+)$#', $path, $m) => $this->detail(rawurldecode($m[1])),
            $method === 'POST' && $path === '/v1/drive/folders' => $this->createFolder($this->body($request)),
            $method === 'POST' && $path === '/v1/drive/files' => $this->uploadTicket($this->body($request)),
            $method === 'POST' && (bool) preg_match('#^/v1/drive/resources/([^/]+)/move$#', $path, $m) => $this->move(rawurldecode($m[1]), $this->body($request)),
            $method === 'POST' && (bool) preg_match('#^/v1/drive/resources/([^/]+)/rename$#', $path, $m) => $this->rename(rawurldecode($m[1]), $this->body($request)),
            $method === 'POST' && (bool) preg_match('#^/v1/drive/resources/([^/]+)/copy$#', $path, $m) => $this->copy(rawurldecode($m[1]), $this->body($request)),
            $method === 'DELETE' && (bool) preg_match('#^/v1/drive/trash/([^/]+)$#', $path, $m) => $this->purge(rawurldecode($m[1])),
            $method === 'DELETE' && (bool) preg_match('#^/v1/drive/resources/([^/]+)$#', $path, $m) => $this->trash(rawurldecode($m[1])),
            default => $this->error(404, 'PLAT-404', 'NOT_FOUND'),
        };
    }

    public function reset(): void
    {
        $this->resources = [];
        $this->uploads = [];
        $this->downloads = [];
        $this->requests = [];
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    // --- routes ------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $query
     */
    private function listing(string $folderId, array $query): ResponseInterface
    {
        if ($folderId !== self::ROOT_ID && !isset($this->resources[$folderId])) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $children = array_values(array_filter(
            $this->resources,
            static fn (array $r): bool => $r['parentId'] === $folderId,
        ));

        $count = is_numeric($query['count'] ?? null) ? (int) $query['count'] : $this->pageSize;
        $offset = is_numeric($query['cursor'] ?? null) ? (int) $query['cursor'] : 0;
        $page = array_slice($children, $offset, $count);
        $next = $offset + $count < count($children) ? (string) ($offset + $count) : null;

        return $this->json([
            'fileCount' => count(array_filter($page, static fn (array $r): bool => $r['type'] === 'file')),
            'subFolderCount' => count(array_filter($page, static fn (array $r): bool => $r['type'] === 'folder')),
            'resources' => array_map($this->present(...), $page),
            'responseMetaData' => $next === null ? [] : ['nextCursor' => $next],
        ]);
    }

    private function detail(string $id): ResponseInterface
    {
        $resource = $this->resources[$id] ?? null;

        // Note: a trashed resource still answers here. That is MYBOX behaviour, and
        // it is why the adapter must never use this endpoint to test existence.
        return $resource === null
            ? $this->error(404, 'PLAT-404', 'NOT_FOUND')
            : $this->json($this->present($resource));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createFolder(array $body): ResponseInterface
    {
        $name = is_string($body['folderName'] ?? null) ? $body['folderName'] : '';
        $parentId = is_string($body['parentId'] ?? null) ? $body['parentId'] : self::ROOT_ID;

        if ($this->child($parentId, $name, 'folder') !== null) {
            return $this->error(409, 'PLAT-409', 'ALREADY_EXISTS');
        }

        $id = $this->store($parentId, $name, 'folder', '');

        return $this->json(['resourceId' => $id, 'name' => $name]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function uploadTicket(array $body): ResponseInterface
    {
        $name = is_string($body['fileName'] ?? null) ? $body['fileName'] : '';
        $parentId = is_string($body['parentId'] ?? null) ? $body['parentId'] : self::ROOT_ID;
        $overwrite = ($body['isOverwrite'] ?? false) === true;
        $existing = $this->child($parentId, $name, 'file');

        if ($existing !== null && !$overwrite) {
            return $this->error(409, 'PLAT-409', 'ALREADY_EXISTS');
        }

        $id = $existing['id'] ?? $this->store($parentId, $name, 'file', '');
        $token = 'upload-' . ++$this->sequence;
        $this->uploads[$token] = $id;

        return $this->json([
            'uploadUrl' => sprintf('https://%s/v1/storage/upload?stoken=%s', self::STORAGE_HOST, $token),
            'offset' => 0,
        ]);
    }

    private function downloadTicket(string $id): ResponseInterface
    {
        $resource = $this->resources[$id] ?? null;

        if ($resource === null || $resource['type'] !== 'file') {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $token = 'download-' . ++$this->sequence;
        $this->downloads[$token] = $id;

        return $this->json([
            'downloadUrl' => sprintf('https://%s/v1/storage/download?atoken=%s', self::STORAGE_HOST, $token),
            'expiresIn' => 600,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function move(string $id, array $body): ResponseInterface
    {
        $resource = $this->resources[$id] ?? null;
        $parentId = is_string($body['parentId'] ?? null) ? $body['parentId'] : self::ROOT_ID;

        if ($resource === null) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $occupant = $this->child($parentId, $resource['name'], $resource['type']);

        if ($occupant !== null && $occupant['id'] !== $id) {
            if (($body['isOverwrite'] ?? false) !== true) {
                return $this->error(409, 'PLAT-409', 'ALREADY_EXISTS');
            }

            $this->drop($occupant['id']);
        }

        $this->resources[$id]['parentId'] = $parentId;

        return new Response(204);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rename(string $id, array $body): ResponseInterface
    {
        $resource = $this->resources[$id] ?? null;
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';

        if ($resource === null) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $occupant = $this->child($resource['parentId'], $name, $resource['type']);

        if ($occupant !== null && $occupant['id'] !== $id) {
            // rename has no isOverwrite, so this is a hard 409 — the reason the
            // adapter clears a move destination first.
            return $this->error(409, 'PLAT-409', 'ALREADY_EXISTS');
        }

        $this->resources[$id]['name'] = $name;

        return $this->json(['name' => $name]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function copy(string $id, array $body): ResponseInterface
    {
        $resource = $this->resources[$id] ?? null;

        if ($resource === null) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $parentId = is_string($body['parentId'] ?? null) ? $body['parentId'] : self::ROOT_ID;
        $name = is_string($body['name'] ?? null) ? $body['name'] : $resource['name'];
        $occupant = $this->child($parentId, $name, $resource['type']);

        if ($occupant !== null) {
            if (($body['isOverwrite'] ?? false) !== true) {
                return $this->error(409, 'PLAT-409', 'ALREADY_EXISTS');
            }

            $this->drop($occupant['id']);
        }

        $copyId = $this->store($parentId, $name, $resource['type'], $resource['contents']);

        foreach ($this->descendants($id) as $descendant) {
            $this->store($copyId, $descendant['name'], $descendant['type'], $descendant['contents']);
        }

        return $this->json(['resourceId' => $copyId, 'name' => $name]);
    }

    private function trash(string $id): ResponseInterface
    {
        if (!isset($this->resources[$id])) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        // Only the parent changes; the resource stays readable by id.
        $this->resources[$id]['parentId'] = self::TRASH_ID;

        return new Response(204);
    }

    private function purge(string $id): ResponseInterface
    {
        if (!isset($this->resources[$id])) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        $this->drop($id);

        return new Response(204);
    }

    private function storage(RequestInterface $request, string $path): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);

        if (str_ends_with($path, '/upload')) {
            $token = is_string($query['stoken'] ?? null) ? $query['stoken'] : '';
            $id = $this->uploads[$token] ?? null;

            if ($id === null || !isset($this->resources[$id])) {
                return $this->error(404, 'PLAT-404', 'NOT_FOUND');
            }

            unset($this->uploads[$token]);
            $contents = self::unwrapMultipart((string) $request->getBody());
            $this->resources[$id]['contents'] = $contents;
            $this->resources[$id]['modifiedAt'] = time();

            return $this->json([
                'resourceId' => $id,
                'name' => $this->resources[$id]['name'],
                'fileSize' => strlen($contents),
            ]);
        }

        $token = is_string($query['atoken'] ?? null) ? $query['atoken'] : '';
        $id = $this->downloads[$token] ?? null;

        if ($id === null || !isset($this->resources[$id])) {
            return $this->error(404, 'PLAT-404', 'NOT_FOUND');
        }

        // Single use, exactly like the real thing.
        unset($this->downloads[$token]);

        return new Response(200, ['Content-Type' => 'application/octet-stream'], $this->resources[$id]['contents']);
    }

    // --- state -------------------------------------------------------------

    private function store(string $parentId, string $name, string $type, string $contents): string
    {
        $id = sprintf('%s-%d', $type, ++$this->sequence);

        $this->resources[$id] = [
            'id' => $id,
            'name' => $name,
            'parentId' => $parentId,
            'type' => $type,
            'contents' => $contents,
            'modifiedAt' => time(),
        ];

        return $id;
    }

    /**
     * @return array{id: string, name: string, parentId: string, type: string, contents: string, modifiedAt: int}|null
     */
    private function child(string $parentId, string $name, string $type): ?array
    {
        $wanted = self::fold($name);

        foreach ($this->resources as $resource) {
            // MYBOX matches names case-insensitively, and keeps the spelling it
            // already had. Reproducing that here is the point of this double.
            if ($resource['parentId'] === $parentId && self::fold($resource['name']) === $wanted && $resource['type'] === $type) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, name: string, parentId: string, type: string, contents: string, modifiedAt: int}>
     */
    private function descendants(string $parentId): array
    {
        return array_values(array_filter(
            $this->resources,
            static fn (array $r): bool => $r['parentId'] === $parentId,
        ));
    }

    private function drop(string $id): void
    {
        foreach ($this->descendants($id) as $child) {
            $this->drop($child['id']);
        }

        unset($this->resources[$id]);
    }

    /**
     * @param array{id: string, name: string, parentId: string, type: string, contents: string, modifiedAt: int} $resource
     * @return array<string, mixed>
     */
    private function present(array $resource): array
    {
        $timestamp = date('c', $resource['modifiedAt']);

        return [
            'accessedAt' => $timestamp,
            'createdAt' => $timestamp,
            'modifiedAt' => $timestamp,
            'isFavorite' => false,
            'isHidden' => false,
            'lastModifiedBy' => 'mybox_user',
            'name' => $resource['name'],
            'parentId' => $resource['parentId'],
            'resourceId' => $resource['id'],
            'size' => strlen($resource['contents']),
            'type' => $resource['type'],
        ];
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function body(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function error(int $status, string $code, string $message): ResponseInterface
    {
        return $this->json([
            'code' => $code,
            'message' => $message,
            'requestId' => 'in-memory',
            'timestamp' => date('c'),
        ], $status);
    }

    private static function fold(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * Pulls the Filedata part's bytes back out of the multipart envelope.
     */
    private static function unwrapMultipart(string $body): string
    {
        $start = strpos($body, "\r\n\r\n");

        if ($start === false) {
            return $body;
        }

        $payload = substr($body, $start + 4);
        $end = strrpos($payload, "\r\n--");

        return $end === false ? $payload : substr($payload, 0, $end);
    }
}
