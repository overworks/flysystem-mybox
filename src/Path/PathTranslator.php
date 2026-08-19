<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Path;

use League\Flysystem\PathPrefixer;

/**
 * Converts between Flysystem paths and the locations this adapter works in.
 *
 * A *location* is a prefixed path with no leading or trailing slash, so the
 * drive root is the empty string. Every public adapter method translates once,
 * on the way in, which is the whole of the root-directory feature: the resolver
 * below never learns that a root prefix exists.
 */
final class PathTranslator
{
    private readonly PathPrefixer $prefixer;

    public function __construct(string $rootDirectory = '')
    {
        $this->prefixer = new PathPrefixer($rootDirectory, '/');
    }

    public function location(string $path): string
    {
        return rtrim($this->prefixer->prefixPath($path), '/');
    }

    public function path(string $location): string
    {
        return $this->prefixer->stripPrefix($location);
    }

    public static function dirname(string $location): string
    {
        $position = strrpos($location, '/');

        return $position === false ? '' : substr($location, 0, $position);
    }

    public static function basename(string $location): string
    {
        $position = strrpos($location, '/');

        return $position === false ? $location : substr($location, $position + 1);
    }

    public static function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory . '/' . $name;
    }
}
