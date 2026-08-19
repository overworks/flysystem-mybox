<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Upload;

/**
 * A payload whose exact byte length is known, ready to hand to the SDK uploader.
 *
 * @internal
 */
final class ResolvedUpload
{
    /**
     * @param resource $stream
     * @param bool $owned Whether {@see self::release()} should close the stream — true only
     *                    when we created it by buffering the caller's.
     */
    public function __construct(
        public readonly int $size,
        public readonly mixed $stream,
        private readonly bool $owned,
    ) {
    }

    public function release(): void
    {
        if ($this->owned && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
