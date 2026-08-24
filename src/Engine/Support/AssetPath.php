<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * Which files a document is allowed to name.
 *
 * A `<link href>`, an `<img src>` and an `@font-face src` are all
 * author-controlled and none of them is operator-controlled, so each is a way
 * for a document to point at a file the operator never meant to publish. The
 * channel is real in both directions: an image is embedded whole, and font
 * subsetting copies glyph data out of whatever file it is handed.
 *
 * So there is one rule, in one place, and the three call sites share it:
 * a candidate must resolve, with symlinks followed, to a readable file inside
 * the configured base path. Remote and protocol-relative candidates are
 * refused outright rather than fetched, because fetching a URL a document
 * names is an SSRF sink. No base path means no file is reachable at all.
 */
final class AssetPath
{
    /** The file this candidate names, or null if it names nothing reachable. */
    public static function resolve(string $candidate, string $basePath): ?string
    {
        $candidate = trim(explode('#', explode('?', trim($candidate))[0])[0]);

        if ($candidate === '' || $basePath === '' || self::isRemote($candidate)) {
            return null;
        }

        $base = realpath($basePath);

        if ($base === false) {
            return null;
        }

        $root = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // An absolute path already inside the base path is as legitimate as a
        // relative one, so both spellings are tried and both are contained.
        foreach ([$candidate, $root . ltrim($candidate, '/')] as $try) {
            $path = realpath($try);

            if ($path === false || !str_starts_with($path, $root)) {
                continue;
            }

            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function isRemote(string $candidate): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) === 1
            || str_starts_with($candidate, '//');
    }
}
