<?php
declare(strict_types=1);

/*
 * Shared setup for the engine suites. They verify their PDF output by
 * shelling out to Python (pypdf, pypdfium2, numpy, pillow, fonttools) and
 * some need a DejaVu family on disk, neither of which is discoverable in a
 * portable way. This finds both, so no suite needs environment set by hand.
 */

/*
 * PEP 668 blocks installing the probe packages into a Homebrew or system
 * Python, so a project-local venv is the supported layout. Prepending it to
 * PATH is what makes the suites' bare `python3` calls resolve to it.
 */
$venvBin = dirname(__DIR__, 3) . '/.venv/bin';
if (is_dir($venvBin)) {
    putenv('PATH=' . $venvBin . PATH_SEPARATOR . getenv('PATH'));
}

/**
 * What every suite declares about `<body>` before the document's own sheet.
 *
 * Defect FI gave `<body>` the 8px margin Chrome's UA sheet gives it, which
 * moves every box in every document 6pt right and 6pt down. **None of these
 * suites is asking about that.** A grid case asserts where a track lands and a
 * regression case pins a fold at an exact offset, so a document that starts 8px
 * lower puts that fold somewhere else and the case stops reproducing the thing
 * it was written to catch. Re-baselining the numbers instead would leave every
 * case carrying a constant it does not test and several of them exercising a
 * different fold from the one they were written for.
 *
 * Every probe page under `docs/harness/probes/` opens with the same declaration
 * for the same reason, which is what `SU-body-bare.html` had to be written
 * without to ask FI's question at all.
 *
 * Added **before** the document's own stylesheet, so a case declaring a `body`
 * margin of its own still wins on source order.
 */
const SUITE_BODY_RESET = 'body { margin: 0 }';

/**
 * The `<body>` box inside the tree `HtmlBuilder::build()` returns.
 *
 * Defect DG gave the root element a box of its own, so a tree is now
 * `html > body > ...` where it used to start at the body. Every assertion in
 * these suites was written against the body's children and still means what it
 * meant; what changed is that it takes one more step to reach them.
 *
 * The body is the first child because `head` and everything in it is
 * `display: none` in the UA sheet, so the root box has exactly one. Laying out
 * and paginating still start from the real root, which is what the renderer
 * does: only the assertions descend.
 */
function bodyOf(FlexPDF\Engine\Node $root): FlexPDF\Engine\Node
{
    return $root->children[0] ?? $root;
}

/**
 * Resolved lazily so the suites that need no fonts still run on a host
 * without DejaVu installed.
 */
function dejavu_dir(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = array_values(array_filter([
        getenv('FLEXPDF_TEST_FONT_DIR') ?: null,
        '/usr/share/fonts/truetype/dejavu',
        '/usr/share/fonts/dejavu',
        getenv('HOME') . '/Library/Fonts',
        '/Library/Fonts',
    ]));

    foreach ($candidates as $candidate) {
        $dir = rtrim($candidate, '/') . '/';

        if (is_file($dir . 'DejaVuSans.ttf')) {
            return $resolved = $dir;
        }
    }

    fwrite(STDERR, sprintf(
        "DejaVuSans.ttf not found. Looked in:\n  %s\n\n"
        . "Install it, or point FLEXPDF_TEST_FONT_DIR at the directory holding it.\n"
        . "  macOS:  brew install --cask font-dejavu\n"
        . "  Debian: apt-get install fonts-dejavu-core\n",
        implode("\n  ", $candidates)
    ));
    exit(1);
}
