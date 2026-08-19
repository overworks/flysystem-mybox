<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Upload;

use League\Flysystem\Config;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToWriteFile;
use Minhyung\Flysystem\Mybox\Enum\UnknownSizeStrategy;
use Minhyung\Flysystem\Mybox\MyboxAdapter;
use Minhyung\Flysystem\Mybox\MyboxAdapterOptions;

/**
 * Establishes the exact byte length of a `writeStream()` payload.
 *
 * MYBOX reserves an upload against a declared length and answers HTTP 500
 * `File Storage Error` when the bytes disagree, so this never estimates. It
 * takes the caller's hint, else a trustworthy fstat, else it buffers.
 *
 * @internal
 */
final class UploadSize
{
    /**
     * @param resource $contents
     */
    public static function resolve(string $path, mixed $contents, Config $config, MyboxAdapterOptions $options): ResolvedUpload
    {
        $hint = self::hint($config);

        if ($hint !== null) {
            return new ResolvedUpload($hint, $contents, false);
        }

        $measured = self::measure($contents);

        if ($measured !== null) {
            return new ResolvedUpload($measured, $contents, false);
        }

        if ($options->unknownSize === UnknownSizeStrategy::Fail) {
            throw UnableToWriteFile::atLocation(
                $path,
                sprintf(
                    'Could not determine the exact byte length of the stream, and MYBOX requires one. Pass it as the "%s" config option, or allow buffering.',
                    MyboxAdapter::OPTION_SIZE,
                ),
            );
        }

        return self::buffer($path, $contents, $options->bufferThresholdBytes);
    }

    private static function hint(Config $config): ?int
    {
        foreach ([MyboxAdapter::OPTION_SIZE, StorageAttributes::ATTRIBUTE_FILE_SIZE] as $key) {
            $value = $config->get($key);

            if (is_int($value) && $value >= 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param resource $contents
     */
    private static function measure(mixed $contents): ?int
    {
        if (!is_resource($contents)) {
            return null;
        }

        // A socket, a pipe or a non-seekable wrapper reports size 0, which is
        // indistinguishable from a genuinely empty file. Only a seekable regular
        // file can be believed.
        $meta = stream_get_meta_data($contents);

        if ($meta['seekable'] !== true) {
            return null;
        }

        $stat = fstat($contents);

        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
            return null;
        }

        $position = ftell($contents);

        if ($position === false) {
            return null;
        }

        return max(0, $stat['size'] - $position);
    }

    /**
     * @param resource $contents
     */
    private static function buffer(string $path, mixed $contents, int $thresholdBytes): ResolvedUpload
    {
        $buffer = fopen(sprintf('php://temp/maxmemory:%d', max(0, $thresholdBytes)), 'w+b');

        if ($buffer === false) {
            throw UnableToWriteFile::atLocation($path, 'Could not open a temporary buffer to measure the stream.');
        }

        $copied = stream_copy_to_stream($contents, $buffer);

        if ($copied === false) {
            fclose($buffer);

            throw UnableToWriteFile::atLocation($path, 'Could not buffer the stream to measure it.');
        }

        rewind($buffer);

        return new ResolvedUpload($copied, $buffer, true);
    }
}
