<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Support;

use Minhyung\Mybox\Exception\ApiException;
use Minhyung\Mybox\Exception\BadRequestException;
use Minhyung\Mybox\Exception\ConflictException;
use Minhyung\Mybox\Exception\ForbiddenException;
use Minhyung\Mybox\Exception\InsufficientStorageException;
use Minhyung\Mybox\Exception\LockedException;
use Minhyung\Mybox\Exception\MyboxException;
use Minhyung\Mybox\Exception\NotFoundException;
use Minhyung\Mybox\Exception\RateLimitException;
use Minhyung\Mybox\Exception\ServerException;
use Minhyung\Mybox\Exception\TransportException;
use Minhyung\Mybox\Exception\UnauthorizedException;

/**
 * Renders an SDK exception into the `reason` string Flysystem shows.
 *
 * Flysystem's own message says only which operation failed and where, so this is
 * the only place an operator learns *why*. Keep the MYBOX request id in it: it
 * is what Naver support asks for.
 */
final class FailureReason
{
    public static function of(MyboxException $exception): string
    {
        $reason = match (true) {
            $exception instanceof UnauthorizedException => 'MYBOX rejected the personal access token (HTTP 401).',
            $exception instanceof ForbiddenException => 'The token lacks permission for this resource (HTTP 403).',
            $exception instanceof NotFoundException => 'MYBOX has no such resource (HTTP 404).',
            $exception instanceof ConflictException => 'A resource with that name already exists (HTTP 409).',
            $exception instanceof LockedException => 'The resource is locked, usually by an interrupted upload (HTTP 423).',
            $exception instanceof RateLimitException => sprintf(
                'MYBOX rate limit exceeded (HTTP 429); retry after %s seconds.',
                $exception->retryAfter ?? 'a few',
            ),
            $exception instanceof InsufficientStorageException => 'The MYBOX quota is exhausted (HTTP 507).',
            $exception instanceof ServerException => sprintf(
                'MYBOX returned a server error (HTTP %d). An upload whose declared size does not match the bytes sent also surfaces as a 500.',
                $exception->status,
            ),
            $exception instanceof BadRequestException => sprintf('MYBOX rejected the request (HTTP 400): %s', $exception->getMessage()),
            $exception instanceof TransportException => sprintf('Could not reach MYBOX: %s', $exception->getMessage()),
            default => $exception->getMessage(),
        };

        if ($exception instanceof ApiException && $exception->requestId !== null) {
            $reason .= sprintf(' (requestId %s)', $exception->requestId);
        }

        return $reason;
    }
}
