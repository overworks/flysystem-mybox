<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Stream;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Presents a PSR-7 stream as a PHP resource, which is what `readStream()` returns.
 *
 * The download body arrives as a {@see StreamInterface} and Flysystem hands its
 * callers a resource, so something has to bridge the two. `detach()` would be
 * shorter but it is allowed to return null, and some PSR-18 clients' response
 * bodies do exactly that; a wrapper is one deterministic code path instead of
 * two.
 *
 * @internal
 */
final class PsrStreamResource
{
    public const PROTOCOL = 'flysystem-mybox';

    /** Set by PHP on the wrapper instance; never read by us, but it must exist. */
    public mixed $context = null;

    private StreamInterface $stream;

    /**
     * @return resource
     */
    public static function wrap(StreamInterface $stream)
    {
        self::register();

        $context = stream_context_create([self::PROTOCOL => ['stream' => $stream]]);
        $resource = @fopen(self::PROTOCOL . '://stream', 'rb', false, $context);

        if ($resource === false) {
            throw new RuntimeException('Could not open the MYBOX download stream as a resource.');
        }

        return $resource;
    }

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class, 0);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $context = $this->context;

        if (!is_resource($context)) {
            return false;
        }

        $stream = stream_context_get_options($context)[self::PROTOCOL]['stream'] ?? null;

        if (!$stream instanceof StreamInterface) {
            return false;
        }

        $this->stream = $stream;

        return true;
    }

    public function stream_read(int $count): string
    {
        return $this->stream->read($count);
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_tell(): int
    {
        return $this->stream->tell();
    }

    public function stream_eof(): bool
    {
        return $this->stream->eof();
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if (!$this->stream->isSeekable()) {
            return false;
        }

        $this->stream->seek($offset, $whence);

        return true;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return $this->stat();
    }

    /**
     * @return array<int|string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return $this->stat();
    }

    public function stream_close(): void
    {
        $this->stream->close();
    }

    public function stream_cast(int $castAs): bool
    {
        return false;
    }

    /**
     * @return array<int|string, int>
     */
    private function stat(): array
    {
        // 0100444: a regular file, read-only. Callers that fstat() the handle get a
        // shape they recognise instead of an empty array.
        $values = [
            'dev' => 0, 'ino' => 0, 'mode' => 0100444, 'nlink' => 0, 'uid' => 0, 'gid' => 0,
            'rdev' => 0, 'size' => $this->stream->getSize() ?? 0, 'atime' => 0, 'mtime' => 0,
            'ctime' => 0, 'blksize' => -1, 'blocks' => -1,
        ];

        return array_merge(array_values($values), $values);
    }
}
