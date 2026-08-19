<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Tests;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps the mock client and snapshots each request body as it is sent.
 *
 * Upload requests stream straight from a file handle that the uploader closes
 * once the transfer is done, so the body has to be captured at send time — the
 * same moment a real HTTP client would read it.
 */
final class CapturingClient implements ClientInterface
{
    /** @var list<string> */
    public array $bodies = [];

    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $position = $body->tell();
            $this->bodies[] = $body->getContents();
            $body->seek($position);
        } else {
            $this->bodies[] = '';
        }

        return $this->inner->sendRequest($request);
    }
}
