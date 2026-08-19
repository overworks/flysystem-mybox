<?php

declare(strict_types=1);

namespace Minhyung\Flysystem\Mybox\Path;

use Normalizer;

/**
 * Folds a name into the form MYBOX compares names by.
 *
 * Two measured facts drive this, both recorded in docs/adapter-notes.md:
 *
 * - MYBOX matches names **case-insensitively**. Writing `case.txt` into a folder
 *   already holding `Case.txt` overwrites it and keeps the original spelling, so
 *   a lookup that did not fold case would miss the file it just wrote.
 * - MYBOX stores Hangul composed (NFC) while macOS hands out decomposed (NFD).
 *   The two are different byte strings, so an unfolded lookup of a Korean name
 *   silently misses.
 *
 * Only ever used as a map key. The name sent to the API is always the caller's
 * original spelling.
 *
 * @internal
 */
final class NameKey
{
    public static function fold(string $name): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

            if ($normalized !== false) {
                $name = $normalized;
            }
        }

        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * Folds every segment of a path, leaving the separators alone so prefix
     * matching over cache keys still works.
     */
    public static function foldPath(string $location): string
    {
        if ($location === '') {
            return '';
        }

        return implode('/', array_map(self::fold(...), explode('/', $location)));
    }
}
