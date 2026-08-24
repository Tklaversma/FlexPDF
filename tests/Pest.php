<?php

declare(strict_types=1);

use FlexPDF\Tests\TestCase;

uses(TestCase::class)->in(__DIR__ . '/Feature');

/** Counts the page objects a PDF byte string declares. */
function pdfPageCount(string $bytes): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $bytes);
}

/** A real TrueType file to exercise font embedding, or null when unavailable. */
function dejavuPath(): ?string
{
    $candidates = array_filter([
        getenv('FLEXPDF_TEST_FONT_DIR') ?: null,
        '/usr/share/fonts/truetype/dejavu',
        getenv('HOME') . '/Library/Fonts',
    ]);

    foreach ($candidates as $candidate) {
        $path = rtrim($candidate, '/') . '/DejaVuSans.ttf';

        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}
