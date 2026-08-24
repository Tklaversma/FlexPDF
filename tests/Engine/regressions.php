<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, Fragment, Fragmenter, Html, Node, FontRegistry};
use FlexPDF\Engine\Exceptions\PageLimitExceededException;
use FlexPDF\Engine\Support\Limits;

/**
 * Minimal repros for every bug the fuzzer found. Each one rendered something
 * plausible rather than failing loudly, and none was reachable from the
 * hand-written suites.
 */

require_once __DIR__ . '/support/bootstrap.php';

define('DEJAVU', dejavu_dir());
const PAGE_W = 400.0;
const PAGE_H = 300.0;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

function layout(string $html, float $w = PAGE_W, float $h = PAGE_H, string $basePath = ''): Node
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $resolver = new StyleResolver();
    $resolver->basePath = $basePath;
    $resolver->addStylesheet(SUITE_BODY_RESET);
    foreach ($d->getElementsByTagName('style') as $style) {
        $resolver->addStylesheet($style->textContent);
    }
    $tree = (new HtmlBuilder($resolver))->build($d);
    (new FlexLayout())->layout($tree, $w, $h);
    return $tree;
}

/** @return array{0:int,1:int,2:int} pages, overflowing fragments, glyphs lost */
function paginate(Node $tree, float $h = PAGE_H): array
{
    $count = static function (Node $n) use (&$count): int {
        $t = 0;
        foreach ($n->lineBoxes as $lb) {
            foreach ($lb->items as $i) { if (!$i->isSpace) { $t++; } }
        }
        foreach ($n->children as $c) { $t += $count($c); }
        return $t;
    };
    $before = $count($tree);

    $pages = (new Fragmenter($h))->fragment($tree);

    $over = 0;
    $after = 0;
    foreach ($pages as $page) {
        foreach ($page as $f) {
            if ($f->y + $f->h > $h + 0.5 || $f->y > $h + 0.5) { $over++; }
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $i) { if (!$i->isSpace) { $after++; } }
            }
        }
    }
    return [count($pages), $over, max(0, $before - $after)];
}

/**
 * Paginate under an explicit page ceiling.
 *
 * @return array{0:int,1:int,2:?string} pages, glyphs lost, exception class
 */
function paginateUnder(Node $tree, int $ceiling, float $h = PAGE_H): array
{
    $count = static function (Node $n) use (&$count): int {
        $t = 0;
        foreach ($n->lineBoxes as $lb) {
            foreach ($lb->items as $i) { if (!$i->isSpace) { $t++; } }
        }
        foreach ($n->children as $c) { $t += $count($c); }
        return $t;
    };
    $before = $count($tree);

    $limits = new Limits(maxPages: $ceiling, timeoutSeconds: 0.0);

    try {
        $pages = (new Fragmenter($h, limits: $limits))->fragment($tree);
    } catch (\Throwable $e) {
        return [0, 0, get_class($e)];
    }

    $after = 0;
    foreach ($pages as $page) {
        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $i) { if (!$i->isSpace) { $after++; } }
            }
        }
    }

    return [count($pages), max(0, $before - $after), null];
}

function negativeBoxes(Node $n): int
{
    $bad = ($n->layoutWidth < -0.01 || $n->layoutHeight < -0.01) ? 1 : 0;
    foreach ($n->children as $c) { $bad += negativeBoxes($c); }
    return $bad;
}

FontRegistry::reset();
FontRegistry::default()->registerTrueType('DejaVu', DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf');

echo "\nRegressions found by fuzzing\n\n";

// 1: absolutely positioned boxes were paginated as flow content ------
$tree = layout(
    '<style>.abs{position:absolute;top:600pt;left:10pt;width:100pt;height:1200pt;background:#ccc}</style>'
    . '<p>flow</p><div class="abs">tall absolute</div>'
);
[$pages, $over, $lost] = paginate($tree);
ok('an absolutely positioned box does not overflow the page it lands on',
    $over === 0, "$pages pages");

// 2: a fragment starting past the fold was deleted -------------------
$tree = layout(
    '<style>.abs{position:absolute;top:900pt;left:0;width:200pt}</style>'
    . '<p>flow</p><div class="abs">text parked three pages down</div>'
);
[$pages, $over, $lost] = paginate($tree);
ok('content parked below the fold is moved, not dropped',
    $lost === 0 && $over === 0, "$pages pages, lost $lost");

// 3: a table row taller than the page lost everything below ---------
$tree = layout('<table>'
    . str_repeat('<tr><td style="height:400pt">tall cell</td></tr>', 4)
    . '</table>');
[$pages, $over, $lost] = paginate($tree);
ok('a table row taller than the page keeps its content and its height',
    $lost === 0 && $over === 0 && $pages >= 5,
    "$pages pages (1600pt over 300pt), lost $lost");

// 4: relocation walked one page per round ---------------------------
$tree = layout(
    '<style>.far{position:absolute;top:9000pt;left:0;width:80pt;background:#eee}</style>'
    . '<p>flow</p><div class="far">very far down</div>'
);
$t0 = microtime(true);
[$pages, $over, $lost] = paginate($tree);
$ms = (microtime(true) - $t0) * 1000;
ok('a box parked thousands of points down resolves in one step',
    $over === 0 && $ms < 250.0, sprintf('%d pages in %.0f ms', $pages, $ms));

// 5: negative used sizes from calc ----------------------------------
$tree = layout('<div style="width: calc(0pt - 50pt)">negative width</div>');
ok('a negative calc() width is ignored rather than applied',
    negativeBoxes($tree) === 0);

// 6: negative row width from a zero-width pinned column -------------
$tree = layout('<table style="padding:3pt"><colgroup><col style="width:0"></colgroup>'
    . '<tr><td>x</td></tr></table>');
ok('a table narrower than its own padding yields no negative boxes',
    negativeBoxes($tree) === 0);

// 7: an empty table inside a zero-width parent ----------------------
$tree = layout('<div style="width:0"><section style="display:table;margin:4pt"></section></div>');
ok('an empty table in a zero-width parent yields no negative boxes',
    negativeBoxes($tree) === 0);

// 8: out-of-flow nested inside out-of-flow was never placed ---------
$tree = layout(
    '<style>.outer{position:absolute;top:0;left:0;display:flex}'
    . '.inner{position:absolute;top:20pt;left:20pt;width:120pt}</style>'
    . '<div class="outer"><div class="inner">nested absolute content</div></div>'
);
[$pages, $over, $lost] = paginate($tree);
ok('an out-of-flow box inside another out-of-flow box still gets placed',
    $lost === 0, "lost $lost");

// 9: a stretched flex item is taller than its own lines -------------
$tree = layout(
    '<li style="display:flex;position:absolute">'
    . '<ul style="font-size:40px"><span>' . str_repeat('long ', 40) . '</span></ul>'
    . str_repeat('short ', 30) . '</li>'
);
[$pages, $over, $lost] = paginate($tree);
ok('a stretched flex item taller than its lines is clipped to the page',
    $over === 0, "$pages pages");

// 10: a single line taller than the page ping-ponged forever --------
$tree = layout('<style>p{font-size:60pt;line-height:3}</style>'
    . '<p>' . str_repeat('word ', 20) . '</p>');
[$pages, $over, $lost] = paginate($tree);
ok('a line box taller than the page converges instead of walking forward',
    $over === 0 && $pages < 40, "$pages pages");

// 11: an absolutely positioned cell must not occupy a grid slot -----
$tree = layout('<table><tr>'
    . '<td>one</td><td style="position:absolute;top:0;left:0">floating cell</td><td>two</td>'
    . '</tr></table>');
[$pages, $over, $lost] = paginate($tree);
ok('an absolutely positioned cell leaves the grid but keeps its content',
    $lost === 0 && $over === 0);

// 12: margins must survive the two-pass float layout ----------------
$tree = layout('<style>.a{margin-bottom:30pt}.b{margin-top:-10pt}</style>'
    . '<div class="a">one</div><div class="b">two</div>');
// Defect DG: `layout()` returns the real root, so the two blocks are the
// body's children rather than the tree's.
$blocks = bodyOf($tree)->children;
$gap    = $blocks[1]->y - ($blocks[0]->y + $blocks[0]->layoutHeight);
ok('collapsing still sums a negative margin after the float rework',
    abs($gap - 20.0) < 0.01, sprintf('%.1fpt', $gap));

// 13: float exclusions must survive nesting -------------------------
$tree = layout(
    '<style>.f{float:left;width:100pt;height:60pt;margin-right:10pt}</style>'
    . '<div class="f">F</div><div><p>' . str_repeat('word ', 40) . '</p></div>'
);
$find = static function (Node $n) use (&$find): array {
    $out = [];
    if ($n->display === 'text' && $n->lineBoxes !== []) { $out[] = $n; }
    foreach ($n->children as $c) { $out = array_merge($out, $find($c)); }
    return $out;
};
$texts = $find($tree);
$para = end($texts);
$firstX = $para->lineBoxes[0]->items[0]->x ?? 0.0;
ok('a float still shortens line boxes two levels down',
    $firstX > 100.0, sprintf('first line starts at x=%.1f', $firstX));


// 14: a repeating header taller than the page stalled pagination --------
// Every continuation page was filled by the header before any content could
// land, so page-advancing loops span until they hit their guard.
$tree = layout(
    '<table><thead><tr><th style="height:400pt">huge header</th></tr></thead><tbody>'
    . str_repeat('<tr><td>row</td></tr>', 40)
    . '</tbody></table>'
);
$t0 = microtime(true);
[$pages, $over, $lost] = paginate($tree);
$ms = (microtime(true) - $t0) * 1000;
ok('a repeating header taller than the page does not stall pagination',
    $pages < 100 && $ms < 500.0 && $lost === 0,
    sprintf('%d pages in %.0f ms, lost %d', $pages, $ms, $lost));

// 15: remaining space must never go negative ---------------------------
$tree = layout('<div style="height:5000pt">tall block</div>');
$t0 = microtime(true);
[$pages, $over, $lost] = paginate($tree);
$ms = (microtime(true) - $t0) * 1000;
ok('a 5000pt block paginates in bounded time',
    $pages >= 15 && $pages < 30 && $over === 0 && $ms < 500.0,
    sprintf('%d pages in %.0f ms', $pages, $ms));


// 16: a table row far taller than the page ------------------------------
// Cells share a row, so they must paginate beside each other, not one after
// the next. This used to take 21 seconds and 2,000 pages.
$tree = layout('<table><tr>'
    . '<td style="height:9000pt">a</td><td style="height:9000pt">b</td>'
    . '</tr></table>');
$t0 = microtime(true);
[$pages, $over, $lost] = paginate($tree);
$ms = (microtime(true) - $t0) * 1000;
ok('cells in an oversized row paginate beside each other, not in sequence',
    $pages < 80 && $over === 0 && $lost === 0 && $ms < 500.0,
    sprintf('%d pages in %.0f ms', $pages, $ms));

// 17: a grid taller than the page --------------------------------------
$tree = layout('<style>.g{display:grid;grid-template-columns:repeat(2,1fr)}</style>'
    . '<div class="g">' . str_repeat('<div>' . str_repeat('word ', 15) . '</div>', 60) . '</div>');
[$pages, $over, $lost] = paginate($tree);
ok('a grid breaks between row bands without losing content',
    $pages > 1 && $over === 0 && $lost === 0, "$pages pages, lost $lost");

// 18: nothing may paint past a page edge, ever -------------------------
$tree = layout('<p style="font-size:400pt;line-height:2">' . str_repeat('word ', 10) . '</p>');
[$pages, $over, $lost] = paginate($tree);
ok('a line box far taller than the page never paints past the edge',
    $over === 0, "$pages pages");

// 19: a line box taller than the page collapsed the whole flow ----------
// The cursor is page-local, but the chunk it advanced by was not bounded by
// the space left, so it ran past the fold without the page counter moving.
// The pages that line covered were never created and everything after it was
// stacked back on top.
$tree = layout('<div style="font-size:2000pt;line-height:3">word</div><p>after</p>');
[$pages, $over, $lost] = paginate($tree);
$needed = (int) floor($tree->layoutHeight / PAGE_H);
ok('a line box taller than the page still occupies the pages it covers',
    $pages >= $needed && $over === 0 && $lost === 0,
    sprintf('%d pages for %.0fpt of flow (needs %d), lost %d',
        $pages, $tree->layoutHeight, $needed, $lost));

// 20: a relative offset was re-applied at every level of nesting --------
// Descendants were already in absolute coordinates, so shifting them by their
// parent's position instead of by the offset pushed each level a further
// page-length down. Only a box far down the page showed it, and a purely
// horizontal offset corrupted y just the same.
$tree = layout('<div style="height:500pt"></div>'
    . '<table style="position:relative;left:5pt;border-collapse:collapse">'
    . '<tr><td>cell</td></tr></table>');
$findBy = static function (Node $n, string $display) use (&$findBy): ?Node {
    if ($n->display === $display) { return $n; }
    foreach ($n->children as $c) {
        $hit = $findBy($c, $display);
        if ($hit !== null) { return $hit; }
    }
    return null;
};
$table = $findBy($tree, 'table');
$row = $findBy($table, 'table-row');
ok('a relative offset shifts a subtree by the offset, not by its own position',
    abs($row->y - $table->y) < 0.01,
    sprintf('table y=%.1f, first row y=%.1f', $table->y, $row->y));

// 21: an oversized gap between two children collapsed the pages it spanned -
// Same defect class as 19, at the other unbounded call site. flowChildren and
// splitContainer advanced the cursor by the gap between two children without
// bounding it by the space left on the page, so a gap taller than a page ran
// the cursor past the fold while the page counter stayed put. The pages the
// gap covered were never created and the second child was stacked back near
// the first.
//
// This case demanded the pages the gap covers, which round 18c measured as
// the engine's own answer rather than Chrome's and round 24 fixed: CSS
// Fragmentation section 3.5 truncates a margin the break falls inside, so a
// margin never buys a page of its own. Chrome renders this exact document on
// two pages with `bottom` at the top of the second, and so does this engine
// now. The invariant the case was written for is the part that survives: the
// gap must not collapse, so the two blocks land on different pages, and
// nothing may be lost.
$tree = layout('<div>top</div><div style="margin-top:1000pt">bottom</div>');
[$pages, $over, $lost] = paginate($tree);

$linesOn = static function (array $page): int {
    $lines = 0;

    foreach ($page as $fragment) {
        $lines += count($fragment->lines);
    }

    return $lines;
};

$fragments = (new Fragmenter(PAGE_H))->fragment($tree);
$second    = $fragments[1] ?? [];
$topOfPage = array_all(
    array_filter($second, static fn($f): bool => $f->lines !== []),
    static fn($f): bool => $f->y < 0.5,
);

ok('a gap taller than the page turns exactly one page and truncates',
    $pages === 2 && $over === 0 && $lost === 0
        && $linesOn($fragments[0]) === 1 && $linesOn($second) === 1 && $topOfPage,
    sprintf('%d pages for %.0fpt of flow (want 2), %d and %d lines, lost %d',
        $pages, $tree->layoutHeight, $linesOn($fragments[0]), $linesOn($second), $lost));

// 22: the page ceiling was off by one -----------------------------------
// newPage() guarded on `$this->page >= maxPages` with $page zero-based, so
// the page it was about to create was already one past the ceiling. A limit
// of N produced N + 1 pages, which is why the fuzzer's 2,000-page ceiling
// showed up in the sweep table as 2,001.
$fourteenPages = '<div>' . str_repeat('<p style="height:280pt">block</p>', 14) . '</div>';

$tree = layout($fourteenPages);
[$pages, $lost, $threw] = paginateUnder($tree, 14);
ok('a ceiling of N admits a document exactly N pages long',
    $threw === null && $pages === 14 && $lost === 0,
    $threw ?? "$pages pages, lost $lost");

$tree = layout($fourteenPages);
[$pages, $lost, $threw] = paginateUnder($tree, 13);
ok('a ceiling of N never yields N + 1 pages',
    $threw === PageLimitExceededException::class,
    $threw === null ? "returned $pages pages for a ceiling of 13" : 'threw');

// 23: the ceiling truncated silently, which is how a fragmented document
// lost glyph runs -------------------------------------------------------
// Seed 8675309 #187 lost 12 of 179 glyph runs. It was not a splitting bug:
// the document needs 2,379 pages and the 2,000-page ceiling cut the tail off
// without saying so, so the fuzzer's glyph-count invariant was the only thing
// that noticed. A control whose job is to say no must not return a document
// that is quietly missing its end.
$tree = layout($fourteenPages);
[$pages, $lost, $threw] = paginateUnder($tree, 3);
ok('exceeding the page ceiling throws instead of returning a short document',
    $threw === PageLimitExceededException::class,
    $threw === null ? "returned $pages pages having lost $lost glyph runs" : 'threw');

// 24: the ceiling had a second way out ---------------------------------
// clampOverflow spills the lines of an overflowing fragment onto the next
// page, and it created that page directly rather than through newPage(), so
// it never saw the ceiling. An out-of-flow box is emitted whole wherever it
// lands, which is how a one-page ceiling still returned two pages.
$tree = layout('<div style="height:200pt">top</div>'
    . '<div style="position:absolute;top:250pt;left:0;width:300pt">'
    . str_repeat('word ', 120) . '</div>');
[$pages, $lost, $threw] = paginateUnder($tree, 1);
ok('lines spilling forward cannot create a page past the ceiling',
    $threw === PageLimitExceededException::class,
    $threw === null ? "returned $pages pages for a ceiling of 1" : 'threw');

// 25, and a third way out ----------------------------------------------
// A fragment parked below its own page is relocated to the page its
// coordinate falls on, and that guard compared a zero-based page index
// against the page *count*, so it admitted one index too many. Only a box
// landing on exactly that index shows it: further out it is dropped anyway.
$tree = layout('<div style="height:100pt">top</div>'
    . '<div style="position:absolute;top:650pt;left:0;width:100pt;height:20pt;background:#333"></div>');
[$pages, $lost, $threw] = paginateUnder($tree, 2);
ok('relocating a fragment cannot land it past the ceiling',
    $threw === PageLimitExceededException::class,
    $threw === null ? "returned $pages pages for a ceiling of 2" : 'threw');

// 26: flex lines less than a point apart counted as one -----------------
// countFlexLines() collected distinct child `y` values into an array keyed on
// the float itself, and a float array key truncates to int, so every line
// inside the same integer point collapsed into one entry. A wrap container
// counted as single-line is refused a break between its lines, so it restarts
// whole on the next page instead of straddling the fold.
//
// Round 18l moved the shape and kept the invariant. The padding was 200pt,
// which puts the container's first line 50pt BELOW the fold, and Chrome moves
// such a box whole: `ND-fold-flexwrap-bigpad.html` is 204.000 once on the
// second page, items at 198.000 and 201.000, and this engine now renders it
// rect for rect the same. So the old assertion asked for the one answer Chrome
// does not give, and it no longer discriminated anything: the buggy count
// moves the box whole too. At 149pt the two lines are at 299.000 and 299.600,
// still inside one integer point, and both are ABOVE the fold, so a container
// counted as two lines keeps its first item on the first page and one counted
// as a single line carries it to y=149 on the second.
// `NF-fold-flexwrap-between-lines.html` is the shape in whole CSS pixels and
// Chrome breaks between the lines there exactly as this does.
$tree = layout(
    '<style>.spacer{height:150pt}'
    . '.wrap{display:flex;flex-wrap:wrap;width:100pt;padding-top:149pt;font-size:0;line-height:0}'
    . '.wrap>i{display:block;width:60pt;height:0.6pt;background:#333}</style>'
    . '<div class="spacer"></div><div class="wrap"><i></i><i></i></div>'
);
$wrap = $findBy($tree, 'flex');
$item = $wrap->children[0];
$where = null;
foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
    foreach ($page as $f) {
        if ($f->node === $item) { $where = ['page' => $pi, 'y' => $f->y]; }
    }
}
ok('a wrap flex container with two lines less than a point apart breaks across the fold',
    $where !== null && $where['page'] === 0 && abs($where['y'] - 299.0) < 0.01,
    $where === null
        ? 'the first item was never emitted'
        : sprintf('first item on page %d at y=%.2f', $where['page'], $where['y']));

// 27. a cell reported its declared height, not the taller content it paints -
// CSS 2.1 §17.5.3 makes a cell's specified height a minimum, not a definite
// size, and Chrome reports 400pt for the cell, its row and the table alike in
// both spellings below. The engine reported 50pt for the first and 0pt for the
// second, and the row, the table and the whole flow inherited the shortfall, so
// pagination was measured against a document shorter than the one being
// painted: seed 20260727 #265 painted 471 pages while claiming 10,086pt of
// flow for 72,962pt of content.
$cellHeights = static function (string $declared) use (&$findBy): array {
    $tree  = layout(
        '<style>td{height:' . $declared . ';padding:0}.tall{height:400pt}</style>'
        . '<table style="border-collapse:collapse"><tr><td>'
        . '<div class="tall">tall child</div></td></tr></table>'
    );
    $table = $findBy($tree, 'table');
    $row   = $findBy($table, 'table-row');
    $cell  = $findBy($row, 'table-cell');

    return [$cell->layoutHeight, $row->layoutHeight, $table->layoutHeight, $tree->layoutHeight];
};

$agree = static fn(array $heights): bool => array_all(
    $heights,
    static fn(float $h): bool => abs($h - 400.0) < 0.01,
);

[$cellH, $rowH, $tableH, $flowH] = $cellHeights('50pt');
ok('a cell with a specified height reports the taller child it wraps',
    $agree([$cellH, $rowH, $tableH, $flowH]),
    sprintf('cell %.1f, row %.1f, table %.1f, flow %.1f', $cellH, $rowH, $tableH, $flowH));

[$cellH, $rowH, $tableH, $flowH] = $cellHeights('120%');
ok('a percentage cell height is a minimum too, not a definite size',
    $agree([$cellH, $rowH, $tableH, $flowH]),
    sprintf('cell %.1f, row %.1f, table %.1f, flow %.1f', $cellH, $rowH, $tableH, $flowH));

// 28. a line taller than the page skipped the page it was already on --------
// splitText takes a new page whenever fewer lines fit than `orphans` wants,
// and a line taller than the whole page can never satisfy that, so it moved
// even when nothing had been placed on the current page yet. The next page
// offers exactly the same room, so the move bought a blank page and then
// clipped the line anyway: 3 pages where Chrome prints 2, the first of them
// empty. Same principle as skipping a forced break at the top of a page.
$tree = layout('<p style="font-size:400pt;line-height:1.25">Wg</p>');
$first = null;
foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
    foreach ($page as $f) {
        if ($f->lines !== [] && $first === null) { $first = $pi; }
    }
}
ok('a line taller than the page starts on the page it is already on',
    $first === 0,
    $first === null ? 'no line was emitted' : "first line on page $first");

// 29. an atomic inline taller than the page was clipped at the fold --------
// emitSlices() already draws a block taller than a page as a run of page-sized
// slices. A box sitting on a line had no equivalent, because the line is the
// fragmentation unit and the box on it is atomic, so everything past the fold
// was clipped away: ink per page 0.1500 0.0000 on a 400x300pt page where
// Chrome prints 0.1521 0.1037. The fragment is re-emitted on each page its
// line reaches, with the origin moved up by one page height, so each page
// carries the part of the line that falls on it. Now 0.1500 0.1014.
$tree = layout(
    '<style>.tall{display:inline-block;width:60pt;height:500pt;background:#333}</style>'
    . '<p>before <span class="tall"></span> after</p>'
);
$tops = [];
foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
    foreach ($page as $f) {
        $cursor = $f->y;
        foreach ($f->lines as $lb) {
            foreach ($lb->items as $item) {
                if ($item->isAtomic()) {
                    $tops[] = [$pi, $cursor + $lb->baseline - $item->run->box->baselineOffset()];
                }
            }
            $cursor += $lb->height;
        }
    }
}
ok('a 500pt inline-block continues onto the next page instead of stopping at the fold',
    count($tops) === 2
        && $tops[0] === [0, 0.0]
        && $tops[1][0] === 1 && abs($tops[1][1] + PAGE_H) < 0.01,
    sprintf('%d slice(s) at %s',
        count($tops),
        json_encode(array_map(fn(array $t): string => sprintf('page %d y=%.1f', $t[0], $t[1]), $tops))));

// 30. a flex container whose children run up the page ----------------------
// Both fragmentation walkers advance the cursor by the distance from the
// previous child's bottom to the next child's top, which assumes document
// order runs down the page. `column-reverse` and `order` are the two ways it
// does not: the last child sits highest, `max(0, relTop - prevBottom)` clamps
// every step to zero, and a four-child 600pt column came out as four pages
// with a blank first one and the reading order inverted. Chrome prints two:
// Delta Gamma, then Beta Alpha. Fixed by walking a flex container's children
// in ascending y.
$pageWords = static function (Node $tree): array {
    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
        $out[$pi] = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $out[$pi][] = trim($item->text); }
                }
            }
        }
    }

    return $out;
};

$reversed = layout(
    '<style>.col{display:flex;flex-direction:column-reverse;height:600pt;width:400pt;font-size:30pt}'
    . '.col>div{height:150pt}</style>'
    . '<div class="col"><div>Alpha</div><div>Beta</div><div>Gamma</div><div>Delta</div></div>'
);
$ordered = layout(
    '<style>.col{display:flex;flex-direction:column;height:600pt;width:400pt;font-size:30pt}'
    . '.col>div{height:150pt}.a{order:4}.b{order:3}.c{order:2}.d{order:1}</style>'
    . '<div class="col"><div class="a">Alpha</div><div class="b">Beta</div>'
    . '<div class="c">Gamma</div><div class="d">Delta</div></div>'
);
$expected = [['Delta', 'Gamma'], ['Beta', 'Alpha']];

ok('a column-reverse container paginates down the page, not up it',
    $pageWords($reversed) === $expected,
    json_encode($pageWords($reversed)));

ok('`order` paginates in the order the items were laid out in',
    $pageWords($ordered) === $expected,
    json_encode($pageWords($ordered)));

// 31. a <tfoot> rendered once, in the middle of the table -------------------
// A `<tfoot>` written before the `<tbody>`, which is where HTML puts it so a
// streaming parser sees it early, was flowed in document order and never
// repeated: it printed straight after the header on page 1 and nowhere else.
// Chrome repeats it at the foot of every page the table reaches. The rows are
// moved to the end of the table at build time, and the footer's height is held
// back from every page in `remaining()` before the body is flowed, then the
// footer is emitted once per page afterwards.
$tree = layout(
    '<style>table{border-collapse:collapse;width:300pt;font-size:12pt}'
    . 'td,th{border:1pt solid #000;padding:2pt}</style>'
    . '<table><thead><tr><th>H</th></tr></thead>'
    . '<tfoot><tr><td>FOOT</td></tr></tfoot><tbody>'
    . implode('', array_map(static fn(int $i): string => "<tr><td>r$i</td></tr>", range(1, 40)))
    . '</tbody></table>'
);

$footPerPage = [];

foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
    $footPerPage[$pi] ??= 0;

    foreach ($page as $f) {
        foreach ($f->lines as $lb) {
            foreach ($lb->items as $item) {
                if (trim($item->text) === 'FOOT') { $footPerPage[$pi]++; }
            }
        }
    }
}

ok('a <tfoot> repeats at the bottom of every page its table reaches',
    count($footPerPage) > 1 && array_all($footPerPage, static fn(int $n): bool => $n === 1),
    sprintf('%d pages, FOOT per page %s', count($footPerPage), json_encode(array_values($footPerPage))));

// 32. A line box may be shorter than the font sitting on it. Half-leading goes
// negative once `line-height` drops below the face's own ascent plus descent,
// and flooring each half at zero instead of flooring the height made every
// line at least a full em tall. That is a pagination bug as much as a metrics
// one: it decides how many lines fit on a page. Chrome puts 30 lines of 20pt
// text at `line-height: 0.25` in 150pt, which is 5pt a line.
$tree  = layout(
    '<style>p{width:200pt;font-size:20pt;line-height:0.25;margin:0}</style>'
    . '<p>' . implode(' ', array_fill(0, 60, 'word')) . '</p>'
);
$lines = [];

$collect = static function (Node $n) use (&$collect, &$lines): void {
    foreach ($n->lineBoxes as $lb) { $lines[] = $lb->height; }
    foreach ($n->children as $c) { $collect($c); }
};
$collect($tree);

ok('a line box is as tall as line-height, not as tall as the font',
    $lines !== [] && array_all($lines, static fn(float $h): bool => abs($h - 5.0) < 0.01),
    sprintf('%d lines, first %.2fpt, want 5.00', count($lines), $lines[0] ?? -1.0));

// The flow is what pagination divides by the page height, so the floor showed
// up there as 114.1pt for 15 lines that occupy 75. Nothing is lost either way.
[$pages32, $over32, $lost32] = paginate($tree);

ok('a tight line-height reports the flow height it actually occupies',
    abs($tree->layoutHeight - 75.0) < 0.5 && $pages32 === 1 && $over32 === 0 && $lost32 === 0,
    sprintf('%.1fpt of flow on %d page(s), want 75.0', $tree->layoutHeight, $pages32));

// 33. A box that spans a page break paints its decoration through a proxy
// node built per page, and the proxy carried only the background colour, the
// border and the corner radii. Everything else the painter draws behind the
// content was therefore dropped for exactly the boxes tall enough to split: a
// `background-image` layer, a gradient and a `box-shadow` all vanished at the
// fold, and a box whose only background was a gradient produced no proxy at
// all. The slice also has to know where it sits inside the whole box, or the
// background restarts on every page instead of carrying on across it.
$tree = layout(
    '<style>.card{background-image:linear-gradient(to bottom,#c00,#00c);'
    . 'box-shadow:0 0 8pt #000;padding:4pt}p{margin:0;font-size:12pt}</style>'
    . '<div class="card">'
    . implode('', array_map(static fn(int $i): string => "<p>line $i</p>", range(1, 60)))
    . '</div>'
);

$decorations = [];

foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
    foreach ($page as $f) {
        if ($f->node->backgroundLayers !== []) {
            $decorations[$pi] = $f->node->slicedBackground[0] ?? -1.0;
        }
    }
}

ok('a background layer survives onto every page its box reaches',
    count($decorations) > 2,
    sprintf('%d pages carry the gradient, of %d', count($decorations), count($tree->children) > 0 ? 3 : 0));

// Each slice starts one page further into the box than the one before it, so
// the ramp is continuous rather than restarting at every fold.
$offsets = array_values($decorations);
$steps   = [];

for ($i = 1; $i < count($offsets); $i++) {
    $steps[] = $offsets[$i] - $offsets[$i - 1];
}

ok('each slice knows how far into the box it starts',
    $steps !== [] && array_all($steps, static fn(float $d): bool => abs($d - PAGE_H) < 1.0),
    sprintf('offsets %s, want steps of %.0f', json_encode($offsets), PAGE_H));

// 34. A word an author styles part of must not gain a break opportunity where
// the styling starts. `tokenize()` emitted one token per run and the line
// breaker may break between tokens, so `<b>Ham</b>burgefonstiv` broke into
// `Ham` and `burgefonstiv`, and so did `100<sup>th</sup>`, `<b>50</b>,00` and
// a link against the comma after it. Chrome keeps each of them whole. That is
// a pagination question as much as a wrapping one: it decides how many lines a
// paragraph occupies, and therefore where it splits.
$textOf = static function (Node $tree): array {
    $out     = [];
    $collect = static function (Node $n) use (&$collect, &$out): void {
        foreach ($n->lineBoxes as $lb) {
            $line = '';
            foreach ($lb->items as $item) { $line .= $item->text; }
            $out[] = trim($line);
        }
        foreach ($n->children as $c) { $collect($c); }
    };
    $collect($tree);

    return $out;
};

$lines34 = $textOf(layout(
    '<style>div{width:120pt;font-family:Helvetica;font-size:15pt;margin:0}</style>'
    . '<div>aaa <span>Ham</span>burgefonstiv bbb</div>'
));

ok('a word split across two inline elements wraps as one word',
    $lines34 === ['aaa', 'Hamburgefonstiv', 'bbb'],
    sprintf('%s, want ["aaa","Hamburgefonstiv","bbb"]', json_encode($lines34)));

// A space between the two elements is still a break opportunity, so the fix
// must not weld every adjacent run together.
$lines34b = $textOf(layout(
    '<style>div{width:120pt;font-family:Helvetica;font-size:15pt;margin:0}</style>'
    . '<div>aaa <span>Ham </span>burgefonstiv bbb</div>'
));

ok('white space between two inline elements is still a break opportunity',
    count($lines34b) === 2 && $lines34b[0] === 'aaa Ham',
    sprintf('%s, want ["aaa Ham", ...]', json_encode($lines34b)));

// The min-content contribution has to grow with the group, or a shrink-to-fit
// box is measured at its widest *piece* and the word paints past its own edge.
$narrow = layout(
    '<style>.sf{float:left;font-family:Helvetica;font-size:15pt;margin:0}</style>'
    . '<div class="sf">a<span>b</span>Hamburgefonstiv</div>'
);
$float  = null;
$find   = static function (Node $n) use (&$find, &$float): void {
    if ($n->isFloating()) { $float ??= $n; }
    foreach ($n->children as $c) { $find($c); }
};
$find($narrow);

ok('a shrink-to-fit box is as wide as the whole joined word',
    $float !== null && $float->layoutWidth > 110.0,
    sprintf('%.2fpt wide, want the whole word', $float?->layoutWidth ?? -1.0));

// 35. `box-decoration-break` defaults to `slice`, so a page break is not an
// edge of the box: Chrome paints a split box's top border on the first page
// only and its bottom border on the last only, where the engine cloned all
// four onto every page. The proxy `closeDecoration()` builds per page also
// carried no `outline` at all, so an outline on a box tall enough to split
// disappeared entirely. Both are read off the fragment's own slice flags,
// which is why they are asserted here rather than in the painter.
$tree = layout(
    '<style>.card{border:6pt solid #000;outline:6pt solid #c00;outline-offset:3pt;'
    . 'background:#eee}p{margin:0;font-size:12pt}</style>'
    . '<div class="card">'
    . implode('', array_map(static fn(int $i): string => "<p>line $i</p>", range(1, 60)))
    . '</div>'
);

$slices = [];

foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $page) {
    foreach ($page as $f) {
        if ($f->node->slicedBackground !== null) {
            $slices[] = $f;
        }
    }
}

ok('a split box carries its outline onto every page it reaches',
    count($slices) > 2 && array_all($slices, static fn(object $f): bool => $f->node->outline !== null),
    sprintf('%d slices, %d with an outline', count($slices),
        count(array_filter($slices, static fn(object $f): bool => $f->node->outline !== null))));

$firstOpensTop  = $slices !== [] && !$slices[0]->isContinuation && $slices[0]->splitsAfter;
$lastClosesOnly = $slices !== [] && end($slices)->isContinuation && !end($slices)->splitsAfter;
$middleClosed   = array_all(
    array_slice($slices, 1, -1),
    static fn(object $f): bool => $f->isContinuation && $f->splitsAfter,
);

ok('only the slice the box starts on owns its top edge, and only the last its bottom',
    $firstOpensTop && $lastClosesOnly && $middleClosed && count($slices) > 2,
    sprintf('%d slices: %s', count($slices), json_encode(array_map(
        static fn(object $f): array => [$f->isContinuation, $f->splitsAfter],
        $slices,
    ))));

// 36. `splitContainer`'s walk read the page number *after* `settleCursor()`
// had already turned the page, so a break caused by the gap between two
// children was invisible to the decoration bookkeeping: `closeDecoration()`
// never fired and the container's background, border and outline stopped at
// the first fold. Case 35's children carry no margins, which is the shape the
// bug leaves alone, so the children here are separated by one.
$tree = layout(
    '<style>.card{border:4pt solid #000;outline:2pt solid #c00;background:#1a3a6b;padding:8pt}'
    . 'p{margin:0 0 40pt 0;height:20pt;background:#fff}</style>'
    . '<div class="card">'
    . implode('', array_map(static fn(int $i): string => "<p>line $i</p>", range(1, 12)))
    . '</div>'
);

$pages36  = (new Fragmenter(PAGE_H))->fragment($tree);
$slices36 = [];

foreach ($pages36 as $pi => $page) {
    foreach ($page as $f) {
        if ($f->node->slicedBackground !== null) {
            $slices36[$pi] = $f->node->slicedBackground[0];
        }
    }
}

ok('a container whose children are separated by margins decorates every page it spans',
    count($pages36) > 2 && count($slices36) === count($pages36),
    sprintf('%d of %d pages carry the decoration', count($slices36), count($pages36)));

// The offsets are what the painter maps a background layer through, so a
// decoration that fires on every page and restarts its ramp at every fold is
// still wrong.
$offsets36 = array_values($slices36);
$steps36   = [];

for ($i = 1; $i < count($offsets36); $i++) {
    $steps36[] = $offsets36[$i] - $offsets36[$i - 1];
}

ok('each of those slices starts one page further into the container',
    $steps36 !== [] && array_all($steps36, static fn(float $d): bool => abs($d - PAGE_H) < 1.0),
    sprintf('offsets %s, want steps of %.0f', json_encode($offsets36), PAGE_H));

// 37. `splitContainer` walked a container's children as a list, so items that
// share a top were paginated one after the other instead of beside each other.
// Every item of a flex row line shares one, and so does every item of a
// wrapped column's line, so a three-item row 500pt tall printed on 5 pages
// where Chrome prints 2, with each item alone on its own. The walk groups by
// band now, the way `splitGrid` already did for grid rows and table cells.
$bandPages = static function (string $html): array {
    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout($html)) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background === null) {
                continue;
            }

            $out[$pi] = ($out[$pi] ?? 0) + 1;
        }
    }

    return $out;
};

$rowSheet = '<style>.wrap{display:flex;flex-direction:row;width:380pt;margin:0}'
    . '.wrap>div{height:500pt;width:120pt;background:#1a3a6b;margin:0}</style>';

$rowBands = $bandPages($rowSheet . '<div class="wrap"><div></div><div></div><div></div></div>');

ok('items sharing a top paginate beside each other, not one after the other',
    count($rowBands) === 2 && ($rowBands[0] ?? 0) === 3,
    sprintf('%s, want 3 items on page 0 across 2 pages', json_encode($rowBands)));

// A wrapped column is the same shape one axis over: six 150pt items in a 500pt
// container are two columns of three, so pages hold bands of two.
$colSheet = '<style>.wrap{display:flex;flex-direction:column;flex-wrap:wrap;height:500pt;margin:0}'
    . '.wrap>div{height:150pt;width:120pt;background:#1a3a6b;margin:0}</style>';

$colBands = $bandPages(
    $colSheet
    . '<div class="wrap">' . str_repeat('<div></div>', 6) . '</div>',
);

ok('a wrapped column container puts a band on one page, not each item on its own',
    count($colBands) === 2 && ($colBands[0] ?? 0) === 4 && ($colBands[1] ?? 0) === 2,
    sprintf('%s, want 4 items then 2 across 2 pages', json_encode($colBands)));

// Block flow must be untouched: its children each own their band, and two
// zero-height siblings must not be merged into one.
//
// **Round 18j rewrote this line to Chrome's answer.** It read [2, 2, 1] across
// three pages, which was this engine's own behaviour and not a browser's: five
// 120pt boxes are 600pt of flow and Chrome fits them on **two** 300pt pages by
// slicing the third box at the fold, 60pt on each. Both engines now render
// this markup box for box identically, six fragments over two pages, and
// `docs/harness/probes/K3-fold-siblings.html` is the shape. What the case is
// for is unchanged and still holds: each child owns its own band and no two of
// them are merged.
$blockBands = $bandPages(
    '<style>.s{margin:0}.s>div{height:120pt;background:#1a3a6b;margin:0}</style>'
    . '<div class="s">' . str_repeat('<div></div>', 5) . '</div>',
);

ok('a block container still paginates one child after the last',
    $blockBands === [0 => 3, 1 => 3],
    sprintf('%s, want 3 then 3 across 2 pages', json_encode($blockBands)));

// 38. A box taller than the page turns its pages in `splitContainer`'s
// shortfall top-up, which runs *after* the walk, and only the walk closed the
// decoration. So a 500pt box holding one word painted a single 200pt slice on
// the page it started on and nothing after, where the same box with no children
// at all goes through `emitSlices` and is already correct. Case 36 is the other
// side of the same function: it closes a page the walk itself turned.
$sliceTops = static function (string $html): array {
    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout($html)) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->slicedBackground === null) {
                continue;
            }

            $out[$pi] = $f->node->slicedBackground[0];
        }
    }

    return $out;
};

$tallSheet = '<style>.item{height:500pt;width:120pt;background:#1a3a6b;margin:0}</style>';

$withChild = $sliceTops($tallSheet . '<div><div class="item">one word</div></div>');

ok('a box taller than the page decorates every page it covers, child or not',
    count($withChild) === 2 && abs(($withChild[1] ?? -1.0) - PAGE_H) < 1.0,
    sprintf('%s, want a slice on 2 pages stepping by %.0f', json_encode($withChild), PAGE_H));

// The box the shortfall top-up carries onto page 2 has to be the height the
// page actually holds, not whatever the cursor read on the page it left.
$heights38 = [];

foreach ((new Fragmenter(PAGE_H))->fragment(
    layout($tallSheet . '<div><div class="item">one word</div></div>'),
) as $page) {
    foreach ($page as $f) {
        if ($f->node->slicedBackground !== null) {
            $heights38[] = round($f->h, 2);
        }
    }
}

ok('each slice is as tall as the part of the box that page holds',
    $heights38 === [PAGE_H, 200.0],
    sprintf('%s, want [%.0f, 200]', json_encode($heights38), PAGE_H));

// The control, and it passes on the engine before this fix as well as after:
// an empty box of the same height never reaches `splitContainer`, so whatever
// the fix does to the walk it must leave `emitSlices` alone.
$emptyPages = [];

foreach ((new Fragmenter(PAGE_H))->fragment(
    layout($tallSheet . '<div><div class="item"></div></div>'),
) as $page) {
    foreach ($page as $f) {
        if ($f->node->background !== null) {
            $emptyPages[] = round($f->h, 2);
        }
    }
}

ok('an empty box of the same height still slices into 300 then 200',
    $emptyPages === [PAGE_H, 200.0],
    sprintf('%s, want [%.0f, 200]', json_encode($emptyPages), PAGE_H));

// 39. CSS 2.1 §9.7 makes a float block-level whatever `display` says, so a
// floated span leaves the line and the text wraps beside it. `partition()`
// tested `display === 'inline'` and handed the child to `collectRuns()` before
// anything looked at `float`, so the span stayed on the line and never became a
// box at all. Taller than the page that is a fragmentation bug and not only a
// line-metrics one: the float paginated nowhere, so the same markup written as
// a <div> covered three pages carrying ink on two of them and the <span>
// covered one carrying none.
$floatSlices = static function (string $tag): array {
    $sheet = '<style>.wrap{width:150pt}'
        . '.tall{float:left;width:30pt;height:500pt;background:#1a3a6b;margin:0 3pt 0 0}</style>';
    $body  = '<div class="wrap"><' . $tag . ' class="tall">A</' . $tag . '>'
        . str_repeat('alpha beta gamma delta epsilon ', 20) . '</div>';

    $inked = [];
    $pages = (new Fragmenter(PAGE_H))->fragment(layout($sheet . $body));

    foreach ($pages as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null || $f->node->slicedBackground !== null) {
                $inked[$pi] = true;
            }
        }
    }

    return [count($pages), array_keys($inked)];
};

[$spanPages, $spanInked] = $floatSlices('span');

ok('a floated inline taller than the page paginates like the block it becomes',
    $spanPages === 3 && $spanInked === [0, 1],
    sprintf('%d pages, ink on %s, want 3 and [0,1]', $spanPages, json_encode($spanInked)));

// The control, and it passes on the engine before this fix as well as after:
// the block-level float path is what the span now joins, so whatever
// blockification does it must leave a hand-written floated <div> alone.
[$divPages, $divInked] = $floatSlices('div');

ok('the same markup written as a floated <div> is unchanged',
    $divPages === 3 && $divInked === [0, 1],
    sprintf('%d pages, ink on %s, want 3 and [0,1]', $divPages, json_encode($divInked)));

// 40. A collapsed cell straddles the grid line it shares, so half the rim it
// carries falls outside its own box. On the first page the table's decoration
// draws that line; on a continuation `box-decoration-break: slice` leaves the
// table's top edge undrawn, so the repeated header's own copy is all there is,
// and against the page top its outer half fell off. Chrome draws 6pt of rim at
// the top of every page and the engine drew 3.
$repeatedHeaderTop = static function (string $collapse): array {
    $sheet = '<style>table{border-collapse:' . $collapse . ';border:8pt solid #b71c1c;width:300pt}'
        . 'td,th{border:4pt solid #1a3a6b;padding:2pt}</style>';
    $rows  = str_repeat('<tr><td>row left</td><td>row right</td></tr>', 40);
    $body  = '<table><thead><tr><th>head A</th><th>head B</th></tr></thead><tbody>' . $rows . '</tbody></table>';

    $tops = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout($sheet . $body)) as $pi => $page) {
        if ($pi === 0) {
            continue;
        }

        foreach ($page as $f) {
            if ($f->node->isHeaderRow) {
                $tops[] = round($f->y, 3);
            }
        }
    }

    return $tops;
};

$collapsedTops = $repeatedHeaderTop('collapse');

ok('a repeated header starts below its own rim on every continuation page',
    $collapsedTops !== [] && array_unique($collapsedTops) === [4.0],
    sprintf('%s, want every continuation at 4', json_encode($collapsedTops)));

// The control, and it passes on the engine before this fix as well as after:
// a separate-borders table reserves its whole border inside its own box, so
// there is no half line to make room for and the header stays at the top.
$separateTops = $repeatedHeaderTop('separate');

ok('a separate-borders table repeats its header at the page top, unchanged',
    $separateTops !== [] && array_unique($separateTops) === [0.0],
    sprintf('%s, want every continuation at 0', json_encode($separateTops)));

// 41. A repeating `<thead>` was replayed onto every continuation page however
// tall it was, as long as it fitted, so a header taking most of a page left
// almost no room for rows and the table needed many times the pages its own
// height asks for. Chrome refuses to repeat a header at or over **a quarter of
// the page**, measured exactly: 68pt of a 280pt page repeats on every page and
// 70pt on none, and 58 and 60 of a 240pt one, so it is the fraction and not the
// length. Found by round 18g's row 4, which made some header rows taller and
// took two fuzz documents over the 2,000-page ceiling.
$headerPages = static function (float $headerHeight): array {
    $sheet = '<style>table{border-collapse:collapse;width:300pt}'
        . 'td,th{padding:0;border:0.5pt solid #999;text-align:left;font-weight:normal}'
        . 'thead th{height:' . $headerHeight . 'pt}</style>';
    $rows  = str_repeat('<tr><td>row left</td><td>row right</td></tr>', 200);
    $body  = '<table><thead><tr><th>head A</th><th>head B</th></tr></thead><tbody>' . $rows . '</tbody></table>';

    $pages   = (new Fragmenter(PAGE_H))->fragment(layout($sheet . $body));
    $repeats = 0;

    foreach ($pages as $pi => $page) {
        if ($pi === 0) {
            continue;
        }

        foreach ($page as $f) {
            if ($f->node->isHeaderRow) {
                $repeats++;

                break;
            }
        }
    }

    return [count($pages), $repeats];
};

// 74pt is 24.67% of the 300pt page here, so it still repeats; 80pt is 26.67%
// and it does not. The pair is what pins the rule to the fraction.
[$smallPages, $smallRepeats] = $headerPages(74.0);
[$bigPages, $bigRepeats]     = $headerPages(80.0);

ok('a header under a quarter of the page still repeats on every continuation',
    $smallRepeats === $smallPages - 1 && $smallPages > 1,
    sprintf('%d pages, repeated on %d continuations', $smallPages, $smallRepeats));

ok('a header at or over a quarter of the page is not repeated at all',
    $bigRepeats === 0 && $bigPages < $smallPages,
    sprintf('%d pages, repeated on %d continuations, want 0 and under %d', $bigPages, $bigRepeats, $smallPages));

// 42. A block with no children straddling a fold was moved whole to the next
// page where Chrome slices it, so a document lost a page-worth of room at every
// separator, spacer and coloured band it had. `isSplittable()` called a
// childless box atomic and `emitSlices()` only ever saw one taller than a whole
// page. Chrome's rule is CSS Fragmentation §3.1's: what a childless box
// distributes is its own **content box**, so a replaced element is monolithic
// and a box with no content height has nothing to break inside.
//
// Probes: `docs/harness/probes/K1-fold-empty.html` and
// `K2-fold-empty-edges.html`, eighteen shapes read box for box against Chrome.
$foldSlices = static function (string $style, float $spacer = 279.0): array {
    // The spacer carries no background of its own, so every fragment the walk
    // below collects belongs to the box being probed.
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}.b{margin:0;' . $style . '}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout($sheet . '<div class="s"></div><div class="b"></div>')) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

// 279 + 60 straddles the 300pt fold by 39, and Chrome puts 21 on the first page
// and 39 on the second.
ok('a childless block straddling a fold is sliced, not moved whole',
    $foldSlices('height:60pt;background:#1b7a3a') === [21.0, 39.0],
    sprintf('%s, want [21, 39]', json_encode($foldSlices('height:60pt;background:#1b7a3a'))));

// The border box is what is cut, so 12pt of padding travels with the 60pt of
// content: 21 on the first page and 51 on the second, 72 in total.
ok('the slice is taken from the border box, padding and all',
    $foldSlices('height:60pt;padding:6pt 0;background:#1b7a3a') === [21.0, 51.0],
    sprintf('%s, want [21, 51]', json_encode($foldSlices('height:60pt;padding:6pt 0;background:#1b7a3a'))));

// Two boxes Chrome refuses to break inside, and they are the reason this is a
// rule about the content box rather than about having no children: a box with
// no content height has nothing to distribute, and `break-inside: avoid` says
// so in as many words. Both move whole to the next page.
ok('a childless box with no content height moves whole',
    $foldSlices('height:0;padding:30pt 0;background:#1b7a3a') === [60.0],
    sprintf('%s, want [60]', json_encode($foldSlices('height:0;padding:30pt 0;background:#1b7a3a'))));

ok('`break-inside: avoid` still moves a childless box whole',
    $foldSlices('height:60pt;background:#1b7a3a;break-inside:avoid') === [60.0],
    sprintf('%s, want [60]', json_encode($foldSlices('height:60pt;background:#1b7a3a;break-inside:avoid'))));

// The behaviour that was already right and must stay right: a box taller than
// a whole page still becomes a run of page-sized slices, and it reaches that
// through the same call now that it is reached at every fold.
ok('a childless box taller than a page still slices into a run',
    $foldSlices('height:400pt;background:#1b7a3a') === [21.0, 300.0, 79.0],
    sprintf('%s, want [21, 300, 79]', json_encode($foldSlices('height:400pt;background:#1b7a3a'))));

// The trap this row paid for. A childless box has to be routed to the slice
// run **before** the display-based branches, not after: an empty
// `display: grid` is splittable and has no row bands, so `splitGrid()` walked
// nothing, emitted nothing, and the box vanished from the document instead of
// being moved whole. The first draft of the row did exactly that.
foreach (['grid', 'flex', 'flow-root'] as $mode) {
    ok("an empty `display: {$mode}` box slices at a fold rather than disappearing",
        $foldSlices("display:{$mode};height:60pt;background:#1b7a3a") === [21.0, 39.0],
        sprintf('%s, want [21, 39]', json_encode($foldSlices("display:{$mode};height:60pt;background:#1b7a3a"))));
}

// Defect BI, round 18k. A table row straddling a fold was moved whole where
// Chrome cuts it, cell for cell: `isSplittable()` called a row atomic on
// purpose, and the comment saying that slicing one would cut every cell at the
// same offset described exactly what Chrome does. What Chrome will not do is
// cut a row one of whose cells has nothing it can leave behind, so the offset
// has to be one every cell can break at.
//
// Probes: `docs/harness/probes/M1-fold-row-separate.html` to
// `MK-fold-grid-bg.html`, seventeen shapes read as filled rects out of the two
// content streams.
$rowSlices = static function (string $cells, float $spacer = 279.0, string $rowStyle = ''): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0}'
        . 'td{padding:0;vertical-align:top;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(
        layout($sheet . '<div class="s"></div><table><tr style="' . $rowStyle . '">' . $cells . '</tr></table>'),
    ) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

// Two 60pt cells 21pt above the fold: Chrome puts 21 of both on the first page
// and 39 of both on the second, and the cells are side by side rather than one
// after the other, so the walk has to place them from the same start.
$plain = '<td style="height:60pt;background:#1b7a3a">alpha</td><td style="height:60pt;background:#1b7a3a">beta</td>';

ok('a table row straddling a fold is sliced, cell for cell',
    $rowSlices($plain) === [21.0, 21.0, 39.0, 39.0],
    sprintf('%s, want [21, 21, 39, 39]', json_encode($rowSlices($plain))));

// The rule the row cannot be simplified out of. Five lines of text with room
// for one is not a break Chrome will take: fewer lines fit than `orphans` asks
// for, so nothing of the cell stays and the whole row moves. Slicing it anyway
// paints a band of colour with nothing in it.
$lines = '<td style="background:#1b7a3a">one<br>two<br>three<br>four<br>five</td>'
    . '<td style="background:#1b7a3a">aa<br>bb<br>cc<br>dd<br>ee</td>';

ok('a row whose cells cannot break at the fold moves whole',
    $rowSlices($lines) === [60.0, 60.0],
    sprintf('%s, want [60, 60]', json_encode($rowSlices($lines))));

// The same row with room for three lines is cut, and the first fragment runs to
// the fold rather than stopping at the last line that fitted: Chrome paints
// 39.000 and then 24.000 for 60pt of content.
ok('a row whose cells can break at the fold is cut at the fold',
    $rowSlices($lines, 261.0) === [39.0, 39.0, 24.0, 24.0],
    sprintf('%s, want [39, 39, 24, 24]', json_encode($rowSlices($lines, 261.0))));

// One cell that could be sliced beside one that cannot. The row moves whole:
// the offset has to work for every cell at once, which is what makes this a
// row-level decision rather than a per-cell one.
$mixed = '<td style="height:60pt;background:#1b7a3a"></td>'
    . '<td style="background:#1b7a3a">aa<br>bb<br>cc<br>dd<br>ee</td>';

ok('a row moves whole when only some of its cells could be cut',
    $rowSlices($mixed) === [60.0, 60.0],
    sprintf('%s, want [60, 60]', json_encode($rowSlices($mixed))));

ok('`break-inside: avoid` still moves a table row whole',
    $rowSlices($plain, 279.0, 'break-inside:avoid') === [60.0, 60.0],
    sprintf('%s, want [60, 60]', json_encode($rowSlices($plain, 279.0, 'break-inside:avoid'))));

// A sliced row still paints its own background on both pages. The band walk
// emits its items and nothing else, so without `splitGrid()`'s own decoration
// bookkeeping a `<tr>` with a background loses it completely the moment it is
// cut, which is content leaving the paper rather than moving on it.
$rowBg = static function (): array {
    $sheet = '<style>.s{height:279pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0}'
        . 'td{padding:0;vertical-align:top;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        $sheet . '<div class="s"></div><table><tr style="background:#8b1a1a">'
        . '<td style="height:60pt">alpha</td><td style="height:60pt">beta</td></tr></table>',
    )) as $page) {
        foreach ($page as $f) {
            if ($f->node->display === 'rect' && $f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

ok('a sliced table row keeps its own background on both pages',
    $rowBg() === [21.0, 39.0],
    sprintf('%s, want [21, 39]', json_encode($rowBg())));

// Defect BO, round 18l. splitContainer() opened its decoration at the cursor
// before it knew whether any child would stay, so a box whose content cannot
// break at the fold painted a band of its own background on the page it left
// and then the whole box on the next: 21.000 then 60.000, 81pt of background
// for a 60pt box, where Chrome paints 60.000 once. It is the question defect
// BI answers for a table row, asked of a plain block, and it is asked before
// splitContainer() is entered rather than inside isSplittable(), because a box
// taller than any page still has to be split. It just starts on the page it
// can be split on.
//
// Probes: `docs/harness/probes/MI-fold-block-lines-control.html` and
// `N1-fold-block-nobreak-border.html` to `NG-fold-block-bigpad-tall.html`.
$blockSlices = static function (
    string $style,
    float $spacer = 279.0,
    string $content = 'one<br>two<br>three<br>four<br>five',
): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . '.b{width:400pt;margin:0;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(
        layout($sheet . '<div class="s"></div><div class="b" style="' . $style . '">' . $content . '</div>'),
    ) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

// Five 12pt lines with room for one. Fewer fit than `orphans` asks for, so
// nothing of the text stays and Chrome moves the box whole, background and all.
ok('a block whose lines cannot break at the fold paints one band, not two',
    $blockSlices('background:#1b7a3a') === [60.0],
    sprintf('%s, want [60]', json_encode($blockSlices('background:#1b7a3a'))));

// The control that says only the no-break case was wrong: with room for three
// lines the box is cut at the fold and both engines already agreed.
ok('a block whose lines can break at the fold is still cut at the fold',
    $blockSlices('background:#1b7a3a', 261.0) === [39.0, 24.0],
    sprintf('%s, want [39, 24]', json_encode($blockSlices('background:#1b7a3a', 261.0))));

// The padding and the border travel with a box that moves whole. Painting the
// sliver had spent the top edge on the page the box left, so the continuation
// was 66.000 for a 72pt box and 6pt of padding was simply gone.
ok('a block that moves whole keeps its own padding',
    $blockSlices('background:#1b7a3a;padding:6pt') === [72.0],
    sprintf('%s, want [72]', json_encode($blockSlices('background:#1b7a3a;padding:6pt'))));

// Taller than any page, so it cannot move whole anywhere, but it still starts
// on the page it can be cut on. splitText() applies the orphan rule before it
// splits, whatever the box's total height, which is why landsHere() answers a
// text box out of its own lines rather than out of its height.
$lines30 = implode('<br>', array_map(static fn(int $i): string => 'l' . $i, range(1, 30)));

ok('a block taller than a page starts on the page it can be cut on',
    $blockSlices('background:#1b7a3a', 279.0, $lines30) === [300.0, 60.0],
    sprintf('%s, want [300, 60]', json_encode($blockSlices('background:#1b7a3a', 279.0, $lines30))));

// A box whose own padding overruns the fold has no break opportunity above it
// at all, and Chrome moves it whole when it fits on the next page:
// `NB-fold-block-bigpad.html` is 192.000 once.
ok('a block whose padding overruns the fold moves whole',
    $blockSlices('background:#1b7a3a;padding-top:180pt', 150.0, 'alpha') === [192.0],
    sprintf('%s, want [192]', json_encode(
        $blockSlices('background:#1b7a3a;padding-top:180pt', 150.0, 'alpha'),
    )));

// A column flex container reaches the same code, so it gets the same answer.
ok('a column flex container that cannot break at the fold paints one band',
    $blockSlices('display:flex;flex-direction:column;background:#1b7a3a') === [60.0],
    sprintf('%s, want [60]', json_encode(
        $blockSlices('display:flex;flex-direction:column;background:#1b7a3a'),
    )));

// The control that must not move, and the one this could have broken. When the
// first child DOES stay, the band of background above the fold is the box's
// real extent and Chrome paints it: 21.000 with the 12pt child inside it, then
// 60.000 on the next page. Suppressing the sliver unconditionally would have
// deleted this one too.
$firstStays = static function (): array {
    $sheet = '<style>.s{height:279pt;margin:0}'
        . '.b{width:400pt;margin:0;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        $sheet . '<div class="s"></div><div class="b" style="background:#1b7a3a">'
        . '<div style="height:12pt;background:#c8a415"></div>'
        . '<div>one<br>two<br>three<br>four<br>five</div></div>',
    )) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

ok('a block whose first child stays still opens its decoration at the fold',
    $firstStays() === [21.0, 12.0, 60.0],
    sprintf('%s, want [21, 12, 60]', json_encode($firstStays())));

// Defect BP, round 18l. A `display: grid` at a fold was two defects in one
// box. splitGrid() moved a whole row band where Chrome slices it, and it
// painted no decoration of its own on ANY page (not the one it left and not
// the one it landed on), so a grid with a background lost it completely the
// moment it reached a fold. Round 18k could only give the decoration
// bookkeeping to a table row, because a band that moves rather than slicing
// would have painted defect BO's empty sliver; with BO answered and a
// breakable band sliced rather than moved, it is every band-walked box's.
//
// Probes: `docs/harness/probes/MK-fold-grid-bg.html` and
// `P1-fold-grid-bg-room.html` to `PA-fold-grid-itembg.html`.
$gridSlices = static function (string $items, float $spacer = 279.0, string $gridStyle = ''): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . '.g{display:grid;grid-template-columns:1fr 1fr;width:400pt;margin:0;'
        . 'background:#8b1a1a;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(
        layout($sheet . '<div class="s"></div><div class="g" style="' . $gridStyle . '">' . $items . '</div>'),
    ) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

$gridCells = '<div style="height:60pt">alpha</div><div style="height:60pt">beta</div>';
$gridLines = '<div>one<br>two<br>three<br>four<br>five</div>'
    . '<div>aa<br>bb<br>cc<br>dd<br>ee</div>';

// The recorded shape. Chrome cuts the band 21.000 / 39.000, background and
// all; this moved the band AND painted the background nowhere.
ok('a grid straddling a fold is sliced and keeps its background on both pages',
    $gridSlices($gridCells) === [21.0, 39.0],
    sprintf('%s, want [21, 39]', json_encode($gridSlices($gridCells))));

ok('a grid sliced further down the page is cut at the fold too',
    $gridSlices($gridCells, 261.0) === [39.0, 21.0],
    sprintf('%s, want [39, 21]', json_encode($gridSlices($gridCells, 261.0))));

// The band is refused on exactly a table row's terms: five lines with room for
// one is fewer than `orphans` asks for, so the band moves, and the background
// moves with it rather than staying behind or vanishing.
ok('a grid whose items cannot break at the fold moves whole, background and all',
    $gridSlices($gridLines) === [60.0],
    sprintf('%s, want [60]', json_encode($gridSlices($gridLines))));

// One item that could be sliced beside one that cannot. The band moves whole:
// a band is cut at one offset, so the offset has to work for every item at
// once, which is what makes this a band-level decision rather than a per-item
// one. It is `MG-fold-row-mixed.html`'s rule read on a grid.
$gridMixed = '<div style="height:60pt"></div><div>aa<br>bb<br>cc<br>dd<br>ee</div>';

ok('a grid moves its band whole when only some of its items could be cut',
    $gridSlices($gridMixed) === [60.0],
    sprintf('%s, want [60]', json_encode($gridSlices($gridMixed))));

// Taller than any page, which is the one case that always reached the band
// walk. It was the case that hid the missing decoration for so long, and it
// paints on all three pages now.
$gridTall = '<div style="height:360pt">alpha</div><div style="height:360pt">beta</div>';

ok('a grid taller than a page keeps its background on every page it crosses',
    $gridSlices($gridTall) === [21.0, 300.0, 39.0],
    sprintf('%s, want [21, 300, 39]', json_encode($gridSlices($gridTall))));

// The fold between two bands rather than inside one: the first band stays, the
// second is cut, and the decoration closes at the fold and reopens.
$gridTwo = '<div style="height:24pt">a</div><div style="height:24pt">b</div>'
    . '<div style="height:60pt">c</div><div style="height:60pt">d</div>';

ok('a grid with the fold between two bands closes its decoration at the fold',
    $gridSlices($gridTwo, 264.0) === [36.0, 48.0],
    sprintf('%s, want [36, 48]', json_encode($gridSlices($gridTwo, 264.0))));

ok('`break-inside: avoid` still moves a grid whole',
    $gridSlices($gridCells, 279.0, 'break-inside:avoid') === [60.0],
    sprintf('%s, want [60]', json_encode($gridSlices($gridCells, 279.0, 'break-inside:avoid'))));

// The control that must not move: a grid nowhere near a fold never reaches
// splitGrid() at all.
ok('a grid that reaches no fold is painted once',
    $gridSlices($gridCells, 75.0) === [60.0],
    sprintf('%s, want [60]', json_encode($gridSlices($gridCells, 75.0))));

// Defect BR, round 18m. A row flex container straddling a fold was moved whole
// where Chrome cuts it, and `isSplittable()` refused it on purpose:
// `forceSplitFlexLines` was a flag rather than a rule, so the only way to cut
// one was to ask for it. Chrome cuts a flex line exactly as it cuts a table row
// and a grid row, and refuses on exactly the same terms, so the flag stays as
// an override and the rule is the band's.
//
// Probes: `docs/harness/probes/PB-fold-flexrow-bg.html` and
// `Q1-fold-flexrow-room.html` to `QD-fold-flexcol-bands.html`.
$flexSlices = static function (string $items, float $spacer = 279.0, string $style = ''): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . '.f{display:flex;width:400pt;margin:0;background:#8b1a1a;line-height:12pt}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(
        layout($sheet . '<div class="s"></div><div class="f" style="' . $style . '">' . $items . '</div>'),
    ) as $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = round($f->h, 3);
            }
        }
    }

    return $out;
};

$flexCells = '<div style="height:60pt;width:200pt">alpha</div>'
    . '<div style="height:60pt;width:200pt">beta</div>';
$flexLines = '<div style="width:200pt">one<br>two<br>three<br>four<br>five</div>'
    . '<div style="width:200pt">aa<br>bb<br>cc<br>dd<br>ee</div>';

ok('a row flex container straddling a fold is sliced, item for item',
    $flexSlices($flexCells) === [21.0, 39.0],
    sprintf('%s, want [21, 39]', json_encode($flexSlices($flexCells))));

ok('a row flex container sliced further down the page is cut at the fold too',
    $flexSlices($flexCells, 261.0) === [39.0, 21.0],
    sprintf('%s, want [39, 21]', json_encode($flexSlices($flexCells, 261.0))));

// Refused on a table row's terms: fewer lines fit than `orphans` asks for, so
// nothing of either item stays and the whole line moves.
ok('a flex line whose items cannot break at the fold moves whole',
    $flexSlices($flexLines) === [60.0],
    sprintf('%s, want [60]', json_encode($flexSlices($flexLines))));

// One item that could be sliced beside one that cannot: the offset has to work
// for every item at once, so the line moves. `MG` and `P3` are the same shape
// on a table row and on a grid.
$flexMixed = '<div style="height:60pt;width:200pt"></div>'
    . '<div style="width:200pt">aa<br>bb<br>cc<br>dd<br>ee</div>';

ok('a flex line moves whole when only some of its items could be cut',
    $flexSlices($flexMixed) === [60.0],
    sprintf('%s, want [60]', json_encode($flexSlices($flexMixed))));

// Items of different heights are still one line, so they are still one band.
$flexUneven = '<div style="height:60pt;width:200pt">alpha</div>'
    . '<div style="height:15pt;width:200pt">beta</div>';

ok('a flex line of uneven items is cut at one offset through both',
    $flexSlices($flexUneven, 279.0, 'align-items:flex-start') === [21.0, 39.0],
    sprintf('%s, want [21, 39]', json_encode(
        $flexSlices($flexUneven, 279.0, 'align-items:flex-start'),
    )));

// The control that says the machinery was already there: a container taller
// than any page has always been fragmented, because there is no page it would
// fit on. What this defect does is stop that being the only case that reaches
// it.
$flexTall = '<div style="height:360pt;width:200pt">alpha</div>'
    . '<div style="height:360pt;width:200pt">beta</div>';

ok('a row flex container taller than a page still slices on every page',
    $flexSlices($flexTall) === [21.0, 300.0, 39.0],
    sprintf('%s, want [21, 300, 39]', json_encode($flexSlices($flexTall))));

ok('`break-inside: avoid` still moves a row flex container whole',
    $flexSlices($flexCells, 279.0, 'break-inside:avoid') === [60.0],
    sprintf('%s, want [60]', json_encode($flexSlices($flexCells, 279.0, 'break-inside:avoid'))));

// Controls that must not move: a container nowhere near a fold, and a column
// container, whose bands were already walked one after the other.
ok('a row flex container that reaches no fold is painted once',
    $flexSlices($flexCells, 75.0) === [60.0],
    sprintf('%s, want [60]', json_encode($flexSlices($flexCells, 75.0))));

$flexCol = '<div style="height:18pt;width:400pt">a</div><div style="height:42pt;width:400pt">b</div>';

ok('a column flex container still breaks between its own bands',
    $flexSlices($flexCol, 279.0, 'flex-direction:column') === [21.0, 42.0],
    sprintf('%s, want [21, 42]', json_encode($flexSlices($flexCol, 279.0, 'flex-direction:column'))));

// Defect BQ, round 18m. A repeating `<thead>` was placed at the foot of a page
// whose first body row could not follow it, and then repeated at the top of the
// next: a header with nothing under it, and the same header twice. Chrome
// places a header and the row it heads together or not at all, so the question
// the fold asks everywhere else is asked of the band AFTER the header, because
// a header's reason to be on a page is the row it heads.
//
// Probes: `docs/harness/probes/R1-fold-thead-stranded.html` to
// `R8-fold-thead-tall-control.html`, and `M9-fold-row-thead.html`, which round
// 18k recorded the defect on.
$theadBands = static function (string $body, float $spacer, string $headStyle = 'height:15pt'): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0}'
        . 'td,th{padding:0;vertical-align:top;line-height:12pt}'
        . 'th{background:#8b1a1a}td{background:#1b7a3a}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        $sheet . '<div class="s"></div><table><thead><tr>'
        . '<th style="' . $headStyle . '">h1</th><th style="' . $headStyle . '">h2</th>'
        . '</tr></thead><tbody>' . $body . '</tbody></table>',
    )) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = sprintf('%d@%.0f+%.0f', $pi, $f->y, $f->h);
            }
        }
    }

    return $out;
};

$theadRow = '<tr><td style="height:60pt">alpha</td><td style="height:60pt">beta</td></tr>';

// 21pt of room: the header fits and its row cannot follow it, so Chrome paints
// nothing at all on that page and starts the table on the next one.
ok('a repeating header is not left at the foot of a page its row cannot reach',
    $theadBands($theadRow, 279.0) === ['1@0+15', '1@0+15', '1@15+60', '1@15+60'],
    sprintf('%s, want the header and its row together on page 1', json_encode($theadBands($theadRow, 279.0))));

// The fold falling exactly under the header is the same answer, and it is the
// shape that says the rule is about the row rather than about the header's own
// fit: the header fits to the point.
ok('a header that fits exactly still moves when its row cannot follow',
    $theadBands($theadRow, 285.0) === ['1@0+15', '1@0+15', '1@15+60', '1@15+60'],
    sprintf('%s, want the header and its row together on page 1', json_encode($theadBands($theadRow, 285.0))));

// A header too tall for the room moves with its row rather than being sliced,
// which is what Chrome does with `R8-fold-thead-tall-control.html`.
$theadTwoRows = $theadRow . '<tr><td style="height:150pt">c</td><td style="height:150pt">d</td></tr>';

ok('a header taller than the room moves whole with its rows',
    $theadBands($theadTwoRows, 279.0, 'height:90pt')
        === ['1@0+90', '1@0+90', '1@90+60', '1@90+60', '1@150+150', '1@150+150'],
    sprintf('%s, want the whole table on page 1', json_encode($theadBands($theadTwoRows, 279.0, 'height:90pt'))));

// The control that must not move: a table nowhere near a fold is untouched, and
// so is one whose row can still be cut under its header.
ok('a header nowhere near a fold is placed where it always was',
    $theadBands($theadRow, 75.0) === ['0@75+15', '0@75+15', '0@90+60', '0@90+60'],
    sprintf('%s, want [0@75+15 x2, 0@90+60 x2]', json_encode($theadBands($theadRow, 75.0))));

// Defect BU, round 18n. A repeating `<thead>` above a table row that gets
// SLICED at the fold was replayed once per cell of that row, each copy at that
// cell's own x, so a two-cell row printed the header twice and a three-cell row
// three times and the copies past the first ran off the right edge of the page.
// The row's continuation was then painted over them rather than under them. It
// arrived with defect BI in round 18k and no `M` probe reaches it, because none
// of them slices a row that has a repeating header above it.
//
// Probes: `docs/harness/probes/R2-fold-thead-row-slices.html`, `R3`, `R4`, and
// `S1-fold-thead-indent.html` to `S6-fold-nofold-control.html`.
//
// Cells are keyed by their `id` rather than by x, because the column widths are
// the text's business and the header's x is the defect's.
$theadCells = static function (
    string $head,
    string $body,
    float $spacer,
    string $extra = '',
): array {
    $sheet = '<style>.s{height:' . $spacer . 'pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0}'
        . 'td,th{padding:0;vertical-align:top;line-height:12pt}'
        . 'th{background:#8b1a1a}td{background:#1b7a3a}' . $extra . '</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        $sheet . '<div class="s"></div><table id="t"><thead><tr>' . $head
        . '</tr></thead><tbody>' . $body . '</tbody></table>',
    )) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background === null || $f->node->anchorId === '') {
                continue;
            }

            $out[] = [
                'id'   => $f->node->anchorId,
                'bg'   => $f->node->background,
                'page' => $pi,
                'x'    => round($f->x, 3),
                'y'    => round($f->y, 3),
                'h'    => round($f->h, 3),
            ];
        }
    }

    return $out;
};

/**
 * Every fragment of one cell, as `page@y+h`, in page order.
 *
 * A box the fold cuts is painted through the proxy {@see Fragmenter} builds for
 * it, which carries the box's colours and not its `id`, so a sliced cell is
 * matched by the background it was given instead. The header is never sliced
 * here, so it keeps its own.
 */
$cellBands = static function (array $cells, string $id, ?array $bg = null): array {
    $out = [];

    foreach ($cells as $c) {
        if ($bg === null ? $c['id'] === $id : $c['bg'] === $bg) {
            $out[] = sprintf('%d@%.0f+%.0f', $c['page'], $c['y'], $c['h']);
        }
    }

    sort($out);

    return $out;
};

/** The background a named box was resolved to, read off a layout that has it. */
$bgOf = static function (array $cells, string $id): ?array {
    foreach ($cells as $c) {
        if ($c['id'] === $id) {
            return $c['bg'];
        }
    }

    return null;
};

$twoHeads  = '<th id="h1" style="height:15pt">h1</th><th id="h2" style="height:15pt">h2</th>';
$slicedRow = '<tr><td id="d1" style="height:60pt">alpha</td>'
    . '<td id="d2" style="height:60pt">beta</td></tr>';

// The two colours the sliced boxes are matched by, read off a layout that
// reaches no fold and so paints every box through its own node.
$flat    = $theadCells($twoHeads, $slicedRow, 75.0, 'table{background:#1a4b8b}');
$cellBg  = $bgOf($flat, 'd1');
$tableBg = $bgOf($flat, 't');

$sliced = $theadCells($twoHeads, $slicedRow, 240.0);

// The recorded shape. Chrome paints one header per page at the table's x:
// 15.000 of header then 45.000 of row, then 15.000 of header and 15.000 of the
// row's continuation. This painted the header FOUR times and put the row's
// continuation at y 0.000 over the top of them.
ok('a repeating header above a sliced row is replayed once, not once per cell',
    $cellBands($sliced, 'h1') === ['0@240+15', '1@0+15']
        && $cellBands($sliced, 'h2') === ['0@240+15', '1@0+15'],
    sprintf('h1 %s h2 %s', json_encode($cellBands($sliced, 'h1')), json_encode($cellBands($sliced, 'h2'))));

// The continuation lands UNDER the header rather than over it: 15.000 of row at
// y 15.000, where this painted 30.000 at y 0.000 across both header copies.
ok('a sliced row resumes below the header it was replayed under',
    $cellBands($sliced, '', $cellBg) === ['0@255+45', '0@255+45', '1@15+15', '1@15+15'],
    sprintf('%s, want both cells 45.000 then 15.000 at y 15.000',
        json_encode($cellBands($sliced, '', $cellBg))));

// A replayed header goes at the header's own x, not at the x of whichever cell
// turned the page. Every copy has to line up with the one on the first page,
// and the copies that did not ran clear off the right edge of the paper.
$headX = static function (array $cells, string $id): array {
    $out = [];

    foreach ($cells as $c) {
        if ($c['id'] === $id) {
            $out[$c['page']] = $c['x'];
        }
    }

    return $out;
};

ok('every copy of a repeating header sits at the header\'s own x',
    $headX($sliced, 'h1') === [0 => $headX($sliced, 'h1')[0], 1 => $headX($sliced, 'h1')[0]]
        && $headX($sliced, 'h2') === [0 => $headX($sliced, 'h2')[0], 1 => $headX($sliced, 'h2')[0]]
        && max($headX($sliced, 'h2')) < PAGE_W,
    sprintf('h1 %s h2 %s against a %.0fpt page',
        json_encode($headX($sliced, 'h1')), json_encode($headX($sliced, 'h2')), PAGE_W));

// A three-cell row is what says the copies were one per cell rather than simply
// doubled: it printed the header three times, the last beginning at 488.411.
$threeCell = '<tr><td id="d1" style="height:60pt">a</td><td id="d2" style="height:60pt">b</td>'
    . '<td id="d3" style="height:60pt">c</td></tr>';
$three     = $theadCells($twoHeads, $threeCell, 240.0);

ok('a three-cell sliced row still replays its header exactly once',
    $cellBands($three, 'h1') === ['0@240+15', '1@0+15']
        && $cellBands($three, '', $cellBg)
            === ['0@255+45', '0@255+45', '0@255+45', '1@15+15', '1@15+15', '1@15+15'],
    sprintf('h1 %s cells %s',
        json_encode($cellBands($three, 'h1')), json_encode($cellBands($three, '', $cellBg))));

// A row taller than a page crosses several folds, and the header belongs to
// every page it reaches: once per page is the rule, not once per document.
$tallRow = '<tr><td id="d1" style="height:600pt">alpha</td>'
    . '<td id="d2" style="height:600pt">beta</td></tr>';
$tall    = $theadCells($twoHeads, $tallRow, 240.0);

ok('a repeating header is replayed on every page a tall row crosses',
    $cellBands($tall, 'h1') === ['0@240+15', '1@0+15', '2@0+15']
        && $cellBands($tall, '', $cellBg)
            === ['0@255+45', '0@255+45', '1@15+285', '1@15+285', '2@15+270', '2@15+270'],
    sprintf('h1 %s cells %s',
        json_encode($cellBands($tall, 'h1')), json_encode($cellBands($tall, '', $cellBg))));

// A box under the header resumes below it; the box that HOLDS the header runs
// behind it. The table's own background covers 30.000 on the continuation page,
// one header plus the row's continuation, which is Chrome's answer on
// `S2-fold-thead-tablebg.html`. Reopening every continuation at the page floor
// would have pushed the table's own background down off its own header.
$withBg = $theadCells($twoHeads, $slicedRow, 240.0, 'table{background:#1a4b8b}');

ok('a table background runs behind its own repeated header',
    $cellBands($withBg, '', $tableBg) === ['0@240+60', '1@0+30'],
    sprintf('%s, want [0@240+60, 1@0+30]', json_encode($cellBands($withBg, '', $tableBg))));

// The control that must not move: a table nowhere near a fold, where nothing is
// replayed at all.
$nofold = $theadCells($twoHeads, $slicedRow, 75.0);

ok('a sliced-row table nowhere near a fold is placed where it always was',
    $cellBands($nofold, 'h1') === ['0@75+15'] && $cellBands($nofold, 'd1') === ['0@90+60'],
    sprintf('h1 %s d1 %s',
        json_encode($cellBands($nofold, 'h1')), json_encode($cellBands($nofold, 'd1'))));

// Defect BW, round 18o. A repeating `<tfoot>` was painted at the bottom of a
// page whose row could not follow it, which is defect BQ's shape at the other
// end of the table, and it was pinned to the reserved band on every page rather
// than sitting under the last row on the page the table ends on. Probing it
// found two more in the same band: a row cut by the reserve painted straight
// through it, and the quarter-page refusal that governs both a header and a
// footer excluded its own boundary.
//
// Probes: `docs/harness/probes/R6-fold-tfoot.html` and `T1-tfoot-multipage.html`
// to `T8-tfoot-quarter-page.html`.
$footRects = static function (string $body, string $footHeight = '15pt', string $lead = ''): array {
    $sheet = '<style>.s{height:1pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0}'
        . 'td,th{padding:0;vertical-align:top;line-height:12pt}'
        . 'th{background:#8b1a1a}td{background:#1b7a3a}</style>';

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        $sheet . $lead . '<table id="t"><tfoot><tr>'
        . '<th id="f0" style="height:' . $footHeight . '">f</th>'
        . '</tr></tfoot><tbody>' . $body . '</tbody></table>',
    )) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background === null) {
                continue;
            }

            $out[] = [
                // A box the fold cuts is painted through a proxy that carries
                // the colours and not the id, so the id is absent rather than
                // empty on exactly the fragments this row is about.
                'id'   => $f->node->anchorId ?? '',
                'bg'   => $f->node->background,
                'page' => $pi,
                'y'    => round($f->y, 3),
                'h'    => round($f->h, 3),
            ];
        }
    }

    return $out;
};

$footBands = static function (array $rows, string $id, ?array $bg = null): array {
    $out = [];

    foreach ($rows as $r) {
        if ($bg === null ? $r['id'] === $id : $r['bg'] === $bg) {
            $out[] = sprintf('%d@%.0f+%.0f', $r['page'], $r['y'], $r['h']);
        }
    }

    sort($out);

    return $out;
};

$row100 = static fn(int $n): string => implode('', array_map(
    static fn(int $i): string => '<tr><td style="height:75pt">r' . $i . '</td></tr>',
    range(1, $n),
));

// The recorded shape: 279pt of lead leaves the single row unable to follow its
// footer, so Chrome paints NOTHING on that page and starts the table on the
// next. This painted a footer at the page bottom with no row over it, and then
// the footer again on the page the row moved to.
$stranded = $footRects('<tr><td style="height:60pt">alpha</td></tr>', '15pt',
    '<div style="height:279pt;margin:0"></div>');

ok('a repeating footer is not left at the foot of a page its row cannot reach',
    $footBands($stranded, 'f0') === ['1@60+15'],
    sprintf('%s, want the footer once, under its row on page 1',
        json_encode($footBands($stranded, 'f0'))));

// The footer sits at the bottom of the table's fragment on each page: the
// reserved band on a page the table fills, and directly under the last row on
// the page it ends on. This pinned both to 285.000.
$multi = $footRects($row100(6));

ok('a repeating footer sits under the last row on the page the table ends on',
    $footBands($multi, 'f0') === ['0@285+15', '1@165+15'],
    sprintf('%s, want [0@285+15, 1@165+15]', json_encode($footBands($multi, 'f0'))));

// A row the reserved band cuts stops at the band. The fourth row painted
// 75.000 straight over its own footer where Chrome cuts it at 60.000.
$bodyBg = null;

foreach ($multi as $r) {
    if ($r['id'] === '') {
        $bodyBg = $r['bg'];

        break;
    }
}

ok('a row cut by the footer band stops at the band',
    in_array('0@225+60', $footBands($multi, '', $bodyBg), true)
        && !in_array('0@225+75', $footBands($multi, '', $bodyBg), true),
    sprintf('%s, want the fourth row 0@225+60', json_encode($footBands($multi, '', $bodyBg))));

// A footer over a quarter of the page is refused and placed once, like any
// row, exactly as a header over a quarter is. Reserving a band that big on
// every page turns a table needing two pages into one needing many.
$tallFoot = $footRects('<tr><td style="height:60pt">alpha</td></tr>', '255pt',
    '<div style="height:279pt;margin:0"></div>');

ok('a footer over a quarter of the page is placed once rather than repeated',
    count($footBands($tallFoot, 'f0')) === 1,
    sprintf('%s, want one footer', json_encode($footBands($tallFoot, 'f0'))));

// And the boundary itself is repeated, at both ends of the table. Chrome
// repeats a 75.000pt run on a 300pt page and refuses a 75.750pt one; round 18g
// read the test as "at or over" from a 280pt page, whose quarter cannot be
// written in whole CSS pixels.
$quarter = $footRects($row100(6), '75pt');
$over    = $footRects($row100(6), '75.75pt');

ok('a repeating run at exactly a quarter of the page is still repeated',
    count($footBands($quarter, 'f0')) > 1 && count($footBands($over, 'f0')) === 1,
    sprintf('at the quarter %s, over it %s',
        json_encode($footBands($quarter, 'f0')), json_encode($footBands($over, 'f0'))));

// The control that must not move: a table small enough never to be split does
// not go through the footer band at all.
$whole = $footRects('<tr><td style="height:60pt">alpha</td></tr>');

ok('a table that never splits places its footer as an ordinary row',
    $footBands($whole, 'f0') === ['0@60+15'],
    sprintf('%s, want [0@60+15]', json_encode($footBands($whole, 'f0'))));

// Defect BL, round 18p: a declared `height` on a table that HAS rows was read
// nowhere. CSS 2.1 §17.5.3 makes it a minimum for the table box and hands the
// surplus to the rows, and Chrome shares it out in two passes: between the
// sections first, then between the rows of each. Every number below is
// Chrome's off the `W1` to `W9` probes.
$tallRows = static function (string $table): array {
    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null) {
            $out[$n->anchorId] = round($n->layoutHeight, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };

    $walk(layout(
        '<style>table{border-spacing:0;margin:0}'
        . 'td,th{padding:0;vertical-align:top;line-height:12pt}</style>' . $table,
    ));

    return $out;
};

// `W1-table-height-rows.html` `w1`: a 150pt table around one 12pt line is
// 150.000 with the cell stretched to it, where the declaration was read
// nowhere and the table was 12.000.
$oneRow = $tallRows('<table id="t" style="width:90pt;height:150pt">'
    . '<tr id="r0"><td id="c0">alpha</td></tr></table>');

ok('a declared height on a table with rows reaches the rows',
    [$oneRow['t'], $oneRow['r0'], $oneRow['c0']] === [150.0, 150.0, 150.0],
    sprintf('%s, want 150 / 150 / 150', json_encode($oneRow)));

// `W1` `w4`: 12pt and 36pt of content on a 150pt table are 37.500 and
// 112.500, which is proportional. An equal split of the surplus gives 63 / 87.
$share = $tallRows('<table id="t" style="width:90pt;height:150pt">'
    . '<tr id="r0"><td>alpha</td></tr><tr id="r1"><td>b<br>b<br>b</td></tr></table>');

ok('the surplus is shared in proportion to what each row already has',
    [$share['r0'], $share['r1']] === [37.5, 112.5],
    sprintf('%s, want 37.5 / 112.5', json_encode($share)));

// `W2-table-height-share.html` `x4`: the header keeps its 12.000 and the body
// takes all 114 of the surplus.
$header = $tallRows('<table id="t" style="width:90pt;height:150pt">'
    . '<thead><tr id="h0"><th>head</th></tr></thead>'
    . '<tbody><tr id="r0"><td>alpha</td></tr><tr id="r1"><td>beta</td></tr></tbody></table>');

ok('a repeating header does not take the surplus while there is a body to take it',
    [$header['h0'], $header['r0'], $header['r1']] === [12.0, 69.0, 69.0],
    sprintf('%s, want 12 / 69 / 69', json_encode($header)));

// `W5-table-height-sections.html` `a4`: and a table whose only section IS the
// header gives the header all of it, so the rule is a preference rather than a
// ban.
$headerOnly = $tallRows('<table id="t" style="width:90pt;height:150pt">'
    . '<thead><tr id="h0"><th>head</th></tr></thead></table>');

ok('a table whose only section is its header gives the header the surplus',
    $headerOnly['h0'] === 150.0,
    sprintf('%s, want 150', json_encode($headerOnly)));

// `W5` `a1`: a row a cell pinned with a length keeps the length, and the rows
// either side of it share what is left in proportion. Sharing over all three
// gives 140.6 / 28.1 / 56.3.
$pinned = $tallRows('<table id="t" style="width:90pt;height:240pt">'
    . '<tr id="r0"><td style="height:60pt">alpha</td></tr>'
    . '<tr id="r1"><td>beta</td></tr>'
    . '<tr id="r2"><td>g<br>g</td></tr></table>');

ok('a row a cell pinned with a length keeps the length',
    [$pinned['r0'], $pinned['r1'], $pinned['r2']] === [60.0, 60.0, 120.0],
    sprintf('%s, want 60 / 60 / 120', json_encode($pinned)));

// `W6-table-height-more.html` `b1`: the split is between the SECTIONS before
// it is between the rows. One flat pool of rows gives 60 / 54 / 54.
$sections = $tallRows('<table id="t" style="width:90pt;height:168pt">'
    . '<tbody><tr id="r0"><td style="height:60pt">alpha</td></tr>'
    . '<tr id="r1"><td>beta</td></tr></tbody>'
    . '<tbody><tr id="r2"><td>gamma</td></tr></tbody></table>');

ok('the surplus is split between the row groups before it is split between rows',
    [$sections['r0'], $sections['r1'], $sections['r2']] === [60.0, 84.0, 24.0],
    sprintf('%s, want 60 / 84 / 24', json_encode($sections)));

// The control that must not move: a declared height UNDER the rows is lost,
// which is why this only ever adds. `W1` `w5`.
$short = $tallRows('<table id="t" style="width:90pt;height:15pt">'
    . '<tr id="r0"><td>alpha</td></tr><tr id="r1"><td>beta</td></tr></table>');

ok('a declared height under the rows is lost',
    [$short['t'], $short['r0'], $short['r1']] === [24.0, 12.0, 12.0],
    sprintf('%s, want 24 / 12 / 12', json_encode($short)));

// `W7-fold-table-height.html`: the stretched rows are what the fold cuts, so a
// table that fitted on one page at 12pt a row now crosses onto the next.
$foldTall = static function (): array {
    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        '<style>table{border-spacing:0;width:400pt;margin:0}'
        . 'td{padding:0;vertical-align:top;line-height:12pt;background:#1b7a3a}</style>'
        . '<div style="height:279pt;margin:0"></div>'
        . '<table style="height:150pt"><tr><td>alpha</td></tr><tr><td>beta</td></tr></table>',
    )) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background === null) {
                continue;
            }

            $out[] = sprintf('%d@%.0f+%.0f', $pi, $f->y, $f->h);
        }
    }

    sort($out);

    return $out;
};

ok('a fold cuts the rows the declared height stretched',
    $foldTall() === ['0@279+21', '1@0+54', '1@54+75'],
    sprintf('%s, want [0@279+21, 1@0+54, 1@54+75]', json_encode($foldTall())));

// Defect CA: an undeclared `<td>` centres its content in its row, which is
// HTML's rendering sheet rather than this engine's old `top`. Each shape is
// one table, so the returned y is the offset into the 36pt row: `middle` is
// 12.000 and `bottom` 24.000 for a 12pt line.
$cellY = static function (string $body, string $extra = ''): array {
    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null) {
            $out[$n->anchorId] = round($n->y, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };

    $walk(layout(
        '<style>table{border-spacing:0;width:90pt;margin:0}'
        . 'td,th{padding:0;line-height:12pt}' . $extra . '</style>' . $body,
    ));

    return $out;
};

$tall = '<td>p<br>q<br>r</td>';

// `WA-cell-ua-valign.html` `c0` and `Y1-cell-valign-groups.html` `c0`: Chrome
// puts the baseline at 21.000 in a 36pt row and this engine put it at 9.001.
ok('an undeclared cell centres its content in its row',
    $cellY("<table><tr><td><div id=\"a\">x</div></td>$tall</tr></table>")['a'] === 12.0,
    sprintf('%s, want 12', json_encode($cellY("<table><tr><td><div id=\"a\">x</div></td>$tall</tr></table>"))));

// `Y1` `g0`: the group carries the value and the row passes it on, which is
// what `td, th { vertical-align: inherit }` is for.
$group = $cellY('<table><tbody style="vertical-align:bottom"><tr>'
    . "<td><div id=\"b\">x</div></td>$tall</tr></tbody></table>");

ok('a vertical-align on a row group reaches the cell',
    $group['b'] === 24.0, sprintf('%s, want 24', json_encode($group)));

// `Y4-cell-valign-author-tr.html` `z0`: an author's own `tr` rule outranks the
// UA default, on a row written with no `<tbody>` around it.
$authorRow = $cellY("<table><tr><td><div id=\"c\">x</div></td>$tall</tr></table>",
    'tr{vertical-align:bottom}');

ok('an author rule on tr beats the UA default on a bare row',
    $authorRow['c'] === 24.0, sprintf('%s, want 24', json_encode($authorRow)));

// `Y1` `l0`: Chrome's `<tbody>` carries `middle` in its own right, so a
// declaration on the table never reaches the cells through it.
$onTable = $cellY('<table style="vertical-align:bottom"><tbody><tr>'
    . "<td><div id=\"d\">x</div></td>$tall</tr></tbody></table>");

ok('a vertical-align on the table is blocked by the row group',
    $onTable['d'] === 12.0, sprintf('%s, want 12', json_encode($onTable)));

// `Y2-cell-valign-neighbours.html` `t0`: §17.2.1's anonymous row, whose cells
// cascade against the table itself.
$noRow = $cellY("<table><td><div id=\"e\">x</div></td>$tall</table>");

ok('a cell with no row around it centres too',
    $noRow['e'] === 12.0, sprintf('%s, want 12', json_encode($noRow)));

// `Y2` `n0`: a `<th>` in a `<thead>`, which is two inherit steps.
$head = $cellY('<table><thead><tr><th><div id="f">x</div></th>'
    . '<th>p<br>q<br>r</th></tr></thead></table>');

ok('a th in a thead centres its content',
    $head['f'] === 12.0, sprintf('%s, want 12', json_encode($head)));

// The two controls that must not move, both exact on the round 18p engine.
// `Y1` `m0` is the explicit declaration and `Y2` `r0` is the `display: table`
// spelling, which Chrome leaves at the initial value rather than centring.
$declared = $cellY('<table><tr><td style="vertical-align:top">'
    . "<div id=\"g\">x</div></td>$tall</tr></table>");

ok('an explicit vertical-align: top still leaves content at the top',
    $declared['g'] === 0.0, sprintf('%s, want 0', json_encode($declared)));

$divTable = $cellY('<div style="display:table;width:90pt"><div style="display:table-row">'
    . '<div style="display:table-cell"><div id="h">x</div></div>'
    . '<div style="display:table-cell">p<br>q<br>r</div></div></div>');

ok('a display: table-cell div is not centred',
    $divTable['h'] === 0.0, sprintf('%s, want 0', json_encode($divTable)));

// Defect CC: which of a table's three sections a group is, is its *display*.
// A `display: table-header-group` on anything that was not a `<thead>` had its
// rows dropped on the floor, and a `<thead>` was a header whatever display it
// declared. Each shape is one 150pt table over 12pt rows, so a section that
// takes the surplus reads 138 and one that does not reads 12.
$rowBoxes = static function (string $body): array {
    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null) {
            $out[$n->anchorId] = [round($n->y, 3), round($n->layoutHeight, 3)];
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };

    $walk(layout(
        '<style>table{border-spacing:0;width:90pt;height:150pt;margin:0}'
        . 'td,th{padding:0;line-height:12pt}'
        . '.t{display:table;width:90pt;height:150pt}.r{display:table-row}'
        . '.c{display:table-cell;line-height:12pt}'
        . '.hg{display:table-header-group}.fg{display:table-footer-group}'
        . '.rg{display:table-row-group}</style>' . $body,
    ));

    // The rows come back in tree order, which a section moved to the top or
    // the bottom of the table changes: key order is not what is being asserted.
    ksort($out);

    return $out;
};

// `Z1-group-display.html` `e0`: the header row was not built at all, so
// nothing was painted for it and the body row took the whole 150.
$orphanHead = $rowBoxes('<div class="t"><div class="hg"><div class="r" id="i">'
    . '<div class="c">head</div></div></div><div class="rg"><div class="r" id="j">'
    . '<div class="c">alpha</div></div></div></div>');

ok('an orphaned header group keeps its rows and stays at the top',
    $orphanHead === ['i' => [0.0, 12.0], 'j' => [12.0, 138.0]],
    sprintf('%s, want i [0, 12] j [12, 138]', json_encode($orphanHead)));

// `Z1` `e2`: §17.5.1 renders the header group at the top of the table whatever
// order it was written in, which Chrome does for the div spelling too.
$lateHead = $rowBoxes('<div class="t"><div class="rg"><div class="r" id="k">'
    . '<div class="c">alpha</div></div></div><div class="hg"><div class="r" id="l">'
    . '<div class="c">head</div></div></div></div>');

ok('a header group written second renders at the top',
    $lateHead === ['k' => [12.0, 138.0], 'l' => [0.0, 12.0]],
    sprintf('%s, want k [12, 138] l [0, 12]', json_encode($lateHead)));

// `Z1` `e3`: and the footer group at the bottom, on the same terms.
$earlyFoot = $rowBoxes('<div class="t"><div class="fg"><div class="r" id="m">'
    . '<div class="c">foot</div></div></div><div class="rg"><div class="r" id="n">'
    . '<div class="c">alpha</div></div></div></div>');

ok('a footer group written first sinks to the bottom',
    $earlyFoot === ['m' => [138.0, 12.0], 'n' => [0.0, 138.0]],
    sprintf('%s, want m [138, 12] n [0, 138]', json_encode($earlyFoot)));

// `Z1` `e6` and `e7`: the display decides, so a `<tbody>` can be the header
// and a `<thead>` an ordinary body section. This engine read the tag and got
// both of these exactly the wrong way round.
$tbodyHead = $rowBoxes('<table><tbody class="hg"><tr id="o"><td>head</td></tr></tbody>'
    . '<tbody><tr id="p"><td>alpha</td></tr></tbody></table>');

ok('a tbody with a header-group display is the header',
    $tbodyHead === ['o' => [0.0, 12.0], 'p' => [12.0, 138.0]],
    sprintf('%s, want o [0, 12] p [12, 138]', json_encode($tbodyHead)));

$theadBody = $rowBoxes('<table><thead class="rg"><tr id="q"><td>head</td></tr></thead>'
    . '<tbody><tr id="r"><td>alpha</td></tr></tbody></table>');

ok('a thead with a row-group display is an ordinary section',
    $theadBody === ['q' => [0.0, 75.0], 'r' => [75.0, 75.0]],
    sprintf('%s, want q [0, 75] r [75, 75]', json_encode($theadBody)));

// `Z2-group-display-more.html` `f3`: §17.5.1 gives a table one header, so the
// second `<thead>` is an ordinary row group where it stands and shares the
// surplus with the body.
$twoHeads = $rowBoxes('<table><thead><tr id="s"><td>one</td></tr></thead>'
    . '<thead><tr id="t"><td>two</td></tr></thead>'
    . '<tbody><tr id="u"><td>alpha</td></tr></tbody></table>');

ok('only the first of two theads is the header',
    $twoHeads === ['s' => [0.0, 12.0], 't' => [12.0, 69.0], 'u' => [81.0, 69.0]],
    sprintf('%s, want s [0, 12] t [12, 69] u [81, 69]', json_encode($twoHeads)));

// The control: a plain `<thead>`, `<tbody>` and `<tfoot>` in the order they are
// usually written, which is `Z2` `f5` and was already exact on both engines.
$plain = $rowBoxes('<table><thead><tr id="v"><td>head</td></tr></thead>'
    . '<tbody><tr id="w"><td>alpha</td></tr></tbody>'
    . '<tfoot><tr id="x"><td>foot</td></tr></tfoot></table>');

ok('a plainly written thead, tbody and tfoot are unchanged',
    $plain === ['v' => [0.0, 12.0], 'w' => [12.0, 126.0], 'x' => [138.0, 12.0]],
    sprintf('%s, want v [0, 12] w [12, 126] x [138, 12]', json_encode($plain)));

// Repeating on every page needs the element as well as the display, which is
// the one question the display does not settle: Chrome repeats a `<thead>` that
// is the header section and nothing else. Only the header cell carries a
// background, so a fragment with one is a copy of the header.
$headerCopies = static function (string $sections): array {
    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment(layout(
        '<style>table{border-spacing:0;width:400pt;margin:0}'
        . 'td{padding:0;line-height:12pt;vertical-align:top}'
        . 'thead td,.hg td{background:#1b7a3a}'
        . '.hg{display:table-header-group}.rg{display:table-row-group}</style>'
        . '<div style="height:6pt;margin:0"></div><table>' . $sections
        . '<tbody>' . str_repeat('<tr><td>x</td></tr>', 28) . '</tbody></table>',
    )) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[$pi] = ($out[$pi] ?? 0) + 1;
            }
        }
    }

    ksort($out);

    return $out;
};

// `Z8-fold-thead-control.html`: the control, one copy per page, unmoved.
ok('a real thead still repeats on every page',
    $headerCopies('<thead><tr><td>HEAD</td></tr></thead>') === [0 => 1, 1 => 1],
    sprintf('%s, want [1, 1]', json_encode($headerCopies('<thead><tr><td>HEAD</td></tr></thead>'))));

// `Z5-fold-group-thead-demoted.html`: a `<thead>` that is not the header
// section does not repeat, where this engine replayed it on every page.
ok('a demoted thead does not repeat',
    $headerCopies('<thead class="rg"><tr><td>HEAD</td></tr></thead>') === [0 => 1],
    sprintf('%s, want [1]', json_encode($headerCopies('<thead class="rg"><tr><td>HEAD</td></tr></thead>'))));

// `Z4-fold-group-header-tbody.html`: and a `<tbody>` acting as the header does
// not repeat either, which is the shape that says the tag is what Chrome asks
// about here and not the display.
ok('a tbody acting as the header does not repeat',
    $headerCopies('<tbody class="hg"><tr><td>HEAD</td></tr></tbody>') === [0 => 1],
    sprintf('%s, want [1]', json_encode($headerCopies('<tbody class="hg"><tr><td>HEAD</td></tr></tbody>'))));

// `ZB-fold-thead-second.html`: two copies on the page they are written on and
// only the first of them on the next.
ok('only the first of two theads repeats',
    $headerCopies('<thead><tr><td>HDA</td></tr></thead><thead><tr><td>HDB</td></tr></thead>') === [0 => 2, 1 => 1],
    sprintf('%s, want [2, 1]', json_encode($headerCopies('<thead><tr><td>HDA</td></tr></thead><thead><tr><td>HDB</td></tr></thead>'))));

// Defect CF: a row the fold has to cut aligns its cells to the top of the
// fragment. A `middle` or `bottom` cell put its content at its centred offset,
// which is past the fold, so no offset was one every cell could break at and
// the row moved whole. Only the cells carry a background, so a fragment with
// one is a cell fragment; the glyph count per page says where the content went.
$foldRow = static function (string $align, int $lines = 22, string $extra = ''): array {
    $body = implode('<br>', array_map(static fn (int $i): string => "b{$i}", range(1, $lines)));

    $pages = (new Fragmenter(PAGE_H))->fragment(layout(
        '<style>table{border-spacing:0;width:300pt;margin:0}'
        . 'td{padding:0;line-height:12pt;vertical-align:' . $align . '}'
        . '#a{background:#1b7a3a;width:75pt}#b{background:#7a1b3a;width:225pt}'
        . '#c{background:#1b3a7a}</style>'
        . '<div style="height:180pt;margin:0"></div>'
        . '<table><tr><td id="a">a</td><td id="b">' . $body . '</td></tr>' . $extra . '</table>',
    ));

    $rects  = [];
    $glyphs = [];

    foreach ($pages as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $rects[] = sprintf('%d@%.0f+%.0f', $pi, $f->y, $f->h);
            }

            foreach ($f->lines as $line) {
                foreach ($line->items as $item) {
                    if (!$item->isSpace) {
                        $glyphs[$pi] = ($glyphs[$pi] ?? 0) + 1;
                    }
                }
            }
        }
    }

    sort($rects);
    ksort($glyphs);

    return [$rects, $glyphs];
};

// `ZD-fold-cell-valign-bottom.html` and `Y5-fold-cell-valign-middle.html`:
// Chrome cuts the row 120 / 144 and puts the short cell's line 9.000 into the
// first fragment, which is byte for byte what the same row declaring `top`
// does. This moved the whole row to the next page.
foreach (['middle', 'bottom', 'top'] as $align) {
    [$rects, $glyphs] = $foldRow($align);

    ok("a row the fold cuts is sliced with its cells vertical-align: {$align}",
        $rects === ['0@180+120', '0@180+120', '1@0+144', '1@0+144'] && $glyphs === [0 => 11, 1 => 12],
        sprintf('%s %s, want two 120pt fragments then two 144pt and 11 / 12 glyphs',
            json_encode($rects), json_encode($glyphs)));
}

// `ZG-fold-cell-valign-twofolds.html`: two folds, so three fragments, and the
// short cell's own line stays on the first of them.
[$twoFolds, $twoGlyphs] = $foldRow('middle', 50);

ok('a row two folds cut is sliced twice with its cells at the top',
    $twoFolds === ['0@180+120', '0@180+120', '1@0+300', '1@0+300', '2@0+180', '2@0+180']
        && $twoGlyphs === [0 => 11, 1 => 25, 2 => 15],
    sprintf('%s %s, want 120 / 300 / 180 and 11 / 25 / 15 glyphs',
        json_encode($twoFolds), json_encode($twoGlyphs)));

// `ZH-fold-cell-valign-rowspan.html`: a `rowspan` cell reaches past the row the
// fold cut, and its own content is at the top of the first fragment too.
[$spanRects, $spanGlyphs] = $foldRow('middle', 22, '<tr><td id="c">c</td></tr>');

ok('a rowspan cell the fold cuts aligns to the top as well',
    $spanGlyphs === [0 => 11, 1 => 13],
    sprintf('%s %s, want 11 / 13 glyphs', json_encode($spanRects), json_encode($spanGlyphs)));

// `ZF-fold-cell-valign-nofold.html` is the control that must not move: a row no
// fold reaches keeps its content centred, 66.000 into a 144pt row for a 12pt
// line, which is where the UA `middle` puts it.
$centred = $cellY('<table><tr><td><div id="i">x</div></td>'
    . '<td>' . str_repeat('q<br>', 11) . 'q</td></tr></table>');

ok('a row no fold reaches keeps its cells centred',
    $centred['i'] === 66.0, sprintf('%s, want 66', json_encode($centred)));

// Defect BT: a row flex container whose items do not share a top was left
// alone at a fold, because it was not one band. Chrome cuts it exactly as it
// cuts a container whose items do share one, and the staggered item is placed
// at its **own** offset from the container's top rather than after the item
// beside it. Every box carries a background, so each fragment is one box: the
// container is 300pt wide, the two items 150pt each and only the second of
// them starts at x 150.
$flexFold = static function (string $align, string $c1, float $spacer = 279.0): array {
    $pages = (new Fragmenter(PAGE_H))->fragment(layout(
        '<style>html,body{margin:0;padding:0}body{line-height:12pt}'
        . '#g0{display:flex;align-items:' . $align . ';background:#8b1a1a;width:300pt;height:60pt}'
        . '#c0{height:60pt;width:150pt;background:#1b7a3a}'
        . '#c1{' . $c1 . ';width:150pt;background:#7a1b3a}</style>'
        . '<div style="height:' . $spacer . 'pt;margin:0"></div>'
        . '<div id="g0"><div id="c0">a1</div><div id="c1">b1</div></div>',
    ));

    $out = [];

    foreach ($pages as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = sprintf('%d@%.0f,%.0f %.0fx%.0f', $pi, $f->x, $f->y, $f->w, $f->h);
            }
        }
    }

    sort($out);

    return $out;
};

// `ZJ-fold-flexrow-center.html`: the container is cut 21.000 / 39.000 and the
// centred item, which has 6pt above the fold and a 12pt line, moves whole to
// 0.000 on the page after. This moved the whole container.
$cut = ['0@0,279 150x21', '0@0,279 300x21', '1@0,0 150x39', '1@0,0 300x39', '1@150,0 150x30'];

foreach (['center' => 'height:30pt', 'flex-end' => 'height:30pt;margin-bottom:15pt'] as $align => $c1) {
    ok("a staggered row flex container is cut at a fold with align-items: {$align}",
        $flexFold($align, $c1) === $cut,
        sprintf('%s, want %s', json_encode($flexFold($align, $c1)), json_encode($cut)));
}

// `ZL-fold-flexrow-margin.html` and `ZR-fold-flexrow-alignself.html`: a margin
// and a per-item `align-self` stagger the item exactly as `align-items` does,
// which is the shape that says the rule is the offset and not the property
// that produced it.
foreach (['height:30pt;margin-top:15pt', 'height:30pt;align-self:center'] as $c1) {
    ok("a row flex container staggered by {$c1} is cut the same way",
        $flexFold('flex-start', $c1) === $cut,
        sprintf('%s, want %s', json_encode($flexFold('flex-start', $c1)), json_encode($cut)));
}

// `ZK-fold-flexrow-center-below.html`: an item whose own offset is past the
// fold lands that far into the page after it, 22.500 - 21.000 = 1.500, rather
// than at the top of it.
$below = $flexFold('center', 'height:15pt');

ok('an item starting below the fold lands at its own offset into the next page',
    $below === ['0@0,279 150x21', '0@0,279 300x21', '1@0,0 150x39', '1@0,0 300x39', '1@150,2 150x15'],
    sprintf('%s, want the item at y 1.500 on page 2', json_encode($below)));

// `ZO-fold-flexrow-center-cut.html`: a staggered item with room for a line of
// its own above the fold is cut there like anything else, 18.000 / 36.000.
$sliced = $flexFold('center', 'height:54pt');

ok('a staggered item with room above the fold is cut there',
    $sliced === ['0@0,279 150x21', '0@0,279 300x21', '0@150,282 150x18',
        '1@0,0 150x39', '1@0,0 300x39', '1@150,0 150x36'],
    sprintf('%s, want the item 18 then 36', json_encode($sliced)));

// `ZM-fold-flexrow-flexend.html`: the item the walk moved whole is taller than
// the container has left, and Chrome lets it overflow rather than growing the
// box: the container's second fragment is 39.000, not 45.000.
$overflows = $flexFold('flex-end', 'height:45pt');

ok('a moved item does not stretch the container it overflows',
    in_array('1@0,0 300x39', $overflows, true) && !in_array('1@0,0 300x45', $overflows, true),
    sprintf('%s, want the container 39 on page 2', json_encode($overflows)));

// `ZQ-fold-flexrow-center-noroom.html` is the control: with 7.5pt above the
// fold no item can leave a line behind, so the whole container moves and
// nothing at all is painted on the page it left.
$noRoom = $flexFold('center', 'height:30pt', 292.5);

ok('a staggered container with no room for a line still moves whole',
    $noRoom === ['1@0,0 150x60', '1@0,0 300x60', '1@150,15 150x30'],
    sprintf('%s, want everything on page 2', json_encode($noRoom)));

// `ZN-fold-flexrow-center-nofold.html` is the other control: a container no
// fold reaches is one fragment per box, with the centred item 15.000 down.
$noFold = $flexFold('center', 'height:30pt', 75.0);

ok('a staggered container no fold reaches is untouched',
    $noFold === ['0@0,75 150x60', '0@0,75 300x60', '0@150,90 150x30'],
    sprintf('%s, want one page', json_encode($noFold)));

// Defect BD: a column flex item shrank below its own content, because §4.5's
// content size suggestion in the block axis was never measured and
// `autoMinMain()` returned 0 for the column. The shortfall was shared out with
// no floor at all, and an item beside one that would not shrink reached 0.000
// and vanished.
$columnHeights = static function (string $items, string $container = '', string $basePath = ''): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}body{line-height:12pt}'
        . '.c{display:flex;flex-direction:column;width:150pt;height:30pt;' . $container . '}'
        . '.c > div{background:#1a3a6b}</style>'
        . '<div class="c">' . $items . '</div>',
        PAGE_W,
        PAGE_H,
        $basePath,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutHeight, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$threeLines = '<div id="a">one<br>two<br>three</div>';

// `ZS-column-automin-more.html` `k0`: 36.000 and 12.000 in Chrome, each item
// floored at its own content, where this shared the shortfall out 13.333 /
// 16.667 with no floor at all.
$k0 = $columnHeights($threeLines . '<div id="b" style="height:45pt">two</div>');

ok('a column flex item is floored at its own content height',
    $k0 === ['a' => 36.0, 'b' => 12.0], sprintf('%s, want a 36 b 12', json_encode($k0)));

// `k7`: the same with an item that will not shrink, which is the shape that
// reached **0.000** and lost the second item's line entirely.
$k7 = $columnHeights('<div id="a" style="flex-shrink:0">one<br>two<br>three</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('an item beside one that will not shrink keeps its own line',
    $k7 === ['a' => 36.0, 'b' => 12.0], sprintf('%s, want a 36 b 12', json_encode($k7)));

// `ZS` `k6` and `I15-column-automin.html` `y8b`: the floor is the content's own
// height however that content is sized, so an item wrapping a box taller than
// the whole container cannot shrink and the item beside it still keeps its
// line. On `I15` that first item is a 240px-tall image, which is the ordinary
// markup this reaches, and its second item was **0.000**.
$k8 = $columnHeights('<div id="a"><div style="height:450pt"></div></div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('an item beside a box taller than the container keeps its own line',
    $k8 === ['a' => 450.0, 'b' => 12.0], sprintf('%s, want a 450 b 12', json_encode($k8)));

// `k3`: §4.5's last clause, the automatic minimum clamped by the max main size.
$k3 = $columnHeights('<div id="a" style="max-height:15pt">one<br>two<br>three</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('a column item\'s content floor is clamped by its max-height',
    $k3 === ['a' => 15.0, 'b' => 15.0], sprintf('%s, want a 15 b 15', json_encode($k3)));

// `k5`: the floor is the border box, so the item's own padding is in it: a
// 12pt line inside `padding: 6pt` is 24.000 in Chrome.
$k5 = $columnHeights('<div id="a" style="padding:6pt">one</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('a column item\'s content floor carries its own padding',
    $k5 === ['a' => 24.0, 'b' => 12.0], sprintf('%s, want a 24 b 12', json_encode($k5)));

// `k1` and `k2` are the two controls that must not move. A declared
// `min-height` is not the automatic one, and §4.5 gives an item whose overflow
// is not visible no automatic minimum at all: Chrome shrinks both of these
// straight past their content, 13.336 against three lines of 36.000.
foreach (['min-height:0', 'overflow:hidden'] as $lift) {
    $lifted = $columnHeights('<div id="a" style="' . $lift . '">one<br>two<br>three</div>'
        . '<div id="b" style="height:45pt">two</div>');

    ok("a column item declaring {$lift} still shrinks past its content",
        $lifted === ['a' => 13.333, 'b' => 16.667],
        sprintf('%s, want a 13.333 b 16.667', json_encode($lifted)));
}

// `ZS` `ka` against `kb`: §4.5's overflow clause outranks the **transferred**
// size suggestion as well, so a 60x240 image that fills a 150pt column and
// would be 600.000 tall by its own ratio is squashed to **78.000** when its
// overflow is hidden, and stays 600.000 when it is not. Chrome's numbers on
// both, and the item under it is 12.000 either way where it was 0.000.
$tall = '/tmp/flexpdf_tall60x240.png';

if (!is_file($tall)) {
    $im = imagecreatetruecolor(60, 240);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 100, 220));
    imagepng($im, $tall);
    imagedestroy($im);
}

foreach (['overflow:hidden' => 78.0, '' => 600.0] as $clip => $want) {
    $image = $columnHeights(
        '<img id="a" src="' . $tall . '" style="' . $clip . '"><div id="b" style="height:45pt">two</div>',
        'height:90pt',
        dirname($tall),
    );

    ok('a replaced column item with ' . ($clip === '' ? 'visible overflow' : $clip) . " is {$want}",
        $image === ['a' => $want, 'b' => 12.0], sprintf('%s, want a %s b 12', json_encode($image), $want));
}

// `I15` `y0` is the third control: two items whose content is one line each
// have a floor of 12.000 and the 15.000 they shrink to clears it, so the
// declaration decides and nothing about this changes.
$y0 = $columnHeights('<div id="a" style="height:45pt">one</div><div id="b" style="height:45pt">two</div>');

ok('two column items that clear their own floors share the space as before',
    $y0 === ['a' => 15.0, 'b' => 15.0], sprintf('%s, want a 15 b 15', json_encode($y0)));

// Round 18t, defect CH: a forced line break does not end a max-content run, so
// the max-content width of any box holding one is the sum of its lines where
// Chrome takes the widest of them, and the factor is the line count exactly. A
// float takes its max-content width straight, which is what makes it readable.
$breakWidths = static function (string $body): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{border-spacing:0;margin:0;clear:both}td{padding:0}'
        . '.sw{float:left;clear:both}.nar{width:20px;clear:both}</style>' . $body,
        PAGE_W,
        PAGE_H,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutWidth, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

// `ZT-maxcontent-break.html` `br0`: 34.535 in Chrome, the first line, where
// this summed the two and gave 44.028.
$br0 = $breakWidths('<div class="sw" id="a">alpha be<br>cd</div>');

ok('a forced break ends a max-content run',
    $br0 === ['a' => 34.524], sprintf('%s, want a 34.524', json_encode($br0)));

// `br2`: the widest segment wins wherever it is written.
$br2 = $breakWidths('<div class="sw" id="a">cd<br>alpha be</div>');

ok('the widest segment wins whichever line it is on',
    $br2 === ['a' => 34.524], sprintf('%s, want a 34.524', json_encode($br2)));

// `br5`: two breaks in a row, three segments, Chrome 22.031 for `alpha`.
$br5 = $breakWidths('<div class="sw" id="a">alpha<br><br>be</div>');

ok('two forced breaks in a row make three runs',
    $br5 === ['a' => 22.014], sprintf('%s, want a 22.014', json_encode($br5)));

// `br6`, `br7` and `br8`: a preserved newline is the same rule as `<br>` under
// all three of `pre`, `pre-wrap` and `pre-line`, 34.535 in Chrome for each.
foreach (['pre', 'pre-wrap', 'pre-line'] as $preserve) {
    $preserved = $breakWidths(
        '<div class="sw" id="a" style="white-space:' . $preserve . "\">alpha be\ncd</div>"
    );

    ok("a preserved newline under {$preserve} ends a max-content run",
        $preserved === ['a' => 34.524], sprintf('%s, want a 34.524', json_encode($preserved)));
}

// `br9`: white space preserved before a newline belongs to the run it ends, so
// two spaces there are 5.004 of width Chrome keeps: 39.539 against `br6`'s
// 34.535.
$br9 = $breakWidths('<div class="sw" id="a" style="white-space:pre">alpha be  ' . "\ncd</div>");

ok('preserved white space before a newline stays in the run it ends',
    $br9 === ['a' => 39.528], sprintf('%s, want a 39.528', json_encode($br9)));

// `brf`: the break is skipped rather than measured. It carries the span it sits
// inside with no opening or closing index of its own, so charging its edges
// gave the box a second left and right edge: 56.028 against Chrome's 37.535,
// where `brg` (the same span with no break in it) was right all along.
$brf = $breakWidths('<div class="sw" id="a"><span style="padding:0 4px">alpha be<br>cd</span></div>');

ok('an inline element straddling a break is charged its edges once',
    $brf === ['a' => 37.524], sprintf('%s, want a 37.524', json_encode($brf)));

// `brd`: the table column algorithm reads the same width, so a 400px table
// whose cells are `a` and `alpha be<br>cd` splits 38.039 / 261.961 in Chrome
// where this split 30.617 / 269.383.
$brd = $breakWidths('<table style="width:400px"><tr><td id="a">a</td>'
    . '<td id="b">alpha be<br>cd</td></tr></table>');

ok('a table column measures the widest line and not the sum',
    $brd === ['a' => 37.978, 'b' => 262.022],
    sprintf('%s, want a 37.978 b 262.022', json_encode($brd)));

// `br1` and `br3` are the two controls that must not move: the same words with
// no break in them, and a break with nothing after it, which adds neither a
// line nor a width on either engine.
$br1 = $breakWidths('<div class="sw" id="a">alpha be cd</div>');

ok('a box with no forced break in it is unchanged',
    $br1 === ['a' => 46.53], sprintf('%s, want a 46.53', json_encode($br1)));

$br3 = $breakWidths('<div class="sw" id="a">alpha be<br></div>');

ok('a trailing forced break adds nothing to the width',
    $br3 === ['a' => 34.524], sprintf('%s, want a 34.524', json_encode($br3)));

// `bra` and `brb` are the third control, and they are the half of this that was
// already right: min-content resets at a break already, so a table squeezed
// into a 20px block is its widest **word** on both engines, break or no break.
$bra = $breakWidths('<div class="nar"><table><tr><td id="a">alpha be<br>cd</td></tr></table></div>'
    . '<div class="nar"><table><tr><td id="b">alpha be cd</td></tr></table></div>');

ok('the min-content width across a forced break is unchanged',
    $bra === ['a' => 22.014, 'b' => 22.014],
    sprintf('%s, want a 22.014 b 22.014', json_encode($bra)));

// Round 18t, defect BZ: a declared `height` on a `<tr>` is dropped, because
// every row height was derived from the cells and the row's own declaration was
// never read. It is a minimum for the row, and it pins the row out of the
// table's surplus distribution the way a declared cell height already did.
$rowHeights = static function (string $table): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{border-spacing:0;margin:0}td,th{padding:0}</style>' . $table,
        PAGE_W,
        PAGE_H,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutHeight, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

// `ZU-row-height.html` `rh0`: 60.000 and 12.000 in Chrome, and the table 72.000,
// where the declaration was read nowhere and every box was 12.000.
$rh0 = $rowHeights('<table id="t" style="width:120px"><tr id="a" style="height:80px"><td>alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a declared height on a <tr> is a minimum for the row',
    $rh0 === ['t' => 72.0, 'a' => 60.0, 'b' => 12.0],
    sprintf('%s, want t 72 a 60 b 12', json_encode($rh0)));

// `rh1`: a minimum and not a size, so content taller than the declaration wins.
$rh1 = $rowHeights('<table id="t" style="width:120px"><tr id="a" style="height:20px"><td>one<br>two<br>three</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a declared row height under its own content is the content\'s',
    $rh1 === ['t' => 48.0, 'a' => 36.0, 'b' => 12.0],
    sprintf('%s, want t 48 a 36 b 12', json_encode($rh1)));

// `rh2`: the cell's padding is inside the row's declared height, not added to it.
$rh2 = $rowHeights('<table id="t" style="width:120px"><tr id="a" style="height:60px"><td style="padding:8px">alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a cell\'s padding sits inside its row\'s declared height',
    $rh2 === ['t' => 57.0, 'a' => 45.0, 'b' => 12.0],
    sprintf('%s, want t 57 a 45 b 12', json_encode($rh2)));

// `rh7`: read alongside the cells' own declarations rather than instead of them.
$rh7 = $rowHeights('<table id="t" style="width:120px"><tr id="a" style="height:60px">'
    . '<td style="height:20px">alpha</td><td>beta</td></tr><tr id="b"><td>gamma</td></tr></table>');

ok('a row declaration outranks a smaller cell declaration',
    $rh7 === ['t' => 57.0, 'a' => 45.0, 'b' => 12.0],
    sprintf('%s, want t 57 a 45 b 12', json_encode($rh7)));

// `rh4`: a percentage resolves against the table's own height, and `rh3` is the
// control that says it is `auto` where the table has none.
$rh4 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a" style="height:25%"><td>alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr><tr id="c"><td>gamma</td></tr></table>');

ok('a percentage row height resolves against the table',
    $rh4 === ['t' => 150.0, 'a' => 37.5, 'b' => 56.25, 'c' => 56.25],
    sprintf('%s, want t 150 a 37.5 b 56.25 c 56.25', json_encode($rh4)));

$rh3 = $rowHeights('<table id="t" style="width:120px"><tr id="a" style="height:50%"><td>alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a percentage row height on a table with no height is auto',
    $rh3 === ['t' => 24.0, 'a' => 12.0, 'b' => 12.0],
    sprintf('%s, want t 24 a 12 b 12', json_encode($rh3)));

// `rh5` and `W3` `y1`: a pinned row keeps exactly its declaration and the
// table's surplus goes round it, to the rows it can still move.
$rh5 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a" style="height:40px"><td>alpha</td></tr>'
    . '<tr id="b" style="height:40px"><td>beta</td></tr><tr id="c"><td>gamma</td></tr></table>');

ok('the table\'s surplus goes round a row its own height pinned',
    $rh5 === ['t' => 150.0, 'a' => 30.0, 'b' => 30.0, 'c' => 90.0],
    sprintf('%s, want t 150 a 30 b 30 c 90', json_encode($rh5)));

// `rha`: the declaration pins the row out of the split whether or not it wins
// the row's height, which is the half a `max()` alone would have got wrong.
$rha = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a" style="height:20px">'
    . '<td>one<br>two<br>three</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a row a declaration pinned is out of the split even when its content wins',
    $rha === ['t' => 150.0, 'a' => 36.0, 'b' => 114.0],
    sprintf('%s, want t 150 a 36 b 114', json_encode($rha)));

// `rh9`: nothing left to give the surplus to falls back to the whole set, which
// is `stretchToDeclaredHeight`'s existing rule and must keep working.
$rh9 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a" style="height:40px"><td>alpha</td></tr></table>');

ok('a table whose every row is pinned still fills its declared height',
    $rh9 === ['t' => 150.0, 'a' => 150.0],
    sprintf('%s, want t 150 a 150', json_encode($rh9)));

// `rh8` is the control: no declaration anywhere and nothing about this changes.
$rh8 = $rowHeights('<table id="t" style="width:120px"><tr id="a"><td>alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a table with no row height declared is unchanged',
    $rh8 === ['t' => 24.0, 'a' => 12.0, 'b' => 12.0],
    sprintf('%s, want t 24 a 12 b 12', json_encode($rh8)));

// `W3` `y4` is the second control: a declared **cell** height, which round 18p
// already honoured, on a table whose surplus it also pins.
$y4 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a"><td style="height:80px">alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a declared cell height still pins its row as it did',
    $y4 === ['t' => 150.0, 'a' => 60.0, 'b' => 90.0],
    sprintf('%s, want t 150 a 60 b 90', json_encode($y4)));

// Round 18t, defect CB: a row-spanning cell's shortfall landed entirely on the
// last row of its span, where Chrome spreads it over the rows the cell spans on
// §17.5.3's own two preferences.
//
// `ZV-rowspan-shortfall.html` `rs0`: 60.000 and 60.000 in Chrome, where this
// gave 12.000 and 108.000 for the same 120.000 of table.
$rs0 = $rowHeights('<table id="t" style="width:160px"><tr id="a"><td rowspan="2" style="height:160px">tall</td>'
    . '<td>one</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a row-spanning cell\'s shortfall is spread over the rows it spans',
    $rs0 === ['t' => 120.0, 'a' => 60.0, 'b' => 60.0],
    sprintf('%s, want t 120 a 60 b 60', json_encode($rs0)));

// `rs2`: proportional to what each row already has, not equal.
$rs2 = $rowHeights('<table id="t" style="width:160px"><tr id="a"><td rowspan="2" style="height:160px">tall</td>'
    . '<td>one<br>two<br>three</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('the shortfall is proportional to what each row already has',
    $rs2 === ['t' => 120.0, 'a' => 90.0, 'b' => 30.0],
    sprintf('%s, want t 120 a 90 b 30', json_encode($rs2)));

// `rs9` and `rsa`: a row a length pinned is out of the split, whether the pin is
// the row's own declaration or its cell's.
foreach (['<tr id="b" style="height:60px"><td>beta</td></tr>' => 'its own declaration',
    '<tr id="b"><td style="height:60px">beta</td></tr>' => 'its cell\'s'] as $middle => $how) {
    $pinned = $rowHeights('<table id="t" style="width:160px"><tr id="a">'
        . '<td rowspan="3" style="height:160px">tall</td><td>one</td></tr>'
        . $middle . '<tr id="c"><td>gamma</td></tr></table>');

    ok("a row pinned by {$how} is out of the shortfall's split",
        $pinned === ['t' => 120.0, 'a' => 37.5, 'b' => 45.0, 'c' => 37.5],
        sprintf('%s, want t 120 a 37.5 b 45 c 37.5', json_encode($pinned)));
}

// `rsb`: nothing free falls back to every row of the span.
$rsb = $rowHeights('<table id="t" style="width:160px"><tr id="a" style="height:20px">'
    . '<td rowspan="2" style="height:160px">tall</td><td>one</td></tr>'
    . '<tr id="b" style="height:20px"><td>beta</td></tr></table>');

ok('a span whose every row is pinned still shares the shortfall',
    $rsb === ['t' => 120.0, 'a' => 60.0, 'b' => 60.0],
    sprintf('%s, want t 120 a 60 b 60', json_encode($rsb)));

// `rs4`: only the rows the span covers, and `rs7` the same under a declared
// table height, where §17.5.3's own surplus goes out over all three afterwards.
$rs4 = $rowHeights('<table id="t" style="width:160px"><tr id="a"><td rowspan="2" style="height:160px">tall</td>'
    . '<td>one</td></tr><tr id="b"><td>beta</td></tr><tr id="c"><td>gamma</td><td>delta</td></tr></table>');

ok('a row outside the span is left alone',
    $rs4 === ['t' => 132.0, 'a' => 60.0, 'b' => 60.0, 'c' => 12.0],
    sprintf('%s, want t 132 a 60 b 60 c 12', json_encode($rs4)));

$rs7 = $rowHeights('<table id="t" style="width:160px;height:300px"><tr id="a">'
    . '<td rowspan="2" style="height:160px">tall</td><td>one</td></tr><tr id="b"><td>beta</td></tr>'
    . '<tr id="c"><td>gamma</td><td>delta</td></tr></table>');

ok('the table\'s own surplus goes out over the spread rows',
    $rs7 === ['t' => 225.0, 'a' => 102.273, 'b' => 102.273, 'c' => 20.455],
    sprintf('%s, want t 225 a 102.273 b 102.273 c 20.455', json_encode($rs7)));

// `rs8` is the control: the same table with no `rowspan` in it.
$rs8 = $rowHeights('<table id="t" style="width:160px"><tr id="a"><td>one</td><td>two</td></tr>'
    . '<tr id="b"><td>beta</td><td>gamma</td></tr></table>');

ok('a table with no rowspan in it is unchanged',
    $rs8 === ['t' => 24.0, 'a' => 12.0, 'b' => 12.0],
    sprintf('%s, want t 24 a 12 b 12', json_encode($rs8)));

// Round 18t, defect CI: §4.5's automatic minimum size was lifted off a scroll
// container in the column axis only, so a row item whose `overflow` is hidden
// was floored at its own longest word and refused to shrink. The condition is a
// **scroll container** rather than a box that clips, which is what leaves
// `overflow: clip` alone in both axes.
$flexWidths = static function (string $items, string $container = ''): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}body{line-height:12pt}'
        . '.r{display:flex;width:45pt;height:30pt;' . $container . '}</style>'
        . '<div class="r">' . $items . '</div>',
        PAGE_W,
        PAGE_H,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutWidth, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$longWord = '<div id="a" style="width:60pt;%s">Hamburgefonstiv</div><div id="b" style="width:60pt">x</div>';

// `ZW-row-automin-overflow.html` `v1`: 22.500 in Chrome, where the item was
// floored at its own min-content 60.000 and squeezed the one beside it to 4.500.
foreach (['overflow:hidden', 'overflow:auto', 'overflow:scroll'] as $scroller) {
    $shrunk = $flexWidths(sprintf($longWord, $scroller));

    ok("a row flex item declaring {$scroller} has no automatic minimum",
        $shrunk === ['a' => 22.5, 'b' => 22.5],
        sprintf('%s, want a 22.5 b 22.5', json_encode($shrunk)));
}

// `v0` is the control: the same item with no `overflow` on it.
$v0 = $flexWidths(sprintf($longWord, ''));

ok('a row flex item with visible overflow keeps its content floor',
    $v0 === ['a' => 60.0, 'b' => 4.5], sprintf('%s, want a 60 b 4.5', json_encode($v0)));

// `v4`: `overflow: clip` clips without scrolling, so §4.5 still applies to it.
$v4 = $flexWidths(sprintf($longWord, 'overflow:clip'));

ok('a row flex item declaring overflow:clip keeps its content floor',
    $v4 === ['a' => 60.0, 'b' => 4.5], sprintf('%s, want a 60 b 4.5', json_encode($v4)));

// `vb`: the same distinction in the **column** axis, which round 18s read off
// `overflow` and so zeroed for `clip` too.
$vb = $columnHeights('<div id="a" style="overflow:clip">one<br>two<br>three</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('a column flex item declaring overflow:clip keeps its content floor',
    $vb === ['a' => 36.0, 'b' => 12.0], sprintf('%s, want a 36 b 12', json_encode($vb)));

// `ZS` `k2` is the control at the other end: `hidden` in the column axis is a
// scroll container and still has no automatic minimum, which is round 18s's.
$k2 = $columnHeights('<div id="a" style="overflow:hidden">one<br>two<br>three</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('a column flex item declaring overflow:hidden still shrinks past its content',
    $k2 === ['a' => 13.333, 'b' => 16.667], sprintf('%s, want a 13.333 b 16.667', json_encode($k2)));

// Round 18t, defect CK: a percentage `height` on a table **cell** is dropped. A
// cell is measured with no available height, so the percentage has nothing to
// resolve against; CSS 2.1 §17.5.3 makes it a minimum for the row instead.
//
// `ZX-cell-height-percent.html` `ck0`: 37.500 / 112.500 in Chrome, where the
// declaration was read nowhere and the table's surplus was shared 75 / 75.
$ck0 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a"><td style="height:25%">alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a percentage height on a cell is a minimum for its row',
    $ck0 === ['t' => 150.0, 'a' => 37.5, 'b' => 112.5],
    sprintf('%s, want t 150 a 37.5 b 112.5', json_encode($ck0)));

// `ck6`: read alongside the row's own declaration, the larger of the two.
$ck6 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a" style="height:10%">'
    . '<td style="height:25%">alpha</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a cell\'s percentage outranks a smaller one on its row',
    $ck6 === ['t' => 150.0, 'a' => 37.5, 'b' => 112.5],
    sprintf('%s, want t 150 a 37.5 b 112.5', json_encode($ck6)));

// `ck7`: the cell's own padding is inside it, not added to it.
$ck7 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a">'
    . '<td style="height:25%;padding:8px">alpha</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a cell\'s padding sits inside its percentage height',
    $ck7 === ['t' => 150.0, 'a' => 37.5, 'b' => 112.5],
    sprintf('%s, want t 150 a 37.5 b 112.5', json_encode($ck7)));

// `ck4`: a minimum and not a size, and the row is pinned out of the split with
// it whether or not the declaration wins.
$ck4 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a">'
    . '<td style="height:5%">one<br>two<br>three</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a cell percentage under its own content leaves the content\'s height',
    $ck4 === ['t' => 150.0, 'a' => 36.0, 'b' => 114.0],
    sprintf('%s, want t 150 a 36 b 114', json_encode($ck4)));

// `ck5`: the basis is the table's content height less the **outer** border
// spacing, which is a quarter of 135 here and not of 150 or of 127.5.
$ck5 = $rowHeights('<table id="t" style="width:120px;height:200px;border-spacing:10px"><tr id="a">'
    . '<td style="height:25%">alpha</td></tr><tr id="b"><td>beta</td></tr></table>');

ok('a percentage resolves against the table less its outer spacing',
    $ck5 === ['t' => 150.0, 'a' => 33.75, 'b' => 93.75],
    sprintf('%s, want t 150 a 33.75 b 93.75', json_encode($ck5)));

// `ck1` and `ck8` are the controls: a percentage on a table with no height at
// all, and the same table with nothing declared anywhere in it.
$ck1 = $rowHeights('<table id="t" style="width:120px"><tr id="a"><td style="height:50%">alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a cell percentage on a table with no height is auto',
    $ck1 === ['t' => 24.0, 'a' => 12.0, 'b' => 12.0],
    sprintf('%s, want t 24 a 12 b 12', json_encode($ck1)));

$ck8 = $rowHeights('<table id="t" style="width:120px;height:200px"><tr id="a"><td>alpha</td></tr>'
    . '<tr id="b"><td>beta</td></tr></table>');

ok('a table with nothing declared in its rows is unchanged',
    $ck8 === ['t' => 150.0, 'a' => 75.0, 'b' => 75.0],
    sprintf('%s, want t 150 a 75 b 75', json_encode($ck8)));

// Round 18u, defect CN: `overflow: clip` was a scroll container to everything
// except §4.5. It clips without scrolling, so it establishes no block
// formatting context and it synthesizes no baseline, and both of those read
// `Node::$overflow` where the question they ask is `Node::$scrollContainer`.
$clipWrap = static function (string $style): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
        . '.g{width:200px}</style>'
        . '<div id="g" class="g" style="' . $style . '"><div id="c" style="height:16px;margin-top:16px"></div></div>',
        PAGE_W,
        PAGE_H,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutHeight, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

// `ZY-overflow-longhand.html` `yk`: 12.000 in Chrome, the child's margin
// escaping through the wrapper's open top edge, where this contained it and
// the wrapper was 24.000.
$yk = $clipWrap('overflow:clip');

ok('an overflow:clip wrapper does not contain its child\'s margin',
    $yk === ['g' => 12.0, 'c' => 12.0], sprintf('%s, want g 12 c 12', json_encode($yk)));

// `yl`: the two-value shorthand with one clipping axis is the same answer.
$yl = $clipWrap('overflow:visible clip');

ok('an overflow:visible clip wrapper does not contain it either',
    $yl === ['g' => 12.0, 'c' => 12.0], sprintf('%s, want g 12 c 12', json_encode($yl)));

// `ym` and `yj` are the controls at either end: `hidden` is a scroll container
// and does contain the margin, and a wrapper with nothing declared does not.
$ym = $clipWrap('overflow:hidden');

ok('an overflow:hidden wrapper still contains its child\'s margin',
    $ym === ['g' => 24.0, 'c' => 12.0], sprintf('%s, want g 24 c 12', json_encode($ym)));

$yj = $clipWrap('');

ok('a wrapper with no overflow declared still lets the margin escape',
    $yj === ['g' => 12.0, 'c' => 12.0], sprintf('%s, want g 12 c 12', json_encode($yj)));

$clipInline = static function (string $style): float {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
        . '.g{width:200px}</style>'
        . '<div id="g" class="g">A<span style="display:inline-block;width:60px;height:40px;'
        . $style . '">g</span><span style="display:inline-block;width:8px;height:8px"></span></div>',
        PAGE_W,
        PAGE_H,
    );

    $found = 0.0;
    $walk  = static function (Node $n) use (&$walk, &$found): void {
        if ($n->anchorId === 'g') {
            $found = round($n->layoutHeight, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $found;
};

// `yp`: 30.000 in Chrome, the inline-block sitting on its own line's baseline
// exactly as `yn` with nothing declared does, where this synthesized one from
// its bottom margin edge and the line grew to 33.700.
ok('an overflow:clip inline-block keeps its own last line\'s baseline',
    $clipInline('overflow:clip') === 30.0,
    sprintf('%s, want 30', json_encode($clipInline('overflow:clip'))));

// `yo` and `yn` are the controls: `hidden` does synthesize one, and what is
// left over the line is the strut's own descent. Chrome says 33.000 on
// `PZ-clip-inline-baseline.html`; this was 33.701 until round 21 landed the
// Helvetica ascent/descent split, then 32.891 for fifty rounds because the
// strut's own descent came off a FITTED pair. **Round 71 built the base-14 line
// box out of the face's own rounded box instead and the last 0.109 went with
// it**, so this reads Chrome's number exactly now.
ok('an overflow:hidden inline-block still sits on its bottom margin edge',
    $clipInline('overflow:hidden') === 33.0,
    sprintf('%s, want 33', json_encode($clipInline('overflow:hidden'))));

ok('an inline-block with no overflow declared is unchanged',
    $clipInline('') === 30.0,
    sprintf('%s, want 30', json_encode($clipInline(''))));


// Round 18u, defect CO: a flex item's synthesized baseline had CSS 2.1
// §10.8.1's overflow exception in it, which is the **inline** formatting
// context's rule. CSS Flexible Box §8.3 synthesizes one only when the item has
// no baseline at all, whatever its overflow, so a baseline-aligned item that
// clips was pushed down by everything between its first line and its bottom
// margin edge.
$baselineRow = static function (string $style, string $content = 'g'): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
        . '.q{display:flex;align-items:baseline;width:200px}</style>'
        . '<div id="q" class="q"><div style="width:60px;height:40px;' . $style . '">' . $content . '</div>'
        . '<div id="b" style="width:40px">x</div></div>',
        PAGE_W,
        PAGE_H,
    );

    $out  = [];
    $walk = static function (Node $n, float $top = 0.0) use (&$walk, &$out): void {
        if ($n->anchorId === 'q') {
            $out['q'] = round($n->layoutHeight, 3);
            $top      = $n->y;
        }

        if ($n->anchorId === 'b') {
            $out['b'] = round($n->y - $top, 3);
        }

        foreach ($n->children as $c) {
            $walk($c, $top);
        }
    };
    $walk($tree);

    return $out;
};

// `ZY-overflow-longhand.html` `yr`: the neighbour at 0.000 in Chrome and the
// row 30.000 tall, where this synthesized a baseline from the item's bottom
// margin edge and pushed the neighbour down 21.701.
$yr = $baselineRow('overflow:hidden');

ok('a baseline-aligned flex item that clips keeps its own first baseline',
    $yr === ['q' => 30.0, 'b' => 0.0], sprintf('%s, want q 30 b 0', json_encode($yr)));

// `ys`: `clip` is the same answer, which it has to be, since overflow is not
// part of the question at all here.
$ys = $baselineRow('overflow:clip');

ok('an overflow:clip flex item keeps its own first baseline too',
    $ys === ['q' => 30.0, 'b' => 0.0], sprintf('%s, want q 30 b 0', json_encode($ys)));

// `yq` is the control: the same row with nothing declared, which never moved.
$yq = $baselineRow('');

ok('a baseline-aligned flex item with no overflow declared is unchanged',
    $yq === ['q' => 30.0, 'b' => 0.0], sprintf('%s, want q 30 b 0', json_encode($yq)));

// `yt` is the other control: an item with no line box of its own still
// synthesizes one from its bottom margin edge, which is the half of the rule
// that stays. Chrome puts the neighbour at 21.000 and the 0.700 beside it is
// AM, the Helvetica ascent/descent split.
$ye = $baselineRow('', '');

ok('an item with no line of its own still synthesizes from its bottom edge',
    ($ye['b'] ?? -1.0) > 0.0, sprintf('%s, want b above 0', json_encode($ye)));


// Round 18u, defect CL: `overflow-x` and `overflow-y` reached nothing, because
// `StyleResolver` carried the shorthand and neither longhand. CSS Overflow §3
// computes the other axis to `auto` as soon as one of them scrolls, so one
// longhand is enough to make the box both clip and scroll, and the value is
// computed per axis out of all three declarations.
//
// `ZY-overflow-longhand.html` `y2`, `y3`, `yb`, `yc` and `yd`: 22.500 in
// Chrome, exactly as the shorthand's `y1`, where the declaration reached
// nothing and the item kept its own 60.000 min-content floor.
foreach ([
    'overflow-x:hidden',
    'overflow-y:hidden',
    'overflow-x:auto',
    'overflow-y:scroll',
    'overflow-x:hidden;overflow-y:clip',
] as $longhand) {
    $shrunk = $flexWidths(sprintf($longWord, $longhand));

    ok("a row flex item declaring {$longhand} has no automatic minimum",
        $shrunk === ['a' => 22.5, 'b' => 22.5],
        sprintf('%s, want a 22.5 b 22.5', json_encode($shrunk)));
}

// `y4`, `y5` and `y9`: one clipping axis and one visible one is no scroll
// container, so §4.5 still applies. They read 60.000 before the hunk as well,
// `y4` and `y5` because the declaration reached nothing and `y9` because the
// shorthand's second value was matched by a regular expression rather than
// computed, and all three are 60.000 for the right reason now.
foreach (['overflow-x:clip', 'overflow-y:clip', 'overflow:visible clip'] as $clipper) {
    $kept = $flexWidths(sprintf($longWord, $clipper));

    ok("a row flex item declaring {$clipper} keeps its content floor",
        $kept === ['a' => 60.0, 'b' => 4.5],
        sprintf('%s, want a 60 b 4.5', json_encode($kept)));
}

// `y6`, `y7` and `y8`: a `clip` or a `visible` axis beside a scrolling one
// computes to `hidden` and `auto` respectively, so the box scrolls in both.
foreach (['overflow:clip hidden', 'overflow:hidden clip', 'overflow:visible hidden'] as $mixed) {
    $shrunk = $flexWidths(sprintf($longWord, $mixed));

    ok("a row flex item declaring {$mixed} has no automatic minimum",
        $shrunk === ['a' => 22.5, 'b' => 22.5],
        sprintf('%s, want a 22.5 b 22.5', json_encode($shrunk)));
}

// `ye`: the same longhand in the **column** axis, 13.336 in Chrome against
// `yg`'s 36.000 with nothing declared.
$ye = $columnHeights('<div id="a" style="overflow-x:hidden">one<br>two<br>three</div>'
    . '<div id="b" style="height:45pt">two</div>');

ok('a column flex item declaring overflow-x:hidden shrinks past its content',
    $ye === ['a' => 13.333, 'b' => 16.667], sprintf('%s, want a 13.333 b 16.667', json_encode($ye)));

// `yh` and `yi`: a longhand makes the box a scroll container, so it contains
// its child's margin, and a `clip` longhand does not.
$yh = $clipWrap('overflow-x:hidden');

ok('an overflow-x:hidden wrapper contains its child\'s margin',
    $yh === ['g' => 24.0, 'c' => 12.0], sprintf('%s, want g 24 c 12', json_encode($yh)));

$yi = $clipWrap('overflow-x:clip');

ok('an overflow-x:clip wrapper still lets the margin escape',
    $yi === ['g' => 12.0, 'c' => 12.0], sprintf('%s, want g 12 c 12', json_encode($yi)));

// The clip itself is per axis, because Chrome clips per axis.
// `ZZ-overflow-clip-axes.html`: the child of a 45x30pt box overhanging both
// edges is cut to 45 x 60 under `overflow-x: clip` (`w4`), to 75 x 30 under
// `overflow-y: clip` (`w5`) and to 45 x 30 under `overflow: hidden` (`w1`).
$clipOf = static function (string $style): ?array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}body{width:400px}'
        . '.k{width:45pt;height:30pt}</style>'
        . '<div class="k" style="' . $style . '"><div id="c" style="width:75pt;height:60pt"></div></div>',
        PAGE_W,
        400.0,
    );

    foreach ((new Fragmenter(400.0))->fragment($tree) as $page) {
        foreach ($page as $fragment) {
            if ($fragment->node->anchorId === 'c') {
                return $fragment->clip === null
                    ? null
                    : array_map(static fn(float $v): float => round($v, 3), array_slice($fragment->clip, 0, 4));
            }
        }
    }

    return null;
};

ok('overflow-x:clip cuts the inline axis and leaves the block one',
    $clipOf('overflow-x:clip') === [0.0, -1.0E+9, 45.0, 2.0E+9],
    json_encode($clipOf('overflow-x:clip')));

ok('overflow-y:clip cuts the block axis and leaves the inline one',
    $clipOf('overflow-y:clip') === [-1.0E+9, 0.0, 2.0E+9, 30.0],
    json_encode($clipOf('overflow-y:clip')));

ok('overflow:hidden still cuts both axes at the border box',
    $clipOf('overflow:hidden') === [0.0, 0.0, 45.0, 30.0],
    json_encode($clipOf('overflow:hidden')));

ok('a box with no overflow declared clips nothing at all',
    $clipOf('') === null, json_encode($clipOf('')));


// Round 18v, defect CP: CSS 2.1 §17.4's box-level properties on a table
// element are used on the table WRAPPER box, not on the table box inside it.
// `HtmlBuilder::withCaption()` built the wrapper and left every one of them on
// the table it wrapped, so a `margin-bottom` landed between the table and a
// bottom caption, a float carried the table out from under its own caption and
// a caption's own margin collapsed out through the wrapper's edges.
//
// Every number below is Chrome's, off
// `docs/harness/probes/O-table-margin-wrapper.html`, read at absolute
// coordinates because `getBoundingClientRect` on a `<table>` returns Chrome's
// wrapper box where this engine's `anchorId` sits on the inner table box.
$wrapperRects = static function (string $group): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . '.g{height:240px;padding:4px 0}.gf{display:flow-root;height:240px;padding:4px 0}'
        . '.gr{position:relative;height:240px;padding:4px 0}'
        . '.m{height:20px}.mm{height:20px;margin-top:16px}.p{height:20px;margin-bottom:16px}'
        . '.f{float:left;width:100px;height:40px}'
        . 'table{border-spacing:0;margin:0}td,th{padding:0}</style>'
        . $group,
        // The probe pins its containing block with `body { width: 400px }`,
        // which is 300pt. This harness has no body box at all (the root node
        // IS the page), so the same 300 is passed as the page width.
        300.0,
        3000.0,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = [round($n->x, 3), round($n->y, 3)];
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$twoRows = '<tr id="r"><td id="a">alpha</td></tr><tr id="s"><td id="b">beta</td></tr>';

// `o2`: the table's own `margin-top` is above the caption, not between the
// caption and the table. Chrome puts the caption at 9.000 and the first row at
// 21.000; this had the caption at 3.000 and the row at 21.000.
$o2 = $wrapperRects('<div class="g"><table style="width:120px;height:150px;margin-top:8px">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a table\'s margin-top sits above its caption, not under it',
    $o2['c'] === [0.0, 9.0] && $o2['r'] === [0.0, 21.0],
    json_encode($o2));

// `o4`: and its `margin-bottom` is below a `caption-side: bottom` caption
// rather than between the table and it. Chrome 115.500, this had 121.500.
$o4 = $wrapperRects('<div class="g"><table style="width:120px;height:150px;margin-bottom:8px">'
    . '<caption id="c" style="caption-side:bottom">cap</caption>' . $twoRows
    . '</table><div class="m" id="m"></div></div>');

ok('a table\'s margin-bottom sits below a bottom caption',
    $o4['c'] === [0.0, 115.5] && $o4['m'] === [0.0, 133.5],
    json_encode($o4));

// `o3` and `o5` are the two controls that were already exact: a `margin-bottom`
// under a **top** caption and a `margin-top` over a **bottom** one are outside
// both boxes wherever the declaration sits.
$o3 = $wrapperRects('<div class="g"><table style="width:120px;height:150px;margin-bottom:8px">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a table\'s margin-bottom under a top caption is unmoved',
    $o3['c'] === [0.0, 3.0] && $o3['m'] === [0.0, 133.5],
    json_encode($o3));

$o5 = $wrapperRects('<div class="g"><table style="width:120px;height:150px;margin-top:8px">'
    . '<caption id="c" style="caption-side:bottom">cap</caption>' . $twoRows
    . '</table><div class="m" id="m"></div></div>');

ok('a table\'s margin-top over a bottom caption is unmoved',
    $o5['r'] === [0.0, 9.0] && $o5['c'] === [0.0, 121.5],
    json_encode($o5));

// `o9`: the wrapper establishes a block formatting context, so the caption's
// own `margin-top` is contained rather than collapsing with the margin of the
// block before the table. Chrome puts the caption at 36.000, which is the
// preceding 16px margin **plus** the caption's own 8px; this had 30.000, the
// two collapsed to the larger.
$o9 = $wrapperRects('<div class="g"><div class="p" id="p"></div>'
    . '<table style="width:120px;height:150px"><caption id="c" style="margin-top:8px">cap</caption>'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a caption\'s margin-top does not collapse out of the wrapper',
    $o9['c'] === [0.0, 36.0] && $o9['r'] === [0.0, 48.0],
    json_encode($o9));

// `o8`: and the same underneath, against the following block's own margin.
$o8 = $wrapperRects('<div class="g"><table style="width:120px;height:150px">'
    . '<caption id="c" style="caption-side:bottom;margin-bottom:8px">cap</caption>' . $twoRows
    . '</table><div class="mm" id="m"></div></div>');

ok('a caption\'s margin-bottom does not collapse out of the wrapper',
    $o8['c'] === [0.0, 115.5] && $o8['m'] === [0.0, 145.5],
    json_encode($o8));

// `oc` is that pair's control: with nothing to collapse against, the caption's
// bottom margin reads the same either way.
$oc = $wrapperRects('<div class="g"><table style="width:120px;height:150px">'
    . '<caption id="c" style="caption-side:bottom;margin-bottom:8px">cap</caption>' . $twoRows
    . '</table><div class="m" id="m"></div></div>');

ok('a caption\'s bottom margin against a marginless block is unmoved',
    $oc['c'] === [0.0, 115.5] && $oc['m'] === [0.0, 133.5],
    json_encode($oc));

// `oa`: `margin: auto` centres the WRAPPER, so the caption centres with the
// table. Chrome puts both at x 105.000; the caption was at x 0.000, because the
// auto margins were resolved against a containing block that was the table's
// own used width.
$oa = $wrapperRects('<div class="g"><table style="width:120px;height:150px;margin:0 auto">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('margin: auto centres the wrapper, caption included',
    $oa['c'] === [105.0, 3.0] && $oa['r'] === [105.0, 15.0],
    json_encode($oa));

// `ob`: the same declaration with no caption at all, the control that says the
// centring itself was never broken.
$ob = $wrapperRects('<div class="g"><table id="t" style="width:120px;height:150px;margin:0 auto">'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('margin: auto on a table with no caption is unmoved',
    $ob['t'] === [105.0, 3.0], json_encode($ob));

// `og`: `float` belongs to the wrapper too, so the caption floats with the
// table and the block after them rises to the top of the float's line. Chrome
// puts the caption at (6.000, 9.000) and the block at 3.000; this left the
// caption in the flow at (0.000, 3.000) and the block below it at 15.000.
$og = $wrapperRects('<div class="gf"><table style="width:120px;height:150px;float:left;margin:8px">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a float on a table takes its caption with it',
    $og['c'] === [6.0, 9.0] && $og['m'] === [0.0, 3.0],
    json_encode($og));

$oh = $wrapperRects('<div class="gf"><table id="t" style="width:120px;height:150px;float:left;margin:8px">'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a float on a table with no caption is unmoved',
    $oh['t'] === [6.0, 9.0] && $oh['m'] === [0.0, 3.0],
    json_encode($oh));

// `oi`: a relative offset moves the wrapper, so the caption moves with it.
$oi = $wrapperRects('<div class="g"><table style="width:120px;height:150px;position:relative;top:8px;left:8px">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a relative offset on a table moves its caption too',
    $oi['c'] === [6.0, 9.0] && $oi['r'] === [6.0, 21.0],
    json_encode($oi));

$oj = $wrapperRects('<div class="g"><table id="t" style="width:120px;height:150px;position:relative;top:8px;left:8px">'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a relative offset on a table with no caption is unmoved',
    $oj['t'] === [6.0, 9.0] && $oj['m'] === [0.0, 115.5],
    json_encode($oj));

// `ok`: `clear` is not in §17.4's list and Chrome moves it anyway, because a
// clear left on the table box has nothing to clear: the wrapper's own
// formatting context holds no floats. Chrome puts the whole wrapper under the
// float at 33.000; this put the caption beside it at 3.000 and the table box
// to the right of it at x 75.000.
$ok = $wrapperRects('<div class="gf"><div class="f" id="f"></div>'
    . '<table style="width:120px;height:150px;clear:left"><caption id="c">cap</caption>'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('a clear on a table clears the whole wrapper',
    $ok['c'] === [0.0, 33.0] && $ok['r'] === [0.0, 45.0],
    json_encode($ok));

$ol = $wrapperRects('<div class="gf"><div class="f" id="f"></div>'
    . '<table id="t" style="width:120px;height:150px;clear:left">' . $twoRows
    . '</table><div class="m" id="m"></div></div>');

ok('a clear on a table with no caption is unmoved',
    $ol['t'] === [0.0, 33.0] && $ol['m'] === [0.0, 145.5],
    json_encode($ol));

// `om`: an absolutely positioned table is positioned by its wrapper, so the
// caption is inside the positioned box rather than left behind in the flow.
$om = $wrapperRects('<div class="gr"><table style="width:120px;height:150px;position:absolute;top:8px;left:8px;margin:8px">'
    . '<caption id="c">cap</caption>' . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('an absolutely positioned table positions its wrapper',
    $om['c'] === [12.0, 12.0] && $om['r'] === [12.0, 24.0],
    json_encode($om));

$on = $wrapperRects('<div class="gr"><table id="t" style="width:120px;height:150px;position:absolute;top:8px;left:8px;margin:8px">'
    . $twoRows . '</table><div class="m" id="m"></div></div>');

ok('an absolutely positioned table with no caption is unmoved',
    $on['t'] === [12.0, 12.0] && $on['m'] === [0.0, 3.0],
    json_encode($on));


// Round 18v, defect BX: a table nested in a cell of another **replaced** the
// run of repeating rows in scope instead of adding to it, so the outer table's
// `<thead>` was not painted on the continuation page at all.
// `splitContainer()` computed `$allRepeaters = $inheritedRepeaters !== [] ?
// $inheritedRepeaters : $repeaters`, and `$repeaters` is a list of RUNS now,
// one per table in scope, outermost first.
//
// Every number is Chrome's, off `docs/harness/probes/S7-fold-thead-nested.html`,
// `U2-nested-thead-wide.html`, `U3-nested-thead-outerbig.html` and
// `OA-nested-thead-bg.html`, read as colour bands out of the rendered page.
// The fragments are keyed by their background colour rather than by an id,
// because a sliced cell and a container's own decoration are both emitted as
// anonymous `rect` fragments carrying the colour and nothing else.
$nestedBands = static function (
    float $outerHead,
    float $innerHead,
    float $innerBody,
    bool $tableBackgrounds = false,
): array {
    $sheet = '<style>.s{height:240pt;margin:0}'
        . 'table{border-collapse:collapse;width:400pt;margin:0'
        . ($tableBackgrounds ? ';background:#d8d800' : '') . '}'
        . 'td,th{padding:0;vertical-align:top;line-height:12pt}'
        . 'th{background:#8b1a1a}'
        . '#inner{width:200pt' . ($tableBackgrounds ? ';background:#00c8c8' : '') . '}'
        . '#inner th{background:#1a4b8b}#inner td{background:#1b7a3a}</style>';

    $tree = layout(
        $sheet . '<div class="s"></div>'
        . '<table><thead><tr><th style="height:' . $outerHead . 'pt">outer</th></tr></thead>'
        . '<tbody><tr><td><table id="inner"><thead><tr>'
        . '<th style="height:' . $innerHead . 'pt">in</th></tr></thead>'
        . '<tbody><tr><td style="height:' . $innerBody . 'pt">alpha</td></tr></tbody>'
        . '</table></td></tr></tbody></table>',
    );

    $names = [
        'd8d800' => 'outertable',
        '00c8c8' => 'innertable',
        '8b1a1a' => 'outerhead',
        '1a4b8b' => 'innerhead',
        '1b7a3a' => 'innerbody',
    ];

    $out = [];

    foreach ((new Fragmenter(PAGE_H))->fragment($tree) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background === null) {
                continue;
            }

            $hex = vsprintf('%02x%02x%02x', array_map(
                static fn(float $c): int => (int) round($c * 255.0),
                array_slice($f->node->background, 0, 3),
            ));

            if (!isset($names[$hex])) {
                continue;
            }

            $out[$names[$hex]][] = sprintf('%d@%.0f+%.0f', $pi, $f->y, $f->h);
        }
    }

    return $out;
};

// `S7`: on the continuation page Chrome puts the outer header at 0.000, the
// inner header at 15.000 and the inner row at 30.000. The outer header was not
// painted there at all, so the inner one sat at 0.000 and the row at 15.000.
$s7 = $nestedBands(15.0, 15.0, 60.0);

ok('a nested table repeats the outer header as well as its own',
    ($s7['outerhead'] ?? []) === ['0@240+15', '1@0+15'],
    json_encode($s7['outerhead'] ?? []));

ok('the inner header sits under the outer one, and the row under that',
    ($s7['innerhead'] ?? []) === ['0@255+15', '1@15+15']
        && ($s7['innerbody'] ?? []) === ['0@270+30', '1@30+30'],
    json_encode($s7));

// `U2`: 60pt and 60pt on a 300pt page is 20% each and 40% together, and Chrome
// repeats **both** on every page. The threshold is per run, so a merged list
// summing to 120pt would have refused the pair. Page 0 carries nothing: a
// header and the row it heads go together or not at all (defect BQ).
$u2 = $nestedBands(60.0, 60.0, 525.0);

ok('two runs are each judged against a quarter of the page on their own',
    ($u2['outerhead'] ?? []) === ['1@0+60', '2@0+60', '3@0+60'],
    json_encode($u2['outerhead'] ?? []));

ok('and the inner run repeats under the outer one on every page',
    ($u2['innerhead'] ?? []) === ['1@60+60', '2@60+60', '3@60+60'],
    json_encode($u2['innerhead'] ?? []));

// `U3` is the control that must not move: a 93pt outer header is 31% of the
// page and Chrome refuses it, where the 15pt inner one is 5% and repeats. A
// merged run would have summed to 108pt and lost the inner one with it.
$u3 = $nestedBands(93.0, 15.0, 525.0);

ok('an oversized outer run is refused and the inner one still repeats',
    ($u3['outerhead'] ?? []) === ['1@0+93']
        && ($u3['innerhead'] ?? []) === ['1@93+15', '2@0+15', '3@0+15'],
    json_encode($u3));

// `OA`: the box that HOLDS a run paints its background behind its own repeated
// header, which is 0.000 for the outer table and **15.000** for the inner one,
// under the outer's header and behind its own. The two were the same number
// until a table could sit in a cell of another.
$oa = $nestedBands(15.0, 15.0, 420.0, true);

ok('a nested table\'s background resumes where its own repeated header starts',
    ($oa['innertable'] ?? []) === ['0@255+45', '1@15+285', '2@15+135'],
    json_encode($oa['innertable'] ?? []));

ok('and the outer table\'s background still resumes at the top of the page',
    ($oa['outertable'] ?? []) === ['0@240+60', '1@0+300', '2@0+150'],
    json_encode($oa['outertable'] ?? []));

// Round 18v, defect CD: a table whose columns are **all** zero-width ignored
// its own declared width. `distribute()` shares a surplus in proportion to what
// each column already has, and a table of empty cells has nothing to be in
// proportion to, so it returned the zeros it started with and the table, its
// rows and its cells were 0.000 wide.
//
// Chrome splits the declared width **equally** between the columns, off
// `docs/harness/probes/OB-table-zero-columns.html`.
$columnWidths = static function (string $table): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{border-spacing:0;margin:0}td,th{padding:0}</style>'
        . $table,
        300.0,
        1200.0,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = round($n->layoutWidth, 3);
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

// `b1`: two empty cells in a `width: 120px` table, 90.000 wide with two 45.000
// columns in Chrome, 0.000 everywhere here.
$b1 = $columnWidths('<table id="t" style="width:120px"><tr id="r"><td id="a"></td><td id="b"></td></tr></table>');

ok('an all-empty table keeps its declared width and splits it equally',
    $b1 === ['t' => 90.0, 'r' => 90.0, 'a' => 45.0, 'b' => 45.0], json_encode($b1));

// `b2`: three of them, 30.000 each.
$b2 = $columnWidths('<table id="t" style="width:120px"><tr id="r">'
    . '<td id="a"></td><td id="b"></td><td id="c"></td></tr></table>');

ok('three empty columns take a third of it each',
    $b2 === ['t' => 90.0, 'r' => 90.0, 'a' => 30.0, 'b' => 30.0, 'c' => 30.0], json_encode($b2));

// `b6`: a percentage width resolves first and is then split the same way.
$b6 = $columnWidths('<table id="t" style="width:50%"><tr id="r"><td id="a"></td><td id="b"></td></tr></table>');

ok('a percentage width on an all-empty table is split equally too',
    $b6 === ['t' => 150.0, 'r' => 150.0, 'a' => 75.0, 'b' => 75.0], json_encode($b6));

// `b7`: a column with a declared width takes it off the top and the empty one
// gets the rest, 22.500 and 67.500.
$b7 = $columnWidths('<table id="t" style="width:120px"><tr id="r">'
    . '<td id="a" style="width:30px"></td><td id="b"></td></tr></table>');

ok('a pinned column is paid first and the empty one takes the remainder',
    $b7 === ['t' => 90.0, 'r' => 90.0, 'a' => 22.5, 'b' => 67.5], json_encode($b7));

// `b3` is the control that puts the defect on the ALL-zero case: one column
// with anything at all in it takes the whole width and the empty one stays at
// 0.000, which is Chrome's answer and was already this engine's.
$b3 = $columnWidths('<table id="t" style="width:120px"><tr id="r">'
    . '<td id="a"></td><td id="b">be</td></tr></table>');

ok('one column with content in it still takes the whole width',
    $b3 === ['t' => 90.0, 'r' => 90.0, 'a' => 0.0, 'b' => 90.0], json_encode($b3));

// `b9` is the second control: a cell whose only content is its own padding is
// not empty, and the proportional share is right for it.
$b9 = $columnWidths('<table id="t" style="width:120px"><tr id="r">'
    . '<td id="a" style="padding:0 8px"></td><td id="b"></td></tr></table>');

ok('a cell with nothing but padding is not an empty column',
    $b9 === ['t' => 90.0, 'r' => 90.0, 'a' => 90.0, 'b' => 0.0], json_encode($b9));

// `b5` is the third: with no declared width there is no surplus to share and
// an all-empty table is 0.000 wide on both engines.
$b5 = $columnWidths('<table id="t"><tr id="r"><td id="a"></td><td id="b"></td></tr></table>');

ok('an all-empty table with no declared width is still nothing',
    $b5 === ['t' => 0.0, 'r' => 0.0, 'a' => 0.0, 'b' => 0.0], json_encode($b5));

// Round 18v, defect BG: a `viewBox` is a coordinate system and a **ratio**,
// not a length. `SvgDocument::parse()` fell back to the viewBox's own width
// and height as the file's size, so an `<svg>` declaring nothing else was laid
// out at 240 **points** wherever it sat, where CSS Images §4 gives it an
// intrinsic ratio and no intrinsic size at all.
//
// Every number is Chrome's, off `docs/harness/probes/OC-svg-viewbox-ratio.html`.
$svgBox = static function (string $body, float $page = 300.0): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . '.n{width:200px}</style>' . $body,
        $page,
        2400.0,
        // `vb240.svg` and `sq240.svg` are copies of the assets beside the
        // probe pages, kept inside the tracked test tree because `docs/` is
        // not part of a checkout. An `<img src>` resolves through `AssetPath`
        // against the resolver's base path: without it every image in this
        // block is 0.000 wide.
        __DIR__ . '/support/assets',
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = [round($n->layoutWidth, 3), round($n->layoutHeight, 3)];
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$vb = '<svg id="s" style="display:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240">'
    . '<circle cx="120" cy="120" r="100" fill="#2a6"/></svg>';

// `c0`: it fills the containing block and the ratio answers for the height,
// 300.000 x 300.000 in a 300pt block where this gave 180.000 x 180.000.
$c0 = $svgBox('<div id="g">' . $vb . '</div>');

ok('a viewBox-only svg fills its containing block',
    ($c0['s'] ?? []) === [300.0, 300.0], json_encode($c0['s'] ?? []));

// `c1`: the same file in a 150pt block, which is what says it is a ratio and
// not a size.
$c1 = $svgBox('<div id="g" class="n">' . $vb . '</div>');

ok('and it is the containing block\'s width, not the file\'s',
    ($c1['s'] ?? []) === [150.0, 150.0], json_encode($c1['s'] ?? []));

// `c2`: a 4:1 viewBox, so the height is a quarter of the width.
$c2 = $svgBox('<div id="g"><svg id="s" style="display:block" xmlns="http://www.w3.org/2000/svg"'
    . ' viewBox="0 0 240 60"><circle cx="30" cy="30" r="25" fill="#2a6"/></svg></div>');

ok('the ratio is the viewBox\'s own',
    ($c2['s'] ?? []) === [300.0, 75.0], json_encode($c2['s'] ?? []));

// `c9`: no viewBox and no size is neither a ratio nor an intrinsic size, and
// CSS Images §5.2's default object size is the whole answer: 300x150px.
$c9 = $svgBox('<div id="g"><svg id="s" style="display:block" xmlns="http://www.w3.org/2000/svg">'
    . '<circle cx="120" cy="120" r="100" fill="#2a6"/></svg></div>');

ok('an svg with neither a viewBox nor a size is the default object size',
    ($c9['s'] ?? []) === [225.0, 112.5], json_encode($c9['s'] ?? []));

// `cb`: in a flex row the base size is the line's own width, and an image's
// content is the picture, so §4.5's content size suggestion floors it there
// and the item beside it overflows rather than squeezing it.
$cb = $svgBox('<div id="g" class="n"><div id="f" style="display:flex">'
    . '<img id="s" src="vb240.svg"><div id="t">lpha beta gamma delta</div></div></div>');

ok('a viewBox-only img fills a flex line and does not shrink',
    ($cb['s'] ?? []) === [150.0, 150.0], json_encode($cb['s'] ?? []));

// `ca`: an inline `<svg>` is a document fragment with no min-content size at
// all, so the same base size shrinks with the line. Chrome's 93.141 against
// this 93.149 is the font metric rounding every text width carries.
$ca = $svgBox('<div id="g" class="n"><div id="f" style="display:flex">'
    . '<svg id="s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240">'
    . '<circle cx="120" cy="120" r="100" fill="#2a6"/></svg>'
    . '<div id="t">lpha beta gamma delta</div></div></div>');

ok('a viewBox-only inline svg shrinks with the flex line',
    abs(($ca['s'][0] ?? 0.0) - 93.141) < 0.05, json_encode($ca['s'] ?? []));

// The three controls, all exact on both engines before this hunk and after it:
// one declared axis is enough to leave the ratio alone (`c6`, `c7`), and a
// file that declares both is an intrinsic size like any other (`c8`).
$c6 = $svgBox('<div id="g"><svg id="s" style="display:block;width:120px" xmlns="http://www.w3.org/2000/svg"'
    . ' viewBox="0 0 240 240"><circle cx="120" cy="120" r="100" fill="#2a6"/></svg></div>');

ok('a declared width still resolves the height through the ratio',
    ($c6['s'] ?? []) === [90.0, 90.0], json_encode($c6['s'] ?? []));

$c7 = $svgBox('<div id="g"><svg id="s" style="display:block;height:120px" xmlns="http://www.w3.org/2000/svg"'
    . ' viewBox="0 0 240 240"><circle cx="120" cy="120" r="100" fill="#2a6"/></svg></div>');

ok('a declared height still resolves the width through the ratio',
    ($c7['s'] ?? []) === [90.0, 90.0], json_encode($c7['s'] ?? []));

$c8 = $svgBox('<div id="g"><svg id="s" style="display:block" width="240" height="240"'
    . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240">'
    . '<circle cx="120" cy="120" r="100" fill="#2a6"/></svg></div>');

ok('a file that declares both axes is an intrinsic size',
    ($c8['s'] ?? []) === [180.0, 180.0], json_encode($c8['s'] ?? []));

// Round 18v, defect CE: a `<tr>`'s box was laid out at the table's full inner
// width and its background painted across all of it, border-spacing gutters
// included. CSS 2.1 §17.5.1 makes the row box the cells' area and leaves the
// spacing to the **table**'s own background.
//
// Chrome's numbers are off `docs/harness/probes/OD-row-spacing-band.html`.
$rowBox = static function (string $table): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{background:#d8d800;margin:0}tr{background:#26a}'
        . 'td,th{padding:0;background:transparent}</style>' . $table,
        300.0,
        900.0,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = [round($n->x, 3), round($n->layoutWidth, 3)];
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$cells = '<td id="a">al</td><td id="b">be</td>';

// `d1`: the row box is inset by one gutter on each side, x 7.500 w 75.000 in a
// 90.000 table, where this laid it out at x 0.000 w 90.000.
$d1 = $rowBox('<table id="t" style="width:120px;border-spacing:10px"><tr id="r">' . $cells . '</tr></table>');

ok('a row box is inset by the border spacing',
    ($d1['r'] ?? []) === [7.5, 75.0], json_encode($d1['r'] ?? []));

// `d4`: the table's own border and padding are outside the spacing, so the row
// starts at 4px + 8px + 10px.
$d4 = $rowBox('<table id="t" style="width:120px;border-spacing:10px;padding:8px;border:4px solid #333">'
    . '<tr id="r">' . $cells . '</tr></table>');

ok('and the table\'s own border and padding are outside it',
    ($d4['r'] ?? []) === [16.5, 57.0], json_encode($d4['r'] ?? []));

// `d0` and `d3` are the two controls: with no spacing, and with the spacing
// dropped by `border-collapse: collapse`, the row box is the table's inner
// width and always was.
$d0 = $rowBox('<table id="t" style="width:120px;border-spacing:0"><tr id="r">' . $cells . '</tr></table>');

ok('a row with no border spacing is unmoved',
    ($d0['r'] ?? []) === [0.0, 90.0], json_encode($d0['r'] ?? []));

$d3 = $rowBox('<table id="t" style="width:120px;border-spacing:10px;border-collapse:collapse">'
    . '<tr id="r">' . $cells . '</tr></table>');

ok('a collapsed table ignores the spacing here too',
    ($d3['r'] ?? []) === [0.0, 90.0], json_encode($d3['r'] ?? []));

// The paint half: the row's background covers its cells and not the gutter
// between them. `d1`'s two bands are 27.773 and 39.727 wide with 7.500 of the
// table's own colour showing between them.
$rowBands = static function (string $table): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{background:#d8d800;margin:0}tr{background:#26a}'
        . 'td,th{padding:0;background:transparent}</style>' . $table,
        300.0,
        900.0,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId === 'r') {
            // `property_exists` because this runs against the pre-hunk engine
            // too, where the field does not exist at all: reading it there is
            // a fatal rather than the empty list this asks about.
            $out = array_map(
                static fn(array $band): array => [round($band[0], 3), round($band[1], 3)],
                property_exists($n, 'backgroundBands') ? $n->backgroundBands : [],
            );
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$bands = $rowBands('<table id="t" style="width:120px;border-spacing:10px"><tr id="r">' . $cells . '</tr></table>');

ok('a row paints one band per cell, not one across the gutter',
    count($bands) === 2
        && abs($bands[0][0] - 0.0) < 0.001 && abs($bands[0][1] - 27.786) < 0.05
        && abs($bands[1][0] - 35.286) < 0.05 && abs($bands[1][1] - 39.714) < 0.05,
    json_encode($bands));

ok('a row with no spacing paints its whole box',
    $rowBands('<table id="t" style="width:120px;border-spacing:0"><tr id="r">' . $cells . '</tr></table>') === [],
    json_encode($rowBands('<table id="t" style="width:120px;border-spacing:0"><tr id="r">' . $cells . '</tr></table>')));

// Round 18v, defect CQ: a `<caption>` box establishes a block formatting
// context and this engine's did not. `HtmlBuilder::withCaption()` rewrites the
// caption's computed `display` to `block` so `buildBox()` can lay it out, and
// `table-caption` is one of the displays `FlexLayout::sharesFloatContext()`
// already answers `false` for, so the rewrite is where it was lost.
//
// `O-table-margin-wrapper.html` `oo`: a caption holding a 100px float is
// **75.000** tall in Chrome and contains it, where this left the caption at
// 12.000 and let the float out into the table wrapper, which pushed the table
// box and everything under it 75.000 down the page.
$captionFloat = static function (string $caption): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:12px;line-height:16px}'
        . 'table{border-spacing:0;margin:0}td,th{padding:0}</style>'
        . '<div style="display:flow-root"><table id="t" style="width:120px;height:20px">'
        . '<caption id="c">' . $caption . '</caption>'
        . '<tr id="r"><td id="a">alpha</td></tr></table>'
        . '<div id="m" style="height:20px"></div></div>',
        300.0,
        3000.0,
    );

    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->anchorId !== null && $n->anchorId !== '') {
            $out[$n->anchorId] = [round($n->y, 3), round($n->layoutHeight, 3)];
        }

        foreach ($n->children as $c) {
            $walk($c);
        }
    };
    $walk($tree);

    return $out;
};

$float = '<span style="float:left;width:20px;height:100px"></span>';

$oo = $captionFloat($float . 'cap');

ok('a caption contains a float declared inside it',
    ($oo['c'] ?? []) === [0.0, 75.0] && ($oo['r'] ?? []) === [75.0, 15.0],
    json_encode($oo));

ok('and the block after the table is where the float leaves it',
    ($oo['m'] ?? []) === [90.0, 15.0], json_encode($oo['m'] ?? []));

// The control: the same caption with no float in it is one line tall and the
// table sits under it, which is what says the containment is the float's and
// not the caption's own size.
$plain = $captionFloat('cap');

ok('a caption with no float in it is unmoved',
    ($plain['c'] ?? []) === [0.0, 12.0] && ($plain['r'] ?? []) === [12.0, 15.0],
    json_encode($plain));

// Round 18x, defects CJ, CW and CX: a shrink-to-fit box is laid out in the
// room its containing block has, and the width clamps are applied before the
// content rather than after it. Three rules, one consequence for the fold: a
// float, an inline-block and a table that used to take their preferred width
// whatever the room now wrap, and the document that fitted on one page needs
// the three Chrome gives it.
//
// `docs/harness/probes/OK-fold-shrink.html` at 300x200: **3 pages**, ink
// 0.0900 / 0.1000 / 0.0389 against Chrome's 0.0905 / 0.1000 / 0.0425, where
// the pre-hunk engine put all of it on **one** page at 0.1399.
$shrinkFold = layout(
    '<style>html,body{margin:0;padding:0}'
    . 'body{width:400px;font-family:Helvetica;font-size:12px;line-height:16px}'
    . 'table{border-spacing:0}td{padding:0}'
    . '.nar{width:40px;clear:both}.f{float:left}.k{max-width:40px}</style>'
    . '<div class="nar"><div class="f">alpha be cd ef gh ij kl mn op qr st uv wx yz'
    . ' ab cd ef gh ij kl mn op qr st uv wx yz ab cd ef gh ij</div></div>'
    . '<div style="clear:both" class="k">alpha be cd ef gh ij kl mn op qr st uv wx yz'
    . ' ab cd ef gh ij kl mn op qr st uv wx yz</div>'
    . '<div class="nar" style="clear:both"><table class="f"><tr><td>alpha be cd ef gh'
    . ' ij kl mn op qr st uv wx yz ab cd ef gh ij kl mn</td></tr></table></div>',
    300.0,
    200.0,
);

[$shrinkPages, $shrinkOver, $shrinkLost] = paginate($shrinkFold, 200.0);

ok('a shrink-to-fit box that wraps paginates like Chrome',
    $shrinkPages === 3, "pages $shrinkPages");

ok('and nothing overflows a page or is lost across the fold',
    $shrinkOver === 0 && $shrinkLost === 0, "over $shrinkOver, lost $shrinkLost");

// Round 18z, defect DE: the root box is a block in the page area, so its own
// `margin-top` starts the flow lower down. The fragmenter began every document
// at the top of the page whatever the root said, which cost the first page one
// margin's worth of room and sliced the box that no longer fitted.
//
// `$WORK/z/rootfold.html` at 300x200 with `body { margin: 20pt }`: the four
// 60pt bands are at 20..80, 80..140 and 140..200 on page one and 0..60 on page
// two, exactly as Chrome paginates it, where the pre-hunk engine started at
// 0..60 and cut the fourth band across the fold at 180.
$rootMarginFold = layout(
    '<style>html{margin:0;padding:0}'
    . 'body{margin:20pt;font-family:Helvetica;font-size:12px;line-height:16px}'
    . 'div{height:60pt;background:#000}</style>'
    . '<div></div><div></div><div></div><div></div>',
    300.0,
    200.0,
);

[$rootMarginPages, $rootMarginOver, $rootMarginLost] = paginate($rootMarginFold, 200.0);

$rootMarginTops = [];
foreach ((new Fragmenter(200.0))->fragment($rootMarginFold) as $pageIndex => $page) {
    foreach ($page as $f) {
        $rootMarginTops[] = sprintf('%d:%.1f', $pageIndex, $f->y);
    }
}

ok('the root box starts the flow at its own top margin',
    $rootMarginTops === ['0:20.0', '0:80.0', '0:140.0', '1:0.0'],
    implode(' ', $rootMarginTops));

ok('and the shorter first page still costs two pages and loses nothing',
    $rootMarginPages === 2 && $rootMarginOver === 0 && $rootMarginLost === 0,
    "pages $rootMarginPages, over $rootMarginOver, lost $rootMarginLost");

// Round 19, defect DH: the root box emits a fragment on every page it reaches,
// so its own border is sliced across a fold the way any other box's is. The
// fragmenter flowed the root's children and never treated the root as a box, so
// nothing painted its background or its border at all.
//
// `PS-root-paint-fold.html` at 480x300 with `body { margin: 20pt; padding: 10pt;
// border: 4pt }` around a 700pt child: the border box runs 20..748, so the
// decoration is 20..300 on page one, 0..300 on page two and 0..148 on page
// three. Chrome prints exactly that, and its black pixel counts (3120, 2400,
// 2064) say the top border is drawn only on the first page and the bottom only
// on the last.
$rootBorderFold = layout(
    '<style>html{margin:0;padding:0}'
    . 'body{margin:20pt;padding:10pt;width:200pt;background:#ccc;border:4pt solid #000;'
    . 'font-family:Helvetica;font-size:12px;line-height:16px}</style>'
    . '<div style="height:700pt;background:#f00"></div>',
    480.0,
    300.0,
);

$rootBorderSlices = [];
foreach ((new Fragmenter(300.0))->fragment($rootBorderFold) as $pageIndex => $page) {
    foreach ($page as $f) {
        if ($f->node->display !== 'rect' || $f->node->border === null) {
            continue;
        }

        $rootBorderSlices[] = sprintf(
            '%d:%.1f..%.1f%s%s',
            $pageIndex,
            $f->y,
            $f->y + $f->h,
            $f->isContinuation ? '' : ' top',
            $f->splitsAfter ? '' : ' bottom',
        );
    }
}

ok("the root box's border is sliced across a fold",
    $rootBorderSlices === ['0:20.0..300.0 top', '1:0.0..300.0', '2:0.0..148.0 bottom'],
    implode(' | ', $rootBorderSlices));

// Round 30, defect EC: a box the fold cuts is painted through the proxy
// `closeDecoration()` builds, and that proxy is unshifted to the front of its
// page rather than dropped in at the box's own flow position. It therefore
// paints under every box on the page and not only under its own children, so
// an earlier sibling's background covers it.
//
// It is only visible where a box's decoration reaches OUTSIDE its own border
// box, which is an `outline`, an `outline-offset` or a `box-shadow`; a
// background cannot reach past its own edges. `RP-fold-deco-sibling.html` is
// the page: a grey spacer, then a card written after it whose 4pt outline sits
// 12pt outside its border box, so the outline's top edge lands inside the
// spacer. Chrome paints three green rows there and the pre-round engine
// painted none.
//
// The assertion is structural rather than a raster, for round 18n's reason: a
// digest cannot say why a band vanished. Sorted the way `Html::render()` sorts
// a page, the proxy has to land AFTER the earlier sibling and BEFORE its own
// children, and one position in a flat list is what it has.
//
// The card must have children. A childless box the fold cuts is sliced by
// `emitSlices()` into ordinary fragments in flow position, never reaches
// `closeDecoration()` and never had the defect.
$foldDecoSibling = layout(
    '<style>html,body{margin:0;padding:0}'
    . 'body{font-family:Helvetica;font-size:12px}'
    . '.spacer{height:180pt;background:#f2f2f2}'
    . '.card{outline:4pt solid #2e7d32;outline-offset:12pt}'
    . '.row{height:60pt;background:#ddeeff}</style>'
    . '<div class="spacer"></div>'
    . '<div class="card"><div class="row"></div><div class="row"></div><div class="row"></div></div>',
    300.0,
    300.0,
);

$foldDecoPage = (new Fragmenter(300.0))->fragment($foldDecoSibling)[0];
usort(
    $foldDecoPage,
    static fn($a, $b): int => FlexPDF\Engine\BoxPainter::compareStack($a->node, $b->node),
);

$foldDecoOrder = ['spacer' => -1, 'proxy' => -1, 'row' => -1];

$foldDecoRgb = static function (?array $fill): string {
    return $fill === null
        ? ''
        : sprintf('%d,%d,%d', (int) round($fill[0] * 255), (int) round($fill[1] * 255), (int) round($fill[2] * 255));
};

foreach ($foldDecoPage as $i => $f) {
    $key = match (true) {
        $f->node->outline !== null && $f->node->display === 'rect' => 'proxy',
        $foldDecoRgb($f->node->background) === '242,242,242'       => 'spacer',
        $foldDecoRgb($f->node->background) === '221,238,255'       => 'row',
        default                                                    => null,
    };

    if ($key !== null && $foldDecoOrder[$key] === -1) {
        $foldDecoOrder[$key] = $i;
    }
}

ok("a sliced box's decoration paints over an earlier sibling and under its own children",
    $foldDecoOrder['spacer'] >= 0
        && $foldDecoOrder['proxy'] > $foldDecoOrder['spacer']
        && $foldDecoOrder['row'] > $foldDecoOrder['proxy'],
    sprintf(
        'spacer %d, proxy %d, first row %d',
        $foldDecoOrder['spacer'],
        $foldDecoOrder['proxy'],
        $foldDecoOrder['row'],
    ));

// Round 33: `page-break-inside: avoid` did not stop a box being split.
//
// `break-inside` was read and the legacy spelling was not, while
// `page-break-before` and `page-break-after` were both read and mapped onto
// the modern names. It is the one fragmentation control every print template
// still writes, so a card that must stay whole was cut in two.
//
// `RS-fold-break-legacy.html` is the page and Chrome moves the card whole to
// the second page under either spelling. The assertion is the fragment count
// for the card: one fragment on the second page rather than two, one either
// side of the fold.
$legacyBreakDoc = '<style>html,body{margin:0;padding:0}'
    . '.spacer{height:108pt;background:#e3f2fd}'
    . '.card{height:84pt;background:#1e88e5;%s}</style>'
    . '<div class="spacer"></div><div class="card"></div>';

$legacyBreakCards = static function (string $declaration) use ($legacyBreakDoc): array {
    $pages = (new Fragmenter(120.0))->fragment(layout(sprintf($legacyBreakDoc, $declaration), 300.0, 120.0));
    $seen  = [];

    foreach ($pages as $page => $fragments) {
        foreach ($fragments as $fragment) {
            if ($fragment->node->background !== null && (int) round($fragment->node->background[2] * 255) === 229) {
                $seen[] = sprintf('p%d h%.0f', $page, $fragment->h);
            }
        }
    }

    return $seen;
};

$legacyBreakOff    = $legacyBreakCards('');
$legacyBreakLegacy = $legacyBreakCards('page-break-inside: avoid');
$legacyBreakModern = $legacyBreakCards('break-inside: avoid');

ok('page-break-inside: avoid keeps a box whole, the way break-inside does',
    $legacyBreakLegacy === $legacyBreakModern && count($legacyBreakOff) === 2 && count($legacyBreakLegacy) === 1,
    sprintf(
        'nothing %s, legacy %s, modern %s',
        implode('+', $legacyBreakOff),
        implode('+', $legacyBreakLegacy),
        implode('+', $legacyBreakModern),
    ));

// Round 40: a multi-column box moved whole children between columns where
// Chrome cuts one at the boundary.
//
// The column is a fragmentation context of its own, so the piece that does not
// fit is a box of its own in the tree and both painting paths have to find it
// there. `RZ-column-frag.html` z1 is this document and Chrome's own answer is
// the middle item cut in half: three 9pt items balanced over two columns is a
// target of 13.5pt, so column one holds the first item and half the second and
// column two holds the other half and the third.
//
// The control is the same document with `break-inside: avoid` on the middle
// item. Chrome leaves that item where it started and lets the column run past
// the balanced height rather than moving it, which `RZ-column-frag.html` z8
// says pixel for pixel, so the control is the item staying whole and in place
// and it is what says the cut is a choice rather than an accident.
$colCutDoc = '<style>html,body{margin:0;padding:0}'
    . '.mc{column-count:2;column-gap:18pt;width:108pt;height:30pt}'
    . '.i{height:9pt;background:#1e88e5}'
    . '.m{height:9pt;background:#43a047;%s}</style>'
    . '<div class="mc"><div class="i"></div><div class="m"></div><div class="i"></div></div>';

$colCutBands = static function (string $declaration) use ($colCutDoc): array {
    $tree  = layout(sprintf($colCutDoc, $declaration), 300.0, 300.0);
    $pages = (new Fragmenter(300.0))->fragment($tree);
    $out   = [];

    foreach ($pages[0] as $fragment) {
        $fill = $fragment->node->background;

        if ($fill === null || (int) round($fill[1] * 255) !== 160) {
            continue;
        }

        $out[] = sprintf('%.1f,%.1f,%.1f', $fragment->x, $fragment->y, $fragment->h);
    }

    sort($out);

    return $out;
};

$colCut   = $colCutBands('');
$colWhole = $colCutBands('break-inside: avoid');

ok('a column boundary cuts a child rather than moving it whole',
    $colCut === ['0.0,9.0,4.5', '63.0,0.0,4.5'] && $colWhole === ['0.0,9.0,9.0'],
    sprintf('cut %s, avoid %s', implode(' ', $colCut), implode(' ', $colWhole)));

// Round 41: a line box inside a column was not shortened by a float in that
// column, defect ES.
//
// Round 40 took the float out of the column's block flow, which is where
// Chrome has it, and the other half of what a float does was unbuilt: the text
// beside it started at the column's own left edge and ran under it.
// `SA-column-float.html` s1 is this document, and Chrome's answer is read off
// its content stream: the two lines that overlap the float start at its right
// edge, the third is below it and starts at the column edge, and the column
// after it is untouched.
//
// The control is the same document with the float taken out, where every line
// starts at the column edge. What is asserted is a line's own offset rather
// than a position on the page, because that offset IS the band: it is what
// `InlineFormatter` adds to every item on a line a float shortened.
$colFloatDoc = '<style>html,body{margin:0;padding:0}'
    . '.mc{column-count:2;column-gap:18pt;width:108pt}'
    . '.f{float:left;width:18pt;height:18pt;background:#8e24aa}'
    . '.mc p{margin:0;font:9pt/9pt Helvetica}</style>'
    . '<div class="mc">%s<p>aa<br>bb<br>cc<br>dd<br>ee<br>ff</p></div>';

$colFloatStarts = static function (string $float) use ($colFloatDoc): array {
    $tree = layout(sprintf($colFloatDoc, $float), 300.0, 300.0);
    $out  = [];

    $walk = static function (Node $n) use (&$walk, &$out): void {
        foreach ($n->lineBoxes as $line) {
            $out[] = $line->items === [] ? 'empty' : sprintf('%.1f', $line->items[0]->x);
        }

        foreach ($n->children as $child) {
            $walk($child);
        }
    };

    $walk($tree);

    return $out;
};

$colFloat = $colFloatStarts('<div class="f"></div>');
$colBare  = $colFloatStarts('');

ok('a float in a column shortens the line boxes beside it',
    $colFloat === ['18.0', '18.0', '0.0', '0.0', '0.0', '0.0']
        && $colBare === ['0.0', '0.0', '0.0', '0.0', '0.0', '0.0'],
    sprintf('float %s, bare %s', implode(' ', $colFloat), implode(' ', $colBare)));

// Round 42: a paragraph cut at a column boundary dropped the line the balance
// target falls inside, defect ET.
//
// Five 9pt lines balanced over two columns is a target of 22.5pt, which is
// inside the third line. Chrome keeps that line in the column it started in
// and lets the column run past the target, so it is three and two;
// taking only the lines that fit inside the target is two and three.
// `SC-column-lines.html` c1 is this document and Chrome's own content stream
// is where the three comes from.
//
// The control is the same document with four lines, where the target is 18pt
// and falls exactly on the third line's top. A line that starts ON the target
// has not started before it, so both rules answer two and two and the control
// is what says the subject measures the straddling line and nothing else.
$colLineDoc = '<style>html,body{margin:0;padding:0}'
    . '.mc{column-count:2;column-gap:18pt;width:108pt}'
    . '.mc p{margin:0;font:9pt/9pt Helvetica}</style>'
    . '<div class="mc"><p>%s</p></div>';

$colLineSplit = static function (int $lines) use ($colLineDoc): array {
    $tree = layout(sprintf($colLineDoc, implode('<br>', array_fill(0, $lines, 'aa'))), 300.0, 300.0);
    $out  = [];

    $walk = static function (Node $n) use (&$walk, &$out): void {
        if ($n->lineBoxes !== []) {
            $out[] = sprintf('%.1f:%d', $n->x, count($n->lineBoxes));
        }

        foreach ($n->children as $child) {
            $walk($child);
        }
    };

    $walk($tree);
    sort($out);

    return $out;
};

$colLineFive = $colLineSplit(5);
$colLineFour = $colLineSplit(4);

ok('a line that starts before the balance target stays in its own column',
    $colLineFive === ['0.0:3', '63.0:2'] && $colLineFour === ['0.0:2', '63.0:2'],
    sprintf('five %s, four %s', implode(' ', $colLineFive), implode(' ', $colLineFour)));

// Round 45: `box-decoration-break: clone` wraps EVERY fragment in the box's own
// border and padding, so a fragment that continues spends both edges rather
// than the first taking the top and the last the bottom.
//
// The card is 108pt of rows in a box with 9pt of padding and 6pt of border on
// each side, starting 81pt down a 120pt page. Under `slice` that is two pages;
// under `clone` each fragment pays 15pt at each end, so it is three, and the
// second fragment is the one that proves it because it has a fragment above it
// and one below it. `SJ-clone-thrice.html` is the document and Chrome's own
// content stream is where the three comes from.
//
// The `slice` twin is the control and must stay at two pages, which is what
// says the reserve is spent on the keyword rather than on every box.
$cloneDoc = '<style>html,body{margin:0;padding:0}'
    . '.s{height:81pt}'
    . '.card{padding:9pt;border:6pt solid #000;box-decoration-break:%s}'
    . '.r{height:27pt;background:#ccc}</style>'
    . '<div class="s"></div><div class="card">'
    . '<div class="r"></div><div class="r"></div><div class="r"></div><div class="r"></div></div>';

$clonePages = static fn(string $value): array => paginate(
    layout(sprintf($cloneDoc, $value), 300.0, 120.0),
    120.0,
);

[$clonePageCount, $cloneOver, $cloneLost] = $clonePages('clone');
[$slicePageCount, $sliceOver, $sliceLost] = $clonePages('slice');

ok('every fragment of a box-decoration-break: clone box wears both its edges',
    $clonePageCount === 3 && $slicePageCount === 2
        && $cloneOver === 0 && $sliceOver === 0 && $cloneLost === 0 && $sliceLost === 0,
    sprintf('clone %d pages, slice %d pages', $clonePageCount, $slicePageCount));

// Round 51: the fold truncates the gap between two grid row bands, exactly as
// it truncates the margin between two block siblings and the `border-spacing`
// between two table rows.
//
// The grid is 240pt of row, a 120pt gap and 180pt of row on a 300pt page, so
// 60pt of the gap never lands. The second row moves to the top of page 2 either
// way, because it no longer fits; what the band walk did not do was record the
// drop, so the grid was charged for a gap that never reached the paper and the
// box after it sat 60pt lower than Chrome puts it. `SQ-ramp-gridgap.html` is
// the document and the marker is the box after the grid.
$gridGapDoc = '<style>html,body{margin:0;padding:0}'
    . '#w{display:grid;row-gap:120pt}'
    . '#a{height:240pt}#b{height:180pt}'
    . '#c{height:60pt;background:#1b7a3a}</style>'
    . '<div id="w"><div id="a"></div><div id="b"></div></div><div id="c"></div>';

$gridGapPages = (new Fragmenter(300.0))->fragment(layout($gridGapDoc, 400.0, 300.0));
$gridGapMark  = null;

foreach ($gridGapPages[1] ?? [] as $fragment) {
    if ($fragment->node->background !== null) {
        $gridGapMark = $fragment->y;
    }
}

ok('the fold truncates a grid row gap the way it truncates a margin',
    count($gridGapPages) === 2 && $gridGapMark !== null && abs($gridGapMark - 180.0) < 0.01,
    sprintf('%d pages, marker at %s', count($gridGapPages),
        $gridGapMark === null ? 'nowhere' : sprintf('%.3f', $gridGapMark)));

// Rounds 67 to 70: the four column-fragmentation fixes that owe a case here
// under the pre-agreed exception, HH, HL, HM and HT. Every number below is
// Chrome's own answer off the probe page the row was measured on, scaled from
// CSS pixels to points, and every case was confirmed to fail with only its own
// fix reverted.
//
// The reading is every item box in document order as `x,y,height`, absolute:
// `FlexLayout::accumulateOffsets()` has run by the time `layout()` returns, so a
// box's own `x` and `y` are page coordinates. **Document order is what tells
// these cases apart.** A box that fills one column of two and a box that fills a
// row of two columns paint the same multiset of positions, and only the order
// says which item went where.
$columnBoxes = static function (Node $tree, int $green): array {
    $out  = [];
    $walk = static function (Node $n) use (&$walk, &$out, $green): void {
        $fill = $n->background;

        if ($fill !== null && (int) round($fill[1] * 255) === $green) {
            $out[] = sprintf('%.1f,%.1f,%.1f', $n->x, $n->y, $n->layoutHeight);
        }

        foreach ($n->children as $child) {
            $walk($child);
        }
    };

    $walk($tree);

    return $out;
};

// Round 67, defect HH: a table, a grid and a flex container moved WHOLE between
// two columns, where Chrome cuts them exactly as it cuts a block. The page
// fragmenter has split all three since round 14, so this was one path refusing
// what the other path already does.
//
// The box is 180pt wide with an 18pt gap, so each column is 81pt and the second
// starts at 99pt. It declares 36pt of height and holds four 18pt items, so two
// fit a column and the four fill the two exactly. `TT-column-split.html` b1, b2
// and b3 are this document in CSS pixels, and Chrome puts two items in each
// column at left 0 and left 132 for all three displays.
//
// The ordinary block is the control, because the column path has cut a block
// since round 40: a run where the block moved too would be the whole path
// failing rather than this row.
$hhDoc = '<style>html,body{margin:0;padding:0}'
    . '.box{width:180pt;height:36pt;column-count:2;column-gap:18pt;column-fill:auto}'
    . '.i{height:18pt;background:#1e88e5}'
    . '.g{display:grid;grid-auto-rows:18pt}'
    . '.f{display:flex;flex-direction:column}'
    . 'table{border-collapse:collapse;width:100%%;table-layout:fixed}'
    . 'td{padding:0;height:18pt}</style>'
    . '<div class="box">%s</div>';

$hhItems = str_repeat('<div class="i"></div>', 4);
$hhRead  = static fn(string $markup): array => $columnBoxes(
    layout(sprintf($hhDoc, $markup), 400.0, 300.0),
    136,
);

$hhBlock = $hhRead('<div>' . $hhItems . '</div>');
$hhTable = $hhRead('<table>' . str_repeat('<tr><td class="i"></td></tr>', 4) . '</table>');
$hhGrid  = $hhRead('<div class="g">' . $hhItems . '</div>');
$hhFlex  = $hhRead('<div class="f">' . $hhItems . '</div>');
$hhWant  = ['0.0,0.0,18.0', '0.0,18.0,18.0', '99.0,0.0,18.0', '99.0,18.0,18.0'];

ok('a table, a grid and a flex container are cut between two columns',
    $hhTable === $hhWant && $hhGrid === $hhWant && $hhFlex === $hhWant && $hhBlock === $hhWant,
    sprintf('block %s, table %s, grid %s, flex %s',
        implode(' ', $hhBlock), implode(' ', $hhTable),
        implode(' ', $hhGrid), implode(' ', $hhFlex)));

// Round 68, defect HL: a band the column boundary falls INSIDE moved whole,
// where Chrome cuts it at one offset through every item of it. Round 67 admitted
// the three displays only where their own children are a stack, and the cells of
// a table row, the items of a grid row and the items of a flex line all share a
// top.
//
// The same box holds an 18pt spacer and a 27pt band, which is 45pt of content in
// 36pt of first column, so the cut falls 18pt into the band and 9pt of it belongs
// to the second. Each band has two items across the 81pt column, so each is
// 40.5pt wide. `TX-band-cut.html` x1, x2 and x3 are this document in CSS pixels
// and Chrome paints two boxes per item, 24px in the first column and 12px in the
// second.
//
// The control is x4's refusal: one item of the band declares `break-inside:
// avoid`, and a band is cut at one offset or not at all, so the whole band has to
// move. It reads the same on every tree, which is what says the subject is the
// cut rather than the display.
$hlDoc = '<style>html,body{margin:0;padding:0}'
    . '.box{width:180pt;height:36pt;column-count:2;column-gap:18pt;column-fill:auto}'
    . '.sp{height:18pt;background:#607d8b}'
    . '.i{background:#1e88e5}'
    . '.g{display:grid;grid-template-columns:1fr 1fr;grid-auto-rows:27pt}'
    . '.f{display:flex}.f>div{flex:1 1 0;height:27pt}'
    . '.k{break-inside:avoid}'
    . 'table{border-collapse:collapse;width:100%%;table-layout:fixed}'
    . 'td{padding:0;height:27pt}</style>'
    . '<div class="box"><div class="sp"></div>%s</div>';

$hlRead = static fn(string $markup): array => $columnBoxes(
    layout(sprintf($hlDoc, $markup), 400.0, 300.0),
    136,
);

$hlGrid  = $hlRead('<div class="g"><div class="i"></div><div class="i"></div></div>');
$hlRow   = $hlRead('<table><tr><td class="i"></td><td class="i"></td></tr></table>');
$hlFlex  = $hlRead('<div class="f"><div class="i"></div><div class="i"></div></div>');
$hlAvoid = $hlRead('<div class="g"><div class="i k"></div><div class="i"></div></div>');
$hlWant  = ['0.0,18.0,18.0', '40.5,18.0,18.0', '99.0,0.0,9.0', '139.5,0.0,9.0'];
$hlWhole = ['99.0,0.0,27.0', '139.5,0.0,27.0'];

ok('a band the column boundary falls inside is cut at one offset through it',
    $hlGrid === $hlWant && $hlRow === $hlWant && $hlFlex === $hlWant && $hlAvoid === $hlWhole,
    sprintf('grid %s, row %s, flex %s, avoid %s',
        implode(' ', $hlGrid), implode(' ', $hlRow),
        implode(' ', $hlFlex), implode(' ', $hlAvoid)));

// Round 69, defect HM: a multi-column box taller than the page paginated its
// columns one after the other, where CSS Multicol 3.3 makes a column as tall as
// its fragmentainer and gives every page its own ROW of columns.
//
// Sixteen 50pt items in a 400pt box on a 300pt page. Six items fill a column of
// one page, so the first row is twelve items and the second is the box's own
// remainder, 100pt, which is two items per column and NOT four in the first.
// `UD-column-fragmentainer.html` is this document in CSS pixels and its two pages
// are Chrome's, box for box.
//
// With the cap taken back to the box's own height the multiset of positions does
// not change at all: eight items down the first column and eight down the second
// occupy the same sixteen places. **The order is the reading**, and it is why
// this case walks the tree rather than sorting it.
$hmDoc = '<style>html,body{margin:0;padding:0}'
    . '.mc{width:180pt;height:400pt;column-count:2;column-gap:18pt;column-fill:auto}'
    . '.i{height:50pt;background:#1e88e5}</style>'
    . '<div class="mc">' . str_repeat('<div class="i"></div>', 16) . '</div>';

$hmBoxes = $columnBoxes(layout($hmDoc, 400.0, 300.0), 136);
$hmWant  = [
    '0.0,0.0,50.0',    '0.0,50.0,50.0',   '0.0,100.0,50.0',  '0.0,150.0,50.0',
    '0.0,200.0,50.0',  '0.0,250.0,50.0',
    '99.0,0.0,50.0',   '99.0,50.0,50.0',  '99.0,100.0,50.0', '99.0,150.0,50.0',
    '99.0,200.0,50.0', '99.0,250.0,50.0',
    '0.0,300.0,50.0',  '0.0,350.0,50.0',  '99.0,300.0,50.0', '99.0,350.0,50.0',
];

ok('a multi-column box taller than the page gets a row of columns per page',
    $hmBoxes === $hmWant,
    sprintf('%d boxes, %s', count($hmBoxes), implode(' ', array_slice($hmBoxes, 12))));

// Round 70, defect HT: a multi-column box that does not start at the top of its
// page filled its first row of columns to a WHOLE page. The first fragmentainer
// such a box meets is what is LEFT of the page it begins on, and layout could not
// ask where the box was until `FlexLayout::$flowTop` carried it.
//
// The same box with no declared height, 100pt down a 300pt page, so the first row
// is 200pt and holds four items per column and every row after it is a whole
// page. `UF-column-midpage.html` is this document in CSS pixels: Chrome's page 1
// carries the spacer and then a 405pt row of two columns where the page is 540.
//
// The page COUNT is not the tell and the boxes are: the document is two pages
// either way, and with the offset ignored the first row takes six items per
// column and the second column starts where the page has already run out.
$htDoc = '<style>html,body{margin:0;padding:0}'
    . '.head{height:100pt}'
    . '.mc{width:180pt;column-count:2;column-gap:18pt;column-fill:auto}'
    . '.i{height:50pt;background:#1e88e5}</style>'
    . '<div class="head"></div>'
    . '<div class="mc">' . str_repeat('<div class="i"></div>', 12) . '</div>';

$htBoxes = $columnBoxes(layout($htDoc, 400.0, 300.0), 136);
$htWant  = [
    '0.0,100.0,50.0',  '0.0,150.0,50.0',  '0.0,200.0,50.0',  '0.0,250.0,50.0',
    '99.0,100.0,50.0', '99.0,150.0,50.0', '99.0,200.0,50.0', '99.0,250.0,50.0',
    '0.0,300.0,50.0',  '0.0,350.0,50.0',  '0.0,400.0,50.0',  '0.0,450.0,50.0',
];

ok('a multi-column box starting mid-page fills its first row to what is left',
    $htBoxes === $htWant,
    sprintf('%d boxes, %s', count($htBoxes), implode(' ', array_slice($htBoxes, 8))));

// Round 72, feature C: `@page :first` / `:left` / `:right`. A qualified `@page`
// block was parsed, recorded and applied to nothing at all, so all ten spellings
// rendered the same five pages.
//
// Every number below is Chrome's, off `docs/harness/probes/UM-page-first-top.html`
// and its eight neighbours, read as a paragraph count per page. The document is
// those pages with the lengths kept: a 540pt square sheet with a 60pt margin
// holds 420pt, and every `p` is exactly 45pt tall because the line height is an
// absolute length, so no face metric can reach the answer and 420pt is nine
// paragraphs and a third.
$pageDoc = static fn(string $qualified): string => '<style>'
    . '@page{size:540pt 540pt;margin:60pt}' . $qualified
    . 'html,body{margin:0;padding:0}'
    . 'body{font-size:12px;line-height:60px}'
    . 'p{margin:0}'
    . '</style>'
    . str_repeat('<p>x</p>', 40);

/**
 * How many lines of text landed on each page, in page order.
 *
 * @param  Fragment[][] $pages
 * @return list<int>
 */
$linesPerPage = static function (array $pages): array {
    $counts = [];

    foreach ($pages as $fragments) {
        $lines = 0;

        foreach ($fragments as $fragment) {
            foreach ($fragment->lines as $line) {
                foreach ($line->items as $item) {
                    if (!$item->isSpace) {
                        $lines++;

                        break;
                    }
                }
            }
        }

        $counts[] = $lines;
    }

    return $counts;
};

$paged = static fn(callable $count, string $qualified): array
    => $count(Html::make($pageDoc($qualified))->layout()[1]);

// The control first, and it is the reason the whole feature can be trusted not
// to have moved anything: with no qualified block there is one page height and
// nothing is held back at the foot of any page.
$cNone = $paged($linesPerPage, '');

ok('a document with no qualified `@page` block paginates as it always did',
    $cNone === [9, 9, 9, 9, 4],
    implode(',', $cNone));

// `UM-page-first-top.html`: Chrome puts 7 paragraphs on page 1 and 9 on every
// page after it, because page 1's content box is 345pt and the rest are 420pt.
$cFirst = $paged($linesPerPage, '@page :first{margin-top:135pt}');

ok('`@page :first` with a larger top margin shortens the first page only',
    $cFirst === [7, 9, 9, 9, 6],
    implode(',', $cFirst));

// `UN-page-first-topless.html`, the other direction: a SMALLER first-page top
// margin makes page 1 the tallest page in the document, 465pt against 420.
$cShort = $paged($linesPerPage, '@page :first{margin-top:15pt}');

ok('`@page :first` with a smaller top margin lengthens the first page only',
    $cShort === [10, 9, 9, 9, 3],
    implode(',', $cShort));

// `UR-page-parity-top.html`: the first page of a left-to-right document is a
// `:right` page, so the 195pt top margin lands on pages 2 and 4.
$cParity = $paged($linesPerPage, '@page :left{margin-top:195pt}@page :right{margin-top:60pt}');

ok('`@page :left` and `:right` alternate, and page 1 is a right page',
    $cParity === [9, 6, 9, 6, 9, 1],
    implode(',', $cParity));

// `UT-page-first-cascade.html` and `UU-page-first-order.html` are the same
// document with the two blocks swapped and Chrome gives both the same six
// counts, so `:first` beats `:right` on specificity and not on source order.
$cAfter  = $paged($linesPerPage, '@page :right{margin-top:195pt}@page :first{margin-top:135pt}');
$cBefore = $paged($linesPerPage, '@page :first{margin-top:135pt}@page :right{margin-top:195pt}');

ok('`@page :first` beats `:right` whichever order the two are written in',
    $cAfter === [7, 9, 6, 9, 6, 3] && $cAfter === $cBefore,
    implode(',', $cAfter) . ' then ' . implode(',', $cBefore));

// The flow half above says how much room each page gave. This says where the
// page put it: Chrome paints the first baseline of `UM`'s page 1 at 379.500 and
// of its page 2 at 454.500, and the 75pt between them is the margin the
// qualified block asked for less the one it replaced.
[$pageBytes] = Html::make($pageDoc('@page :first{margin-top:135pt}'))->render();

preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pageBytes, $streams);

$baselines = [];

foreach (array_slice($streams[1], 0, 2) as $stream) {
    $plain = @gzuncompress($stream);

    if ($plain !== false && preg_match('/1 0 0 1 [\d.]+ ([\d.]+) Tm/', $plain, $tm) === 1) {
        $baselines[] = $tm[1];
    }
}

ok('a first page with its own top margin is painted at that margin',
    $baselines === ['379.500', '454.500'],
    implode(' ', $baselines));

// `UQ-page-first-size.html`: Chrome gives page 1 a `/MediaBox` of its own and
// leaves every other page on the document's sheet, and 270pt of page less 120pt
// of margin is three paragraphs.
[$sizeBytes] = Html::make($pageDoc('@page :first{size:540pt 270pt}'))->render();
$cSize       = $paged($linesPerPage, '@page :first{size:540pt 270pt}');

ok('`@page :first` with a `size` gives the first page its own sheet',
    $cSize === [3, 9, 9, 9, 9, 1]
        && substr_count($sizeBytes, '/MediaBox [0 0 540.00 270.00]') === 1
        && substr_count($sizeBytes, '/MediaBox [0 0 540.00 540.00]') === 5,
    implode(',', $cSize));

// ---- round 73: a named `@page` and the `page` property ----

/**
 * A document with a run of paragraphs in the middle carrying a page type.
 *
 * The same shape as `VD-page-named.html` and its neighbours, and every
 * paragraph is 45pt tall by construction, so a 540pt page with a 60pt margin
 * holds nine of them and no face metric can reach the answer.
 */
$namedDoc = static fn(string $blocks, string $rule, int $before, int $named, int $after): string => '<style>'
    . '@page{size:540pt 540pt;margin:60pt}' . $blocks
    . 'html,body{margin:0;padding:0}'
    . 'body{font-size:12px;line-height:60px}'
    . 'p{margin:0}' . $rule
    . '</style>'
    . str_repeat('<p>x</p>', $before)
    . '<div class="cover">' . str_repeat('<p>x</p>', $named) . '</div>'
    . str_repeat('<p>x</p>', $after);

$namedPages = static fn(string $blocks, string $rule, int $before, int $named, int $after): array
    => $linesPerPage(Html::make($namedDoc($blocks, $rule, $before, $named, $after))->layout()[1]);

// `VL-page-named-noblock.html`: Chrome paginates 4, 3, 9 with `page: cover` and
// no `@page cover` block anywhere, so it is the NAME that forces the break.
$cName = $namedPages('', '.cover{page:cover}', 4, 3, 9);

ok('a `page` name forces a break with no `@page` block of its own',
    $cName === [4, 3, 9],
    implode(',', $cName));

// `VE-page-named-off.html`, the control: the same document with the `page`
// declaration removed and the named block left in place. It has to pass on both
// trees or it is not a control.
$cOff = $namedPages('@page cover{size:540pt 270pt;margin:60pt}', '', 4, 3, 9);

ok('a named `@page` block nothing selects changes nothing',
    $cOff === [9, 7],
    implode(',', $cOff));

// `VD-page-named.html`: Chrome puts the cover on page 2 and gives that page the
// cover's own sheet, 540 by 270.
$cNamed = $namedPages('@page cover{size:540pt 270pt;margin:60pt}', '.cover{page:cover}', 4, 3, 9);

[$namedBytes] = Html::make(
    $namedDoc('@page cover{size:540pt 270pt;margin:60pt}', '.cover{page:cover}', 4, 3, 9),
)->render();

ok('a named `@page` block gives the pages it selects their own sheet',
    $cNamed === [4, 3, 9]
        && substr_count($namedBytes, '/MediaBox [0 0 540.00 270.00]') === 1
        && substr_count($namedBytes, '/MediaBox [0 0 540.00 540.00]') === 2,
    implode(',', $cNamed));

// `VH-page-named-run.html`: a run that needs four of the shorter pages, which is
// the case a per-page map with a hole in it gets wrong on its last page.
$cRun = $namedPages('@page cover{size:540pt 270pt;margin:60pt}', '.cover{page:cover}', 4, 12, 9);

ok('a named run longer than one page keeps its own sheet throughout',
    $cRun === [4, 3, 3, 3, 3, 9],
    implode(',', $cRun));

// `VJ-page-named-firstpage.html`: on the page where the name and `:first` both
// match, Chrome takes the composed block, so the run fits on one 810pt page.
$cFirstNamed = $namedPages(
    '@page cover{size:540pt 270pt;margin:60pt}@page cover:first{size:540pt 810pt}',
    '.cover{page:cover}',
    0,
    12,
    9,
);

ok('`@page <name>:first` beats the bare name on the page both match',
    $cFirstNamed === [12, 9],
    implode(',', $cFirstNamed));

// ---- round 73: the clamp reads the band, and it carries the whole remainder ----

/**
 * An out-of-flow box taller than the page it starts on, as a list of
 * `page, y, height` triples in page order.
 *
 * `position: absolute` is the path `clampOverflow()` exists for: the box never
 * goes through the flow, so nothing else in the fragmenter has an opinion about
 * where it is cut.
 *
 * @return list<string>
 */
$absPieces = static function (string $qualified, string $holder, string $top, string $height): array {
    $doc = '<style>'
        . '@page{size:540pt 540pt;margin:60pt}' . $qualified
        . 'html,body{margin:0;padding:0}'
        . ".holder{position:relative;height:{$holder}}"
        . ".tall{position:absolute;top:{$top};width:99pt;height:{$height};background:#1e88e5}"
        . '</style>'
        . '<div class="holder"><div class="tall"></div></div>';

    $pieces = [];

    foreach (Html::make($doc)->layout()[1] as $index => $fragments) {
        foreach ($fragments as $fragment) {
            $pieces[] = sprintf('%d:%.2f+%.2f', $index + 1, $fragment->y, $fragment->h);
        }
    }

    return $pieces;
};

// `VM-page-first-abs.html`: Chrome paints 435..480 on a first page a qualified
// block shortened to 345pt of content, then a full page, then the rest. Reading
// the strip instead gave page 1 the whole 120pt to the strip's foot, which is
// 75pt past that page's content box and 15pt off the paper.
$hy = $absPieces('@page :first{margin-top:135pt}', '600pt', '300pt', '500pt');

ok('an out-of-flow box on a shortened page is cut at that page\'s content box',
    $hy === ['1:300.00+45.00', '2:0.00+420.00', '3:0.00+35.00'],
    implode(' ', $hy));

// `VN-abs-multipage.html`, and it needs no `@page` block at all: Chrome paints
// 420, 420, 420 and 240 of a 1,500pt box, where a continuation capped at one
// page painted 840pt and dropped the other 660 in silence.
$hz = $absPieces('', '1500pt', '0pt', '1500pt');

ok('an out-of-flow box taller than two pages is carried to the end of it',
    $hz === ['1:0.00+420.00', '2:0.00+420.00', '3:0.00+420.00', '4:0.00+240.00'],
    implode(' ', $hz));

// ---- round 73: a qualified `@page` block's inline margins ----

/** The x of the first text origin in a document's first content stream. */
$firstTextX = static function (string $bytes): ?string {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);

        if ($plain !== false && preg_match('/1 0 0 1 ([\d.]+) [\d.]+ Tm/', $plain, $tm) === 1) {
            return $tm[1];
        }
    }

    return null;
};

// `UP-page-first-inline.html`: Chrome paints the first page's text at x 150 and
// every other page's at 60. The engine laid every page out at one content width,
// so the qualified inline margins were dropped outright.
[$inlineBytes] = Html::make($pageDoc('@page :first{margin-left:150pt}'))->render();
$inlineX       = $firstTextX($inlineBytes);

ok('a qualified inline margin moves the page it selects, where the lines fit',
    $inlineX === '150.000',
    (string) $inlineX);

// `VP-page-first-inline-wrap.html`, and it is the control: the same block with
// text wide enough to wrap. The lines were fitted to the 420pt page, so putting
// them inside the 330pt one would paint into a margin the document asked to keep
// clear, and every page falls back to the unqualified inline geometry. Chrome
// paints this one at 150 and the engine at 60, which is what is left of HX.
$wrapDoc = '<style>'
    . '@page{size:540pt 540pt;margin:60pt}@page :first{margin-left:150pt}'
    . 'html,body{margin:0;padding:0}'
    . 'body{font-size:12px;line-height:60px}'
    . 'p{margin:0}'
    . '</style><p>' . str_repeat('w ', 200) . '</p>';

[$wrapBytes] = Html::make($wrapDoc)->render();
$wrapX       = $firstTextX($wrapBytes);

ok('a qualified inline margin is dropped where the lines would not fit it',
    $wrapX === '60.000',
    (string) $wrapX);

// ---- round 87: the WIDER half of the same restriction ----

// `YX-page-first-inline-wider.html`: the same qualified block with a SMALLER
// inline margin, so the first page's content box is WIDER than the document's
// rather than narrower. Chrome paints page 1 at x 0.000 and pages 2 and 3 at
// 60.000, read off the probe with `streamdump.py` before the case was written.
// Until round 87 the engine painted 60.000 on all three, because a page wider
// than the width the lines were fitted to was dropped outright; the document is
// laid out at the widest content box any kind asks for now.
// This moves no `/MediaBox` at all, so it pins the margin half on its own and
// says nothing about the sheet half, which `$namedSheet` pins further down.
[$widerBytes] = Html::make($pageDoc('@page :first{margin-left:0}'))->render();
$widerX       = $firstTextX($widerBytes);

ok('a qualified inline margin that WIDENS the page it selects moves that page too',
    $widerX === '0.000',
    (string) $widerX);

// The CONTROL for the wider half, and it is the wrap case pointing the other
// way: the same block with text wide enough that lines fitted to the first
// page's 480pt content box would not fit the 420pt one every later page has.
// Every page keeps the document's own inline geometry, which is what all of
// this did before round 87. Chrome paints page 1 at 0 and page 2 at 60 here, so
// this is a case about the engine's own rule rather than about Chrome, exactly
// as the narrow control above it is.
// It takes 600 repeats and not 200: at 200 the text fits ONE page, and a
// one-page document has no narrower page for its lines to fail to fit, so the
// wider first page is honoured and there is nothing to refuse.
$widerWrapDoc = '<style>'
    . '@page{size:540pt 540pt;margin:60pt}@page :first{margin-left:0}'
    . 'html,body{margin:0;padding:0}'
    . 'body{font-size:12px;line-height:60px}'
    . 'p{margin:0}'
    . '</style><p>' . str_repeat('w ', 600) . '</p>';

[$widerWrapBytes] = Html::make($widerWrapDoc)->render();
$widerWrapX       = $firstTextX($widerWrapBytes);

ok('CONTROL: a WIDENING qualified inline margin is refused where the lines would not fit the rest',
    $widerWrapX === '60.000',
    (string) $widerWrapX);

// ---- round 74: a break inside a box that fits, and a margin box on a
// qualified page ----

/** The literal strings drawn on each page, in order, joined per page. */
$pageText = static function (string $bytes): array {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    $pages = [];

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);

        if ($plain === false) {
            continue;
        }

        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $plain, $runs);
        $pages[] = implode(' ', $runs[1]);
    }

    return $pages;
};

// A box that fits on the page was emitted whole and its children were never
// walked, so a forced break inside one was lost outright. Chrome puts `a1 b1`
// on the first page and `c1 d1` on the second; the engine put all four on one.
$fitsDoc = '<style>'
    . '@page{size:540pt 540pt;margin:60pt}'
    . 'html,body{margin:0;padding:0}body{font-size:12px;line-height:60px}p{margin:0}'
    . '.brk{break-before:page}'
    . '</style>'
    . '<p>a1</p><div><p>b1</p><p class="brk">c1</p></div><p>d1</p>';

[$fitsBytes, $fitsPages] = Html::make($fitsDoc)->render();

ok('a forced break inside a box that fits the page still breaks',
    $fitsPages === 2 && $pageText($fitsBytes) === ['a1 b1', 'c1 d1'],
    $fitsPages . ': ' . implode(' | ', $pageText($fitsBytes)));

// The anonymous box holding a named block's lines carried no page name, so a
// paragraph of the run that had to be SPLIT was read as a change back to the
// ordinary page. The break itself did nothing, because it fell at the top of a
// page, but the type was reset there and the run then lost the break at its
// end. Chrome paginates this one `a1`, `L1..L9`, `L10..L13`, `z1`; the engine
// folded `z1` onto the run's own last page.
//
// Thirteen lines forced with `<br>` rather than a paragraph long enough to
// wrap, so the line count is the document's and not the face's.
$lines = [];

for ($i = 1; $i <= 13; $i++) {
    $lines[] = 'L' . $i;
}

$runDoc = '<style>'
    . '@page{size:540pt 540pt;margin:60pt}@page cover{size:540pt 540pt;margin:60pt}'
    . 'html,body{margin:0;padding:0}body{font-size:12px;line-height:60px}p{margin:0}'
    . '.cover{page:cover}'
    . '</style>'
    . '<p>a1</p><div class="cover"><p>' . implode('<br>', $lines) . '</p></div><p>z1</p>';

[$runBytes, $runPages] = Html::make($runDoc)->render();

ok('a named run that is split keeps its own page type to the end of it',
    $runPages === 4 && ($pageText($runBytes)[3] ?? '') === 'z1',
    $runPages . ': ' . implode(' | ', $pageText($runBytes)));

// A margin box declared in a qualified `@page` block was thrown away at parse
// time, so it never painted at all. Chrome paints `TLTL` on both pages and
// `FONE` only on the page `:first` selects.
$boxDoc = '<style>'
    . '@page{size:300pt 300pt;margin:40pt;@top-left{content:"TLTL";font-size:12px}}'
    . '@page :first{@top-right{content:"FONE";font-size:12px}}'
    . 'html,body{margin:0;padding:0}body{font-size:12px;line-height:20px}p{margin:0}'
    . '</style>';

for ($i = 1; $i <= 16; $i++) {
    $boxDoc .= '<p>a' . $i . '</p>';
}

[$boxBytes] = Html::make($boxDoc)->render();
$boxPages   = $pageText($boxBytes);
$withFirst  = array_values(array_filter($boxPages, static fn(string $t): bool => str_contains($t, 'FONE')));
$withEvery  = array_values(array_filter($boxPages, static fn(string $t): bool => str_contains($t, 'TLTL')));

ok('a margin box in a qualified @page block paints on the pages it selects',
    count($withEvery) === 2 && count($withFirst) === 1 && str_contains($boxPages[0], 'FONE'),
    sprintf('every=%d first=%d', count($withEvery), count($withFirst)));

// ---- round 75: a flex line asked about every item of it ----

/**
 * `VV-page-named-flex.html` and `VZ-break-flex.html`: a two-item flex row whose
 * SECOND item asks for a page of its own, once by naming a page and once with a
 * plain forced break. Only the first item of a band was asked, so neither
 * spelling did anything.
 *
 * Each paragraph is 45pt tall by construction, so a 540pt page with a 60pt
 * margin holds nine of them and no face metric can reach the answer.
 */
$flexDoc = static fn(string $blocks, string $second): string => '<style>'
    . '@page{size:540pt 540pt;margin:60pt}' . $blocks
    . 'html,body{margin:0;padding:0}'
    . 'body{font-size:12px;line-height:60px}'
    . 'p{margin:0}'
    . '.row{display:flex}.row>div{width:240pt}.brk{break-before:page}'
    . '</style>'
    . '<p>a1</p><p>a2</p><p>a3</p><p>a4</p>'
    . '<div class="row"><div><p>b1</p></div><div class="' . $second . '"><p>c1</p></div></div>'
    . '<p>d1</p><p>d2</p><p>d3</p><p>d4</p><p>d5</p><p>d6</p><p>d7</p><p>d8</p><p>d9</p>';

// Chrome paginates this one `a1..a4`, `b1 c1`, `d1..d9`, and gives the middle
// page the cover's own 540 by 270 sheet. The engine read 2 pages with the whole
// line and four `d` paragraphs together on page 1 and no cover sheet anywhere.
[$flexBytes, $flexPages] = Html::make(
    $flexDoc('@page cover{size:540pt 270pt;margin:60pt}.cover{page:cover}', 'cover'),
)->render();

ok('a `page` name on the second item of a flex row breaks before the whole line',
    $flexPages === 3
        && $pageText($flexBytes) === ['a1 a2 a3 a4', 'b1 c1', 'd1 d2 d3 d4 d5 d6 d7 d8 d9']
        && substr_count($flexBytes, '/MediaBox [0 0 540.00 270.00]') === 1
        && substr_count($flexBytes, '/MediaBox [0 0 540.00 540.00]') === 2,
    $flexPages . ': ' . implode(' | ', $pageText($flexBytes)));

// The other spelling, which is what says this is not about the `page` property:
// with a plain forced break there is no run to leave, so the `d` paragraphs
// follow the line on its own page until the ninth needs a third.
[$breakBytes, $breakPages] = Html::make($flexDoc('', 'brk'))->render();

ok('a forced break on the second item of a flex row breaks before the whole line',
    $breakPages === 3
        && $pageText($breakBytes) === ['a1 a2 a3 a4', 'b1 c1 d1 d2 d3 d4 d5 d6 d7 d8', 'd9'],
    $breakPages . ': ' . implode(' | ', $pageText($breakBytes)));

// ---- round 76: a box that leaves a page it put nothing on ----

/**
 * `WD-thead-strand-bg.html`: a table whose repeating header cannot keep its
 * first row on the page it starts on, so the whole table moves to the next
 * page. The page it left kept a 24pt band of the table's own background with
 * nothing inside it, because the box was still measured and painted from the
 * page it never used. Chrome paints nothing at all there.
 *
 * Read as every fragment carrying a background of its own, per page, the way
 * the round 18m header cases above read one. **The numbers are Chrome's**, off
 * `docs/harness/streamdump.py`: page 1 empty, and on page 2 a 90pt table at the
 * top of the page holding an 18pt header and a 72pt row.
 */
$strandBands = static function (float $spacer): array {
    $html = '<style>@page{size:396pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:24px}'
        . 'table{width:396pt;background:#2244aa;border-collapse:collapse}'
        . 'td,th{padding:0;vertical-align:top}'
        . 'th{background:#8b1a1a;font-weight:normal;height:24px}'
        . 'td{background:#1b7a3a;height:96px}</style>'
        . '<div style="height:' . $spacer . 'px"></div>'
        . '<table><thead><tr><th>head</th></tr></thead>'
        . '<tbody><tr><td>alpha</td></tr></tbody></table>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 396.0, 288.0)) as $pi => $page) {
        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $out[] = sprintf('%d@%.0f+%.0f', $pi, $f->y, $f->h);
            }
        }
    }

    return $out;
};

ok('a table that leaves a page for its header paints no band on the page it left',
    $strandBands(352.0) === ['1@0+90', '1@0+18', '1@18+72'],
    json_encode($strandBands(352.0)));

// ---- round 77: a box with a used height that takes a break inside itself ----

/**
 * `WF-break-height-fits.html`: a 144pt box holding a paragraph and then a
 * forced break, with four 36pt paragraphs after it on a 288pt page. The box has
 * a used `height`, so Chrome spends that whole height on the page the box
 * started on and gives the continuation none of it: the flow after the box
 * resumes at 144pt on the page it started on and only the broken-off paragraph
 * is on the page after. This walked on from the break instead and painted 288pt
 * of band on one page and another 36pt on the next, for a box 144pt tall.
 *
 * **The numbers are Chrome's**, off `docs/harness/streamdump.py`: page 1 is a
 * 144pt band carrying `a1 c1 d1 e1 f1` and page 2 is `b1` with no band at all.
 *
 * The two rows under it are the controls and they are the reason the first one
 * is about a used height rather than about forced breaks. With no height and
 * with `min-height` in its place, Chrome fills the page it leaves and keeps the
 * flow after the break, exactly as this engine does and did (defect IF).
 *
 * Every paragraph is one line by construction, at a `line-height` of a whole
 * 36pt, so none of the three readings needs a face metric.
 */
$breakHeight = static function (string $sizing): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:48px}'
        . 'p{margin:0}'
        . '.outer{' . $sizing . 'background:#cfe2ff}'
        . '.brk{break-before:page}</style>'
        . '<div class="outer"><p>a1</p><p class="brk">b1</p></div>'
        . '<p>c1</p><p>d1</p><p>e1</p><p>f1</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $bands = [];
        $words = [];

        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $bands[] = sprintf('%.0f+%.0f', $f->y, $f->h);
            }

            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        $out[] = sprintf('%d: [%s] %s', $pi, implode(' ', $bands), implode(' ', $words));
    }

    return $out;
};

ok('a box with a used height that breaks inside itself ends at that height on the page it started on',
    $breakHeight('height:144pt;') === ['0: [0+144] a1 c1 d1 e1 f1', '1: [] b1'],
    implode(' | ', $breakHeight('height:144pt;')));

ok('the same box with no used height fills the page it leaves and keeps the flow after the break',
    $breakHeight('') === ['0: [0+288] a1', '1: [0+36] b1 c1 d1 e1 f1'],
    implode(' | ', $breakHeight('')));

ok('a min-height is a floor and not a used height, so it reads as the box with none',
    $breakHeight('min-height:144pt;') === ['0: [0+288] a1', '1: [0+36] b1 c1 d1 e1 f1'],
    implode(' | ', $breakHeight('min-height:144pt;')));


// ---- round 78: an absolutely positioned box whose parent took a forced break ----

/**
 * `WL-break-abs-static.html`: nine 36pt paragraphs fill page 1 and put `a9`
 * alone at the top of page 2, then a box carrying `break-before: page` goes to
 * page 3 holding `b1` and an absolutely positioned `x1` with no offsets at all.
 *
 * With auto offsets the box sits at its **static position**, which is inside
 * its parent, so it belongs on the page the parent landed on. This engine took
 * the flow coordinate layout had recorded and placed the box against page 1's
 * own corner, which is where the parent would have been if the break had never
 * fired, so `x1` came out one page early and on a page its parent never
 * reached: defect IG, found as bullet 107.
 *
 * **The numbers are Chrome's**, off `docs/harness/streamdump.py`: page 3
 * carries the box's own 36pt band at the page top and `x1`'s band 36pt below
 * it, with `c1` sharing that second line because an out-of-flow box leaves no
 * room behind it.
 *
 * The row under it is `WN-break-abs-offsets.html` and it is a control rather
 * than a second case of the same thing: **it passes on both trees and it is
 * meant to.** With a declared `top` the box is not at a static position at
 * all, it is positioned against the initial containing block, and Chrome puts
 * it on page 1 even though its parent is on page 3. It fails if the re-base is
 * ever widened to boxes with real offsets, which is the mistake this round
 * made once and the probe caught.
 *
 * Every paragraph is one line by construction at a whole 36pt `line-height`,
 * so neither reading needs a face metric.
 */
$absAfterBreak = static function (string $inset): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:48px}'
        . 'p{margin:0}'
        . '.brk{break-before:page;background:#cfe2ff}'
        . '.abs{position:absolute;' . $inset . 'background:#ffe8cc}</style>'
        . '<p>a1</p><p>a2</p><p>a3</p><p>a4</p><p>a5</p><p>a6</p><p>a7</p><p>a8</p><p>a9</p>'
        . '<div class="brk"><p>b1</p><p class="abs">x1</p></div>'
        . '<p>c1</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $bands = [];
        $words = [];

        foreach ($page as $f) {
            if ($f->node->background !== null) {
                $bands[] = sprintf('%.0f+%.0f', $f->y, $f->h);
            }

            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        $out[] = sprintf('%d: [%s] %s', $pi, implode(' ', $bands), implode(' ', $words));
    }

    return $out;
};

ok('an absolutely positioned box with auto offsets follows the parent a forced break moved',
    $absAfterBreak('') === [
        '0: [] a1 a2 a3 a4 a5 a6 a7 a8',
        '1: [] a9',
        '2: [0+36 36+36] b1 c1 x1',
    ],
    implode(' | ', $absAfterBreak('')));

ok('a declared offset is measured from the first page and does not follow the parent',
    $absAfterBreak('top:36pt;left:144pt;') === [
        '0: [36+36] a1 a2 a3 a4 a5 a6 a7 a8 x1',
        '1: [] a9',
        '2: [0+36] b1 c1',
    ],
    implode(' | ', $absAfterBreak('top:36pt;left:144pt;')));


/*
 * `WO-break-in-cell.html`: a forced break inside a table cell, on a row that
 * fits the page it starts on. The band walk emitted every item of a band that
 * fits whole, so the cell's children were never walked and the declaration was
 * lost: the same shape round 74 fixed in `placeNode`, one level further down.
 *
 * Chrome cuts the row at the break and reads `a1 p1` then `x1 t1`, with the
 * text origins 275.250 and 203.250 on page 1 and 275.250 and 257.250 on
 * page 2. `WP-break-in-block.html` is the same document with the table taken
 * out and both engines already agreed on it, so the cell is the subject and
 * not the break.
 *
 * Every paragraph is one line at a whole 18pt `line-height`, so neither
 * reading needs a face metric.
 */
$breakInCell = static function (bool $inCell): array {
    $inner = '<p>p1</p><p class="brk">x1</p>';
    $html  = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . 'table{border-collapse:collapse;width:216pt}'
        . 'td{padding:0;vertical-align:top}'
        . '.fill{height:72pt}'
        . '.brk{break-before:page}</style>'
        . '<div class="fill"><p>a1</p></div>'
        . ($inCell
            ? '<table><tr><td>' . $inner . '</td></tr></table>'
            : '<div style="width:216pt">' . $inner . '</div>')
        . '<p>t1</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('a forced break inside a table cell cuts the row it is in',
    $breakInCell(true) === ['0: a1 p1', '1: x1 t1'],
    implode(' | ', $breakInCell(true)));


/*
 * `WS-break-in-avoid.html`: a `break-inside: avoid` box taller than the whole
 * page. `isSplittable()` answers "no" to `avoid`, so the walk never reaches
 * `staysHere()` and the last-resort branch for a box taller than the page
 * split it where the cursor already was. Chrome gives such a box ONE fresh
 * page before breaking it anyway.
 *
 * Chrome puts `t1` alone on page 1, `p1 f01..f15` on page 2, `f16 f17 f18` on
 * page 3 and `x1` on page 4, with every text origin from 275.250 down in 18pt
 * steps. `WU-break-avoid-attop.html` is the same document with the filler
 * taken out, so the box already starts at the top of a page: it gets no
 * second page, because the move would buy a blank one, and both engines
 * agreed on it before this fix and still do. `WT-break-in-plain.html` is the
 * same document without the `avoid` and is the control that says the
 * declaration is the subject.
 *
 * Every paragraph is one line at a whole 18pt `line-height`, so neither
 * reading needs a face metric.
 */
$breakAvoidTall = static function (bool $withFiller): array {
    $fillers = '';

    for ($i = 1; $i <= 18; $i++) {
        $fillers .= sprintf('<p>f%02d</p>', $i);
    }

    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.fill{height:72pt}'
        . '.keep{break-inside:avoid}'
        . '.brk{break-before:page}</style>'
        . ($withFiller ? '<div class="fill"><p>t1</p></div>' : '')
        . '<div class="keep"><p>p1</p>' . $fillers . '<p class="brk">x1</p></div>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('a break-inside: avoid box taller than the page takes one fresh page first',
    $breakAvoidTall(true) === [
        '0: t1',
        '1: p1 f01 f02 f03 f04 f05 f06 f07 f08 f09 f10 f11 f12 f13 f14 f15',
        '2: f16 f17 f18',
        '3: x1',
    ],
    implode(' | ', $breakAvoidTall(true)));

ok('and it takes no second page when it already starts at the top of one',
    $breakAvoidTall(false) === [
        '0: p1 f01 f02 f03 f04 f05 f06 f07 f08 f09 f10 f11 f12 f13 f14 f15',
        '1: f16 f17 f18',
        '2: x1',
    ],
    implode(' | ', $breakAvoidTall(false)));


/*
 * `WY-break-in-float.html` and `WX-page-named-in-float.html`: a forced break
 * and a change of page name inside a FLOAT. Both are decided by the same walk,
 * `breaksInside()` plus `flowChildren()`, which skipped an out-of-flow child
 * and walked a floating one, so a float's subtree broke the flow it was placed
 * beside. **Chrome paginates both documents as ONE page.**
 *
 * The filler above the float is load-bearing: `forcePageBreak()` does nothing
 * at the top of a page, so a float placed at the very top makes the document
 * agree for a reason that is not this one.
 *
 * The third case is the control and it must keep breaking: the same document
 * with the wrapper not floated is ordinary flow, and both engines put the
 * b-run on a second page there.
 */
$inFloat = static function (string $decl, bool $float): array {
    $bs = '';

    for ($i = 1; $i <= 9; $i++) {
        $bs .= sprintf('<p>b%d</p>', $i);
    }

    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.side{width:108pt' . ($float ? ';float:right' : '') . '}'
        . '</style>'
        . '<p>t1</p><p>t2</p><p>t3</p>'
        . '<div class="side"><div style="' . $decl . '"><p>a1</p><p>a2</p><p>a3</p></div></div>'
        . $bs;

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

$allOnOnePage = ['0: a1 a2 a3 b1 b2 b3 b4 b5 b6 b7 b8 b9 t1 t2 t3'];

ok('a forced break inside a float does not break the flow beside it',
    $inFloat('break-before:page', true) === $allOnOnePage,
    implode(' | ', $inFloat('break-before:page', true)));

ok('and neither does a change of page name inside one',
    $inFloat('page:cover', true) === $allOnOnePage,
    implode(' | ', $inFloat('page:cover', true)));

ok('but the same break in ordinary flow still breaks',
    count($inFloat('break-before:page', false)) === 2,
    implode(' | ', $inFloat('break-before:page', false)));


/*
 * `XA-break-on-flex-item.html` and `WZ-float-flex-item.html`: the boundary on
 * the case above. CSS Flexible Box §3 says `float` does not apply to a flex
 * item, so a flex item carrying `float: right` is not a float and the clause
 * that lets a float swallow a forced break must not reach it. The first
 * version of that clause did, and put a document Chrome paginates over two
 * pages onto one.
 *
 * Chrome reads `t1 t2 t3` on page 1 and the row plus the b-run on page 2, with
 * `a1` at `x 144.000 y 240.000` and `s1` at `x 36.000 y 240.000`. The filler
 * above the row is load-bearing: `forcePageBreak()` does nothing at the top of
 * a page, and without it both engines put the whole document on one page for a
 * reason that is not this one.
 */
$floatedFlexItem = static function (): int {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.row{display:flex}'
        . '.item{width:108pt}</style>'
        . '<p>t1</p><p>t2</p><p>t3</p>'
        . '<div class="row">'
        . '<div class="item"><p>s1</p></div>'
        . '<div class="item" style="float:right;break-before:page"><p>a1</p></div>'
        . '</div>'
        . '<p>b1</p>';

    return count((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)));
};

ok('a flex item is not a float, so a forced break on one still breaks',
    $floatedFlexItem() === 2,
    sprintf('%d page(s)', $floatedFlexItem()));


/*
 * Defect IK: the band walk never asked its OWN item for a page.
 *
 * `flowChildren()` has asked a child for a page name since round 72 and
 * `splitContainer()`'s band walk has asked its item both that and
 * `break-before` since round 74. `splitGrid()`, which walks grid items and
 * table cells, asked neither, and asked `break-after` nowhere. `breaksInside()`
 * reads a band item's DESCENDANTS, which is why a declaration one level down
 * inside the item already worked and only the item itself was missed.
 *
 * Six cases and two controls, and every number below is CHROME's, read off
 * `$WORK/z/cases/*.html` through `pagecmp.py` on 288x288 sheets. The probe
 * pages are `XB-page-named-size-grid.html`, `XD-page-named-size-td.html`,
 * `XF-break-on-grid-item.html`, `XG-break-on-td.html`,
 * `XH-break-after-grid-item.html` and `XI-break-after-td.html`, with
 * `XC-page-named-size-cell.html` and `XE-page-named-size-in-grid.html` as the
 * two that agreed untouched.
 *
 * The three filler lines above the box are load-bearing for the two spellings
 * that break BEFORE it: `forcePageBreak()` does nothing at the top of a page.
 * A `break-after` needs none, because the band has been placed by the time it
 * is asked.
 */
$bandItem = static function (string $wrap, string $decl, bool $filler): array {
    $inner = '<p>a1</p><p>a2</p><p>a3</p>';
    $box = $wrap === 'grid'
        ? '<div class="wrap"><div style="' . $decl . '">' . $inner . '</div></div>'
        : '<table><tr><td style="' . $decl . '">' . $inner . '</td></tr></table>';

    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . 'table{border-collapse:collapse;border-spacing:0;margin:0}'
        . 'td{padding:0;vertical-align:top}'
        . '.wrap{display:grid;gap:0;margin:0;padding:0}</style>'
        . ($filler ? '<p>s1</p><p>s2</p><p>s3</p>' : '')
        . $box
        . '<p>b1</p><p>b2</p><p>b3</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

// A page NAME breaks twice: once into the named run and once out of it again,
// because the box after it carries the ordinary name.
$namedRun = ['0: s1 s2 s3', '1: a1 a2 a3', '2: b1 b2 b3'];
$brokeBefore = ['0: s1 s2 s3', '1: a1 a2 a3 b1 b2 b3'];
$brokeAfter = ['0: a1 a2 a3', '1: b1 b2 b3'];

ok('a page name on a grid item becomes the page type',
    $bandItem('grid', 'page:cover', true) === $namedRun,
    implode(' | ', $bandItem('grid', 'page:cover', true)));

ok('and a page name on a table cell does too',
    $bandItem('table', 'page:cover', true) === $namedRun,
    implode(' | ', $bandItem('table', 'page:cover', true)));

ok('a break-before on a grid item breaks the flow',
    $bandItem('grid', 'break-before:page', true) === $brokeBefore,
    implode(' | ', $bandItem('grid', 'break-before:page', true)));

ok('and a break-before on a table cell does too',
    $bandItem('table', 'break-before:page', true) === $brokeBefore,
    implode(' | ', $bandItem('table', 'break-before:page', true)));

ok('a break-after on a grid item breaks the flow',
    $bandItem('grid', 'break-after:page', false) === $brokeAfter,
    implode(' | ', $bandItem('grid', 'break-after:page', false)));

ok('and a break-after on a table cell does too',
    $bandItem('table', 'break-after:page', false) === $brokeAfter,
    implode(' | ', $bandItem('table', 'break-after:page', false)));

/*
 * The two controls. Both put the declaration one level DOWN, inside the band
 * item rather than on it, which `breaksInside()` has always seen. They pass on
 * every tree this round built and are here to say the defect was the item and
 * never the container.
 */
$insideItem = static function (string $wrap, string $decl): array {
    $inner = '<div style="' . $decl . '"><p>a1</p><p>a2</p><p>a3</p></div>';
    $box = $wrap === 'grid'
        ? '<div class="wrap"><div>' . $inner . '</div></div>'
        : '<table><tr><td>' . $inner . '</td></tr></table>';

    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . 'table{border-collapse:collapse;border-spacing:0;margin:0}'
        . 'td{padding:0;vertical-align:top}'
        . '.wrap{display:grid;gap:0;margin:0;padding:0}</style>'
        . '<p>s1</p><p>s2</p><p>s3</p>'
        . $box
        . '<p>b1</p><p>b2</p><p>b3</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('CONTROL: a page name INSIDE a grid item already worked',
    $insideItem('grid', 'page:cover') === $namedRun,
    implode(' | ', $insideItem('grid', 'page:cover')));

ok('CONTROL: a break-before INSIDE a table cell already worked',
    $insideItem('table', 'break-before:page') === $brokeBefore,
    implode(' | ', $insideItem('table', 'break-before:page')));


/*
 * Defect IL: `splitContainer()` asked `end($items)` about `break-after: page`
 * where it asks every item about `break-before` and about a page name. A band
 * that is not a flex line holds one item, so the two readings only differ on a
 * flex line, and there Chrome breaks after the WHOLE line when any one item
 * asks. `XL-break-after-first-of-flexline.html` is the probe and
 * `XJ-break-after-first-of-band.html` is the same question one walk over,
 * which is where it was found.
 *
 * One of 8,000 census documents moves on this, which is the honest size of it.
 */
$flexLineBreakAfter = static function (): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.flex{display:flex;margin:0;padding:0}'
        . '.flex>div{margin:0;padding:0;width:72pt}</style>'
        . '<div class="flex"><div style="break-after:page"><p>a1</p></div><div><p>c1</p></div></div>'
        . '<p>b1</p><p>b2</p><p>b3</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('a break-after on the first item of a flex line breaks after the whole line',
    $flexLineBreakAfter() === ['0: a1 c1', '1: b1 b2 b3'],
    implode(' | ', $flexLineBreakAfter()));


/*
 * Defect IM: round 80's fresh page for a `break-inside: avoid` box taller than
 * the page is right, and it is decided by the box's FIRST RUN rather than by
 * the whole box. A forced break inside cuts the box into runs, and `avoid` is
 * about keeping a run together.
 *
 * Three documents settled it and the third is the one that did:
 *   `WS-break-in-avoid.html`   342pt first run, fits no page  -> fresh page
 *   `XN-break-inside-avoid-tall.html`  144pt run, 162pt left  -> no fresh page
 *   `XP-break-inside-avoid-run-fits-page.html`  144pt run, 108pt left
 *                                                             -> fresh page
 * so the question is "does the run fit HERE" and never "does it fit anywhere".
 * `XO-break-inside-avoid-tall-nobreak.html` is `XN` with the declaration taken
 * out and it agreed untouched.
 *
 * Both cases below are the same 18-line avoid box; only the filler above it
 * changes, which is what moves the room left from 216pt to 108pt.
 */
$avoidFirstRun = static function (int $filler): array {
    $ss = '';

    for ($i = 1; $i <= $filler; $i++) {
        $ss .= sprintf('<p>s%d</p>', $i);
    }

    $as = '';

    for ($i = 1; $i <= 8; $i++) {
        $as .= sprintf('<p>a%d</p>', $i);
    }

    $cs = '';

    for ($i = 1; $i <= 10; $i++) {
        $cs .= sprintf('<p>c%d</p>', $i);
    }

    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.avoid{break-inside:avoid;margin:0;padding:0}</style>'
        . $ss
        . '<div class="avoid">' . $as . '<div style="break-before:page">' . $cs . '</div></div>'
        . '<p>b1</p><p>b2</p><p>b3</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('an avoid box takes no fresh page when its first run fits where it stands',
    $avoidFirstRun(4) === [
        '0: a1 a2 a3 a4 a5 a6 a7 a8 s1 s2 s3 s4',
        '1: b1 b2 b3 c1 c10 c2 c3 c4 c5 c6 c7 c8 c9',
    ],
    implode(' | ', $avoidFirstRun(4)));

ok('and it still takes one when the run does not fit the room left',
    $avoidFirstRun(10) === [
        '0: s1 s10 s2 s3 s4 s5 s6 s7 s8 s9',
        '1: a1 a2 a3 a4 a5 a6 a7 a8',
        '2: b1 b2 b3 c1 c10 c2 c3 c4 c5 c6 c7 c8 c9',
    ],
    implode(' | ', $avoidFirstRun(10)));


/*
 * Defect IN: `splitContainer()`'s overshoot correction reads a page a FORCED
 * break turned to as room the box never used, and pulls the cursor back to the
 * page floor. Everything after the box then starts on top of the box's own
 * content.
 *
 * The correction itself is right and `ZM-fold-flexrow-flexend.html` is what it
 * exists for: a flex row whose item is taller than the room left overflows
 * rather than growing the box, so the cursor comes back to the height the box
 * actually has. What it never asked is whether a forced break is what crossed
 * the page, which is the same scoping `splitGrid()`'s `rowspan` correction was
 * given in round 79 (defect IH) and this walk never got.
 *
 * `XT-page-named-grid-in-flex.html` is the reading, and it is bullet 108's own
 * document written clean: a named block inside a `display: grid` inside a
 * `display: flex` row. The engine painted `a1` and `b1` both at `y 240.000`,
 * `a2` and `b2` both at `222.000` and `a3` and `b3` both at `204.000`, three
 * pairs of boxes on one page, where Chrome writes three pages and every origin
 * agrees once the guard is in.
 *
 * The control is the same document with the page name taken off. It is ONE
 * page in Chrome and on every tree this round built, which is what says the
 * correction still fires where it should: if the guard were unconditional the
 * flex would stop coming back and this would move too.
 */
$namedInFlex = static function (bool $named): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.flex{display:flex;margin:0;padding:0}'
        . '.grid{display:grid;gap:0;margin:0;padding:0}</style>'
        . '<p>s1</p><p>s2</p><p>s3</p>'
        . '<div class="flex"><div class="grid">'
        . '<div' . ($named ? ' style="page:cover"' : '') . '>'
        . '<p>a1</p><p>a2</p><p>a3</p>'
        . '</div></div></div>'
        . '<p>b1</p><p>b2</p><p>b3</p><p>b4</p><p>b5</p>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

ok('a forced break inside a flex row is a page the box used, not an overshoot',
    $namedInFlex(true) === ['0: s1 s2 s3', '1: a1 a2 a3', '2: b1 b2 b3 b4 b5'],
    implode(' | ', $namedInFlex(true)));

ok('CONTROL: with no page name the flex row still comes back to its own height',
    $namedInFlex(false) === ['0: a1 a2 a3 b1 b2 b3 b4 b5 s1 s2 s3'],
    implode(' | ', $namedInFlex(false)));

// ---- round 82, defect IO: a named `@page` whose sheet is NARROWER ----

/**
 * `XW-page-named-width-narrower.html` and `XV-page-named-width-only.html`, one
 * function with the cover's own `size` as its argument.
 *
 * The document is 432x288 with a 36pt margin, so its content box is 360 wide
 * and 216 tall, which is twelve lines of 18pt. Three lines of filler, three in
 * the named run and eleven after put the named page in the middle of three.
 */
$namedSheet = static function (string $cover, string $tail = ''): array {
    $p = static fn(string $label): string => '<p>' . $label . $tail . '</p>';

    $html = '<style>@page{size:432pt 288pt;margin:36pt}@page cover{size:' . $cover . '}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-family:\'DejaVu Sans\';font-size:12px;line-height:18pt}'
        . 'p{margin:0}.cover{page:cover}</style>'
        . $p('s1') . $p('s2') . $p('s3')
        . '<div class="cover">' . $p('a1') . $p('a2') . $p('a3') . '</div>'
        . $p('b1') . $p('b2') . $p('b3');

    [$bytes] = Html::make($html)->render();

    preg_match_all('/\/MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]/', $bytes, $found, PREG_SET_ORDER);

    return array_map(static fn(array $m): string => $m[1] . 'x' . $m[2], $found);
};

// The sheet LIST rather than a count of each sheet, because the count cannot
// say which page got which and the order is half of what these read.
// Chrome writes `432x288 | 288x288 | 432x288` on this document, read off
// `XW-page-named-width-narrower.html` with pypdf before the case was written.
ok('a named `@page` asking for a NARROWER sheet gets it, and the other pages keep the document\'s',
    $namedSheet('288pt 288pt') === ['432.00x288.00', '288.00x288.00', '432.00x288.00'],
    implode(' | ', $namedSheet('288pt 288pt')));

// Chrome writes `432x288 | 576x288 | 432x288`, read off
// `YW-page-named-wider-sheet.html`, which is this exact document. Until round 87
// the engine wrote the document's own sheet on all three and that was HX's
// recorded restriction; the document is laid out at the WIDEST content box any
// page kind asks for now, and the narrower pages show those same lines inside
// their own margins.
ok('a named `@page` asking for a WIDER sheet gets it, and the other pages keep the document\'s',
    $namedSheet('576pt 288pt') === ['432.00x288.00', '576.00x288.00', '432.00x288.00'],
    implode(' | ', $namedSheet('576pt 288pt')));

// The CONTROL, and it is what says the rule is the INK and not the sheet.
// The same wider cover with paragraphs long enough to wrap differently at the
// two widths: lines fitted to the cover's 504pt content box do not fit the
// document's own 360pt one, so laying the document out wide would paint them
// past a margin it asked to keep clear. Every page keeps the document's sheet,
// which is what the whole of this did before round 87.
// `YV-page-named-wider-wrap.html` is the same shape as a probe page and Chrome
// gives the named sheet there and wraps each narrow page's text onto two lines,
// which one layout cannot do and this does not claim to.
ok('CONTROL: a WIDER named sheet is refused where the lines would not fit the narrower pages',
    $namedSheet('576pt 288pt', ' the quick brown fox jumps over the lazy dog the quick brown fox jumps over the lazy dog')
        === ['432.00x288.00', '432.00x288.00', '432.00x288.00'],
    implode(' | ', $namedSheet('576pt 288pt', ' the quick brown fox jumps over the lazy dog the quick brown fox jumps over the lazy dog')));

// ---- round 83, defect IP: a forced break after a box whose height is zero ----

/**
 * `YA-break-after-zero-height.html` and `YC-break-after-zero-height-empty.html`,
 * one function with the zero-height box's content as its argument.
 *
 * A box whose own height is zero leaves the cursor at the page top after it and
 * everything inside it has been placed, so `forcePageBreak()` read the page as
 * untouched and did nothing. The box reaches the top of a page through its own
 * `break-before: page`, so no page name is involved and the box is a plain
 * block: the defect is neither a grid nor a named run.
 *
 * `line-height: 18pt` forces the line boxes, so no reading here depends on
 * which face the suite happens to find.
 */
$zeroHeightBreak = static function (bool $inked): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.zero{height:0;break-before:page;margin:0;padding:0}'
        . '.after{break-before:page;margin:0;padding:0}</style>'
        . '<p>s1</p><p>s2</p><p>s3</p>'
        . '<div class="zero">' . ($inked ? '<p>g1</p><p>g2</p><p>g3</p>' : '') . '</div>'
        . '<div class="after"><p>x1</p><p>x2</p><p>x3</p></div>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

// Chrome writes three pages here, `s1 s2 s3 | g1 g2 g3 | x1 x2 x3`, read off
// `YA-break-after-zero-height.html` with pypdf before the case was written.
// This engine wrote two, with the second sibling drawn on top of the first:
// six text runs at three positions, identical in x AND in y.
ok('a `break-before: page` after a zero-height box that inked the page breaks',
    $zeroHeightBreak(true) === ['0: s1 s2 s3', '1: g1 g2 g3', '2: x1 x2 x3'],
    implode(' | ', $zeroHeightBreak(true)));

// `YC-break-after-zero-height-empty.html`, and it is the case that pins the
// RULE rather than the symptom. Chrome writes three pages with page 2
// GENUINELY BLANK, so the question a forced break asks is whether a box was
// placed on this page and never whether any ink reached it. A fix written to
// ask about ink passes the case above and fails this one.
ok('a `break-before: page` after an EMPTY zero-height box breaks too, onto a blank page',
    $zeroHeightBreak(false) === ['0: s1 s2 s3', '1: ', '2: x1 x2 x3'],
    implode(' | ', $zeroHeightBreak(false)));

/**
 * `YD-page-named-zero-height-sheet.html`, the third symptom read as a sheet.
 *
 * The exit from a named run is asked at cursor 0.00 as well, so it did nothing
 * and `enterPageType('')` overwrote `enterPageType('plain')` at the same page
 * index: the named page vanished from the map and the painter never saw it.
 * The named sheet keeps the document's WIDTH and moves only its height, because
 * a named `size` asking for a WIDER content box keeps the document's own sheet
 * and this case would then be measuring HX's restriction instead.
 */
$zeroHeightNamedSheet = static function (): array {
    $html = '<style>@page{size:288pt 288pt;margin:36pt}@page plain{size:288pt 144pt;margin:36pt}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-family:\'DejaVu Sans\';font-size:12px;line-height:18pt}'
        . 'p{margin:0}.named{height:0;margin:0;padding:0;page:plain}'
        . '.after{break-before:page;margin:0;padding:0}</style>'
        . '<p>s1</p><p>s2</p><p>s3</p>'
        . '<div class="named"><p>g1</p><p>g2</p><p>g3</p></div>'
        . '<div class="after"><p>x1</p><p>x2</p><p>x3</p></div>';

    [$bytes, $pages] = Html::make($html)->render();

    return [
        $pages,
        substr_count($bytes, '/MediaBox [0 0 288.00 288.00]'),
        substr_count($bytes, '/MediaBox [0 0 288.00 144.00]'),
    ];
};

// Chrome writes `288x288 | 288x144 | 288x288` on this document. The engine
// wrote two pages of `288x288` and lost the named sheet completely.
ok('a named `@page` on a zero-height box keeps its own sheet and the run still exits',
    $zeroHeightNamedSheet() === [3, 2, 1],
    implode(',', $zeroHeightNamedSheet()));

/**
 * `YG-break-under-a-margin-only.html`, the other half of the same guard.
 *
 * A margin above the first box moves the cursor down a page nothing has been
 * placed on, so the break fired and bought a blank page. Chrome writes ONE page
 * here. The two halves cancelled in `cdoc-1-137`'s page count until the first
 * was fixed, which is why this one is a case of its own.
 */
$marginOnlyBreak = static function (): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}'
        . '.first{margin-top:18pt;padding:0;break-before:page}</style>'
        . '<div class="first"><p>c1</p><p>c2</p><p>c3</p></div>';

    $out = [];

    foreach ((new Fragmenter(288.0))->fragment(layout($html, 288.0, 288.0)) as $pi => $page) {
        $words = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
                }
            }
        }

        sort($words);
        $out[] = sprintf('%d: %s', $pi, implode(' ', $words));
    }

    return $out;
};

// Chrome writes one page. This engine wrote two, the first of them blank.
ok('a forced break under nothing but a margin does not buy a blank page',
    $marginOnlyBreak() === ['0: c1 c2 c3'],
    implode(' | ', $marginOnlyBreak()));

/**
 * Defect AJ, `docs/harness/probes/YH-display-initial-unset.html`.
 *
 * `display: initial` and `display: unset` took the INITIAL table's `block`,
 * which is this engine's default for a box with no declaration on it, where
 * CSS's initial value for `display` is `inline`. A `display` left invalid by
 * `var()` was kept rather than dropped and reached the builder as text, which
 * came out as a block for the same reason.
 *
 * Chrome puts both paragraphs on ONE line for either spelling, both runs at
 * y 276.000 with x 0.000 and x 22.031, and writes TWO lines with no
 * declaration at all, y 276.000 and y 258.000. The reading is line boxes and
 * not positions, so no glyph advance is in it.
 */
$displayLines = static function (string $extra): array {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:18pt}'
        . 'p{margin:0}' . $extra . '</style>'
        . '<div><p class="subject">alpha</p><p class="subject">beta</p></div>';

    $walk = static function (Node $n) use (&$walk): array {
        $out = [];

        foreach ($n->lineBoxes as $lb) {
            $words = [];

            foreach ($lb->items as $item) {
                if (!$item->isSpace && trim($item->text) !== '') { $words[] = trim($item->text); }
            }

            if ($words !== []) { $out[] = implode(' ', $words); }
        }

        foreach ($n->children as $c) { $out = array_merge($out, $walk($c)); }

        return $out;
    };

    return $walk(layout($html, 288.0, 288.0));
};

$initialDisplay = '.subject{display:initial}';
$invalidDisplay = 'div{--bad:notalength}.subject{display:var(--bad)}';

ok('`display: initial` computes to `inline` and not to the engine default `block`',
    $displayLines($initialDisplay) === ['alpha beta'],
    implode(' | ', $displayLines($initialDisplay)));

ok('a `display` left invalid by `var()` is dropped and falls back to `inline`',
    $displayLines($invalidDisplay) === ['alpha beta'],
    implode(' | ', $displayLines($invalidDisplay)));

/**
 * Defect AK, `docs/harness/probes/YI-literal-invalid-loser.html` and
 * `docs/harness/probes/YJ-literal-invalid-same-block.html`.
 *
 * A literal invalid declaration was kept rather than dropped, so the property
 * fell back to a consumer's default and the declaration that lost to it was
 * thrown away with it. Chrome writes a 27.000 band for both shapes below,
 * which is the losing `line-height: 3` at a 12px font size.
 */
$loserLineHeight = static function (string $subject): float {
    $html = '<style>@page{size:288pt 288pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'body{font-size:12px;line-height:24px}'
        . 'p{margin:0}'
        . '.loser{line-height:3}</style>'
        . '<p class="loser" ' . $subject . '>alpha</p>';

    $walk = static function (Node $n) use (&$walk): array {
        $out = [];

        foreach ($n->lineBoxes as $lb) {
            if ($lb->items !== []) { $out[] = $lb->height; }
        }

        foreach ($n->children as $c) { $out = array_merge($out, $walk($c)); }

        return $out;
    };

    $heights = $walk(layout($html, 288.0, 288.0));

    return $heights[0] ?? 0.0;
};

ok('an invalid declaration is dropped so the rule that lost to it wins',
    abs($loserLineHeight('style="line-height: notanumber"') - 27.0) < 0.01,
    sprintf('%.3f', $loserLineHeight('style="line-height: notanumber"')));

// Three answers are open here and only one is Chrome's: 36.000 is the earlier
// declaration in the same block, 27.000 is the class it outranks, and 10.500 is
// the invalid value being kept. Chrome writes 36.000.
ok('an invalid declaration does not take the earlier one in its own block with it',
    abs($loserLineHeight('style="line-height: 4; line-height: notanumber"') - 36.0) < 0.01,
    sprintf('%.3f', $loserLineHeight('style="line-height: 4; line-height: notanumber"')));

/*
 * Round 85: only the FIRST shadow of a `text-shadow` list was drawn.
 * `docs/harness/probes/YP-text-shadow-two.html` and
 * `docs/harness/probes/YR-text-shadow-order.html`.
 *
 * CSS Text Decoration 3 makes `text-shadow` a comma-separated list, painted
 * front to back with every layer under the text. The parser stopped at the
 * first layer, so a second shadow was silently dropped.
 *
 * Both numbers are Chrome's, read off the two probe pages. On `YP`, two
 * shadows, Chrome shows the text 3 times where the engine showed it 2. On
 * `YR`, three shadows coloured red, blue and green in that written order,
 * Chrome lays the fills down last-written first: green, blue, red, then the
 * black text on top.
 */
$shadowFills = static function (string $declaration): array {
    $html = '<style>@page{size:400pt 200pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'p{margin:0;font-size:12px;line-height:24px;color:#000000;'
        . 'text-shadow:' . $declaration . '}</style>'
        . '<p>Order</p>';

    [$bytes] = Html::make($html)->render();

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    $fills = [];

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);

        if ($plain === false) { continue; }

        // Walk the operators in order and remember the fill in force, so the
        // colour reported for a text pass is the one it was actually shown
        // in. Splitting the stream on `rg` cannot do that: a pass that shows
        // text without restating its colour picks up the previous chunk's.
        $current = null;

        preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) rg|(Tj|TJ)/', $plain, $ops, PREG_SET_ORDER);

        foreach ($ops as $op) {
            if (($op[4] ?? '') !== '') {
                if ($current !== null) { $fills[] = $current; $current = null; }

                continue;
            }

            $current = sprintf('#%02x%02x%02x',
                (int) round(((float) $op[1]) * 255),
                (int) round(((float) $op[2]) * 255),
                (int) round(((float) $op[3]) * 255));
        }
    }

    return $fills;
};

$twoShadowPasses = $shadowFills('-2pt -2pt, 2pt 2pt');

ok('every layer of a text-shadow list is drawn, not just the first',
    count($twoShadowPasses) === 3,
    sprintf('%d text passes, Chrome draws 3', count($twoShadowPasses)));

// Three answers are open on the order and only one is Chrome's: the written
// order red, blue, green would mean the LAST layer ends on top, a single
// entry would mean the list is still truncated, and green, blue, red is CSS's
// rule that the first layer is painted over the rest.
$orderedFills = $shadowFills('10pt 0 #ff0000, 20pt 0 #0000ff, 30pt 0 #00ff00');

ok('a text-shadow list is painted last layer first, so the first ends on top',
    $orderedFills === ['#00ff00', '#0000ff', '#ff0000', '#000000'],
    implode(' ', $orderedFills));

/*
 * Round 85: `Times New Roman` and `Courier New` were laid out with Adobe's
 * Times and Courier, where Chrome uses the macOS faces of those names.
 * Defects HV and DM, which were one substitution-policy decision.
 *
 * Every number below is Chrome's, read off
 * `docs/harness/probes/PY-family-baseline.html` through
 * `build/probe/layout-reference.py`, which is `getBoundingClientRect` and not a
 * raster. Sizes are in CSS pixels and the engine works in points, 1px = 0.75pt.
 *
 * The two faces still draw with the base-14 glyphs and still name a base-14
 * face in `/BaseFont`; what moved is where the line box and the baseline go.
 */
$band = static function (string $family, float $cssPixels): array {
    // Through a document and the family name, which is the path a page takes.
    // Reaching for `new Font('Times New Roman')` would answer out of the face
    // table whatever the registry does with the name, so it would pass on a
    // tree where the alias still resolves to Adobe's Times.
    $html = '<style>@page{size:400pt 200pt;margin:0}'
        . 'html,body{margin:0;padding:0}'
        . 'p{margin:0;line-height:normal;font-family:\'' . $family . '\';'
        . 'font-size:' . $cssPixels . 'px}</style>'
        . '<p>Hxg</p>';

    $walk = static function (Node $n) use (&$walk): ?\FlexPDF\Engine\LineBox {
        foreach ($n->lineBoxes as $lb) {
            if ($lb->items !== []) { return $lb; }
        }

        foreach ($n->children as $c) {
            $found = $walk($c);
            if ($found !== null) { return $found; }
        }

        return null;
    };

    $line = $walk(layout($html, 400.0, 200.0));

    if ($line === null) { return [0.0, 0.0]; }

    return [$line->baseline / 0.75, $line->height / 0.75];
};

// 18px `Times New Roman`: Chrome puts the baseline 16 CSS pixels down a
// 21-pixel line box. Adobe's Times gives 17 in a box of 22.
[$tnrBase, $tnrLine] = $band('Times New Roman', 18.0);

ok('`Times New Roman` takes the baseline of the face Chrome lays it out with',
    abs($tnrBase - 16.0) < 0.01 && abs($tnrLine - 21.0) < 0.01,
    sprintf('baseline %.2f of %.2f, Chrome has 16 of 21', $tnrBase, $tnrLine));

// 14px `Courier New`: Chrome puts the baseline 12 CSS pixels down a 16-pixel
// line box. Adobe's Courier gives 13, which is the 5-pixel error at its worst
// further up the ladder.
[$cnBase, $cnLine] = $band('Courier New', 14.0);

ok('`Courier New` takes the baseline of the face Chrome lays it out with',
    abs($cnBase - 12.0) < 0.01 && abs($cnLine - 16.0) < 0.01,
    sprintf('baseline %.2f of %.2f, Chrome has 12 of 16', $cnBase, $cnLine));

/*
 * Round 86: a box lying wholly outside the sheet was still painted and still
 * given a structure element, so a screen reader was read a paragraph no
 * sighted reader could see.
 * `docs/harness/probes/YU-offsheet-tagged.html` and
 * `docs/harness/probes/YS-run-starts-off-sheet.html`.
 *
 * Both numbers are CHROME's, read off the probe pages. Chrome exports the
 * three-paragraph document with the middle one at `left: 900pt` as **two**
 * structure elements and **38** glyphs; this engine wrote three and 75.
 *
 * The second case is the control that decides the RULE. Culling by where a
 * text run starts instead of by where its box is deletes ink: `YS` is one
 * `white-space: nowrap` line at `left: -200pt` whose run begins 200pt off the
 * left edge and paints onto the page, and dropping it costs 802 pixels.
 */
$glyphsDrawn = static function (string $bytes): int {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    $total = 0;

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);

        if ($plain === false) { continue; }

        preg_match_all('/<([0-9A-Fa-f\s]*)>\s*Tj|\((?:\\.|[^\\()])*\)\s*Tj/', $plain, $shows);

        foreach ($shows[0] as $show) {
            foreach (preg_split('/(?=<)/', $show) ?: [] as $piece) {
                if (preg_match('/<([0-9A-Fa-f\s]*)>/', $piece, $hex) === 1) {
                    $total += (int) (strlen((string) preg_replace('/\s/', '', $hex[1])) / 4);
                }
            }

            if (preg_match('/^\((.*)\)\s*Tj$/s', $show, $lit) === 1) {
                $total += strlen($lit[1]);
            }
        }
    }

    return $total;
};

$offSheetDoc = '<style>@page{size:400pt 200pt;margin:0}'
    . 'html,body{margin:0;padding:0}'
    . 'p{margin:0;font-size:12px;line-height:24px}'
    . '.gone{white-space:nowrap;position:relative;left:900pt}</style>'
    . '<p>VisibleParagraphOne</p>'
    . '<p class="gone">CompletelyOffTheSheetAndNobodyCanSeeIt</p>'
    . '<p>VisibleParagraphTwo</p>';

[$offSheetBytes] = Html::make($offSheetDoc)->render();

ok('a box wholly off the sheet paints nothing',
    $glyphsDrawn($offSheetBytes) === 38,
    sprintf('%d glyphs, Chrome draws 38', $glyphsDrawn($offSheetBytes)));

// The control that decides the rule: the box straddles the left edge, so it
// stays whole even though the run it draws STARTS off the sheet.
$straddleDoc = '<style>@page{size:400pt 200pt;margin:0}'
    . 'html,body{margin:0;padding:0}'
    . 'p{margin:0;font-size:12px;line-height:24px;'
    . 'white-space:nowrap;position:relative;left:-200pt}</style>'
    . '<p>LEFTOFFSHEETxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxONTHEPAGE</p>';

[$straddleBytes] = Html::make($straddleDoc)->render();

ok('a box straddling the sheet edge keeps the run that starts outside it',
    $glyphsDrawn($straddleBytes) === 53,
    sprintf('%d glyphs, the whole line is 53', $glyphsDrawn($straddleBytes)));

/*
 * Round 88: a box a TRANSFORM carried off the sheet was still painted and
 * still given a structure element, which is round 86's row one exclusion
 * along. `fallsOffTheSheet()` kept every piece owing an effect, because the
 * matrix that decides where a transform lands had not been pushed at that
 * point; the matrix was knowable from the fragment's own effects all along.
 * `docs/harness/probes/YY-offsheet-transform-tagged.html`.
 *
 * Both numbers are CHROME's, read off these two documents rendered with
 * `--export-tagged-pdf`. Chrome draws 38 glyphs here and exports two
 * paragraphs; this engine drew 68 and exported three.
 */
$transformOffDoc = '<style>@page{size:400pt 300pt;margin:0}'
    . 'html,body{margin:0;padding:0}'
    . 'p{margin:0;font-size:12px;line-height:18pt}'
    . '.gone{transform:translate(2000pt,0)}</style>'
    . '<p>VisibleParagraphOne</p>'
    . '<p class="gone">CarriedOffTheSheetByATransform</p>'
    . '<p>VisibleParagraphTwo</p>';

[$transformOffBytes] = Html::make($transformOffDoc)->render();

ok('a box a transform carries off the sheet paints nothing',
    $glyphsDrawn($transformOffBytes) === 38,
    sprintf('%d glyphs, Chrome draws 38', $glyphsDrawn($transformOffBytes)));

/*
 * The control that decides the composition ORDER, and the only document
 * anywhere that can see it. CSS reads a transform list right to left, so
 * `scale(0.1) translate(2000pt, 0)` moves the box 2000pt out and then scales
 * it back to x 200, which is on the sheet. Folded the other way it lands at
 * x 2000 and the cull above deletes it.
 *
 * Every other `transform` in the probe corpus and every one the census
 * generator can write holds a SINGLE function, where the fold direction
 * cannot be observed at all. Chrome draws all 71 glyphs.
 */
$listOrderDoc = '<style>@page{size:400pt 300pt;margin:0}'
    . 'html,body{margin:0;padding:0}'
    . 'p{margin:0;font-size:12px;line-height:18pt}'
    . '.turned{transform:scale(0.1) translate(2000pt,0);transform-origin:0 0}</style>'
    . '<p>VisibleParagraphOne</p>'
    . '<p class="turned">PutBackOnTheSheetBySecondFunction</p>'
    . '<p>VisibleParagraphTwo</p>';

[$listOrderBytes] = Html::make($listOrderDoc)->render();

ok('a transform list is composed right to left, so the second function can put a box back',
    $glyphsDrawn($listOrderBytes) === 71,
    sprintf('%d glyphs, Chrome draws 71', $glyphsDrawn($listOrderBytes)));

/*
 * The other half of the same rule. An opacity, a blend mode, a mask and an
 * isolation composite what a piece paints and move none of it, so a piece
 * under one of those and under no transform at all is exactly as far off the
 * sheet as its own box says. Round 86 kept it anyway, because the exclusion
 * it wrote asked whether an effect was there rather than whether anything
 * moved. Chrome draws 38 glyphs here; this engine drew 64.
 */
$fadedOffDoc = '<style>@page{size:400pt 300pt;margin:0}'
    . 'html,body{margin:0;padding:0}'
    . 'p{margin:0;font-size:12px;line-height:18pt}'
    . '.faded{opacity:0.5}'
    . '.gone{white-space:nowrap;position:relative;left:900pt}</style>'
    . '<div class="faded">'
    . '<p>VisibleParagraphOne</p>'
    . '<p class="gone">OffTheSheetInsideAFadedBox</p>'
    . '<p>VisibleParagraphTwo</p>'
    . '</div>';

[$fadedOffBytes] = Html::make($fadedOffDoc)->render();

ok('a box off the sheet under an effect that moves nothing paints nothing',
    $glyphsDrawn($fadedOffBytes) === 38,
    sprintf('%d glyphs, Chrome draws 38', $glyphsDrawn($fadedOffBytes)));

/*
 * PDF's character spacing is text state and survives BT/ET, so the tracking a
 * heading set is still in force when the next thing drawn is an SVG `<text>`.
 * `Pdf::drawTextInUserSpace()` wrote no `Tc` of its own, so a chart label
 * after a letter-spaced heading came out spaced like the heading. Chrome
 * draws the label the same whatever precedes it.
 *
 * Read as the operators rather than as pixels. The run drawn in user space is
 * the one whose text matrix carries the counter-flip `1 0 0 -1`, which no
 * ordinary run writes, and it has to carry a `Tc` reset here. The tracked
 * value must NOT move with it: the SVG's own `q` and `Q` put the outer
 * spacing back, so the heading's tracking is what is in force again after the
 * `Q`, and the paragraph has to write its own `0.000 Tc` as it always did.
 * The second case is the control for that. Zeroing the tracked field here
 * instead of only the operator makes the paragraph think the reset is already
 * in effect, it writes none, and it comes out spaced like the heading.
 */
$svgAfterSpacedDoc = '<style>@page{size:320pt 200pt;margin:10pt}'
    . 'html,body{margin:0;padding:0}'
    . 'body{font-family:Helvetica;font-size:9pt}'
    . 'h2{font-size:8pt;letter-spacing:1.8pt;margin:0 0 4pt}'
    . 'p{margin:0 0 4pt}</style>'
    . '<h2>HEADINGWITHTRACKING</h2>'
    . '<svg width="200" height="30" viewBox="0 0 200 30">'
    . '<text x="4" y="20" font-size="9" fill="#000000">LabelInsideTheSvg</text>'
    . '</svg>'
    . '<p>ParagraphAfterTheSvg</p>';

[$svgAfterSpacedBytes] = Html::make($svgAfterSpacedDoc)->render();

$svgRun   = '';
$afterRun = '';

preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $svgAfterSpacedBytes, $svgStreams);

foreach ($svgStreams[1] as $stream) {
    $plain = @gzuncompress($stream);

    if ($plain === false) { continue; }

    if (preg_match('/BT [^E]*?1 0 0 -1 [^E]*?ET/s', $plain, $m) === 1) {
        $svgRun = $m[0];
    }

    if (preg_match('/BT [^E]*?ParagraphAfterTheSvg[^E]*?ET/s', $plain, $m) === 1) {
        $afterRun = $m[0];
    }
}

ok('an SVG label after a letter-spaced heading resets the character spacing',
    $svgRun !== '' && str_contains($svgRun, '0.000 Tc'),
    $svgRun === '' ? 'no user-space text run found' : 'the run carries no Tc reset');

ok('and the paragraph after the SVG still writes its own reset',
    $afterRun !== '' && str_contains($afterRun, '0.000 Tc'),
    $afterRun === '' ? 'the paragraph after the SVG was not found' : 'it writes no Tc, so it inherits the heading');

/*
 * A list item holding a sublist numbered from the sublist's own counter, and
 * so did every item after it. The `list-item` instance a nested list creates
 * stays on top of the stack until `applyCounters()` narrows the scope, and
 * that runs AFTER `applyListItemCounter()`: the item after the sublist
 * incremented the sublist's counter, and a marker is made after its own
 * children are built, so the item holding the sublist read it too.
 *
 * Chrome numbers the outer list 1, 2, 3 with the sublist 1, 2 beside it. This
 * engine drew 2, 3, 4. A nested `<ul>` never showed it, because an unordered
 * marker never prints the number it is counting.
 */
$nestedOlDoc = '<style>@page{size:300pt 240pt;margin:12pt}'
    . 'html,body{margin:0;padding:0}'
    . 'body{font-family:Helvetica;font-size:9pt}'
    . 'ol{padding-left:16pt;margin:0}</style>'
    . '<ol>'
    . '<li>OuterOne<ol><li>InnerOne</li><li>InnerTwo</li></ol></li>'
    . '<li>OuterTwo</li>'
    . '<li>OuterThree</li>'
    . '</ol>';

[$nestedOlBytes] = Html::make($nestedOlDoc)->render();

$markersOf = static function (string $bytes): string {
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    $seen = [];

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);

        if ($plain === false) { continue; }

        preg_match_all('/\((\d+)\.\s*\)\s*Tj/', $plain, $numbers);

        foreach ($numbers[1] as $number) { $seen[] = $number; }
    }

    // Sorted, because a marker is painted when its own line is and an item
    // holding a sublist paints after the sublist does. What the numbers are
    // is the subject here; the order they reach the page in is not.
    sort($seen);

    return implode(',', $seen);
};

ok('a list item holding a sublist does not number from the sublist',
    $markersOf($nestedOlBytes) === '1,1,2,2,3',
    sprintf('markers %s, Chrome draws 1 2 3 outside and 1 2 inside', $markersOf($nestedOlBytes) ?: 'none'));

/*
 * Round 91, item 7: the bundled face was not reachable by NAME.
 *
 * `resources/fonts/` has carried DejaVu Sans in all four slots since round 90,
 * and a character the document's own face cannot draw already reaches it. A
 * document that NAMED it did not: `font-family: 'DejaVu Sans'` missed the
 * registry, was recorded as a family that resolved to nothing, and fell back to
 * Helvetica. So the Latin either side of a Cyrillic word came out in a face the
 * page never asked for, in a declaration that named exactly one family. Dompdf
 * bundles this same file and answers to this same name, so it is also the
 * family a document being migrated off Dompdf already carries.
 *
 * Chrome's answers are read off `ZD-font-fallback-percharacter.html` at
 * 400x600 with `build/probe/layout-reference.py`, and the four bundled files
 * are byte-identical to the four DejaVu faces installed on the machine Chrome
 * ran on, so both engines measure the same outlines:
 *
 *   d4  'ab Привет cd' in 'DejaVu Sans'   103.383pt    <- v1 below
 *   d2  'Привет'       in 'DejaVu Sans'    57.363pt    <- v2 below
 *
 * **v1, h1 and the resolve below fail with this round's hook reverted and
 * nothing else in the suite moves with them.** v1 is the one that moved,
 * 98.216 to 103.374; h1 is the control that did not move at all, because a
 * document naming Helvetica keeps Helvetica for the Latin and reaches the
 * bundle only for the Cyrillic, which is round 90's design.
 *
 * **v2 passes on BOTH trees and is here for a different reason.** Every
 * character in it reaches the per-character fallback anyway, so this round
 * cannot move it. What it carries that nothing else does is Chrome's ABSOLUTE
 * width for a named bundled run: `fonts.php` pins the fallback against the
 * named face and would pass with both of them wrong by the same amount.
 */
$byName = static function (): array {
    $tree = layout(
        '<style>html,body{margin:0;padding:0}body{font-size:20px;line-height:1}'
        . 'span{display:inline-block;font-size:20px}div{height:30px}</style>'
        . '<div><span id="h1" style="font-family:Helvetica">ab Привет cd</span></div>'
        . '<div><span id="v1" style="font-family:\'DejaVu Sans\'">ab Привет cd</span></div>'
        . '<div><span id="v2" style="font-family:\'DejaVu Sans\'">Привет</span></div>',
        400.0,
        600.0,
    );

    $out = [];

    // Through the runs as well as the children, because an inline-block hangs
    // off the run that holds it and never appears in `children`.
    $walk = static function (Node $n) use (&$walk, &$out): void {
        if (($n->anchorId ?? '') !== '') {
            $out[$n->anchorId] = round($n->layoutWidth, 3);
        }

        foreach ($n->runs as $run) {
            if ($run->box !== null) { $walk($run->box); }
        }

        foreach ($n->children as $child) { $walk($child); }
    };
    $walk($tree);

    return $out;
};

$byNameWidths = $byName();

ok('a document naming the bundled family gets it, Latin included',
    abs(($byNameWidths['v1'] ?? -1.0) - 103.383) < 0.05,
    sprintf('%.3f, Chrome lays out 103.383 on ZD d4', $byNameWidths['v1'] ?? -1.0));

ok('PASSES ON BOTH TREES: a named bundled run is Chrome\'s own width, absolutely',
    abs(($byNameWidths['v2'] ?? -1.0) - 57.363) < 0.05,
    sprintf('%.3f, Chrome lays out 57.363 on ZD d2', $byNameWidths['v2'] ?? -1.0));

// The control. A document that names Helvetica must keep Helvetica for every
// character Helvetica can draw, or making the bundle reachable by name would
// have quietly redrawn every page that never asked for it.
ok('CONTROL: naming Helvetica still reaches the bundle for the Cyrillic alone',
    abs(($byNameWidths['h1'] ?? -1.0) - 98.216) < 0.001
        && ($byNameWidths['v1'] ?? -1.0) > ($byNameWidths['h1'] ?? -1.0),
    sprintf('h1 %.3f, v1 %.3f', $byNameWidths['h1'] ?? -1.0, $byNameWidths['v1'] ?? -1.0));

ok('the family resolves rather than being recorded as one that named nothing',
    FontRegistry::default()->resolveFamily("'DejaVu Sans'") === 'DejaVu Sans',
    FontRegistry::default()->resolveFamily("'DejaVu Sans'"));

/*
 * Round 91, defect IV: a positioned child painted a page before its own box.
 *
 * `ZB-abspos-split-page.html` is the probe and it was found by rendering a real
 * Blade template, not by fuzzing. A `position: relative` box 90pt tall starts
 * 190pt down a 272pt content box inside a section that begins with a forced
 * break, so the fold cuts it. Its two absolutely positioned children were
 * painted on the page BEFORE it.
 *
 * **The cause is that a box the fold splits reaches no fragment of its own.**
 * `closeDecoration()` paints such a box through a proxy `rect` node, so reading
 * the pages back cannot find where the box started, `$placed` only holds boxes
 * emitted whole, and `placeOutOfFlow()` fell through to the branch that treats
 * the PAGE BOX as the containing block. There the child's own flow coordinate
 * was read as a page-one coordinate, which is a page and 60pt from where it
 * belongs.
 *
 * **The second half is one word painted on two pages.** Once the children land
 * where they belong, one of them straddles the fold, and the line inside it was
 * kept on the first page clipped to a fraction of a point AND continued on the
 * next. The guard that kept it is for a line taller than a whole page; it was
 * testing only that nothing fit where the line stood.
 *
 * Chrome's answers, printed from the probe with `--print-to-pdf` at
 * `@page { size: 300pt 300pt; margin: 14pt }` and read with `streamdump.py`.
 * Chrome's own margin snaps to 14.25pt, so every y below is a quarter of a
 * point from the engine's by construction and the page and the shape are what
 * the case is about:
 *
 *   lead 190pt   page 1 `page one`
 *                page 2 `lead stack topleft bottomright`, a1 top 198.000,
 *                       a2 top 257.250
 *                page 3 `after`
 *   lead 260pt   page 2 `lead stack`, a1 cut to 4.5pt at top 267.750
 *                page 3 `after topleft bottomright`, a1 resumes at top 0.000
 *                       for 12pt and a2 sits whole at top 54.750
 */
function ivDocument(float $lead): string
{
    return '<style>html,body{margin:0;padding:0}*{box-sizing:border-box}'
        . 'body{font-family:Helvetica;font-size:8pt}'
        . '.page{break-before:page}'
        . '.first{height:60pt;background:#f0f5f2}'
        . '.lead{height:' . $lead . 'pt;background:#eef4f1}'
        . '.stack{position:relative;height:90pt;background:#cfe6da}'
        . '.abs{position:absolute;padding:3pt;background:#2f6f4e;color:#fff}'
        . '.a1{left:8pt;top:8pt}.a2{right:8pt;bottom:8pt}'
        . '.after{height:120pt;background:#ffe9c9}</style>'
        . '<div class="first">page one</div>'
        . '<div class="page"><div class="lead">lead</div>'
        . '<div class="stack">stack<div class="abs a1" id="a1">topleft</div>'
        . '<div class="abs a2" id="a2">bottomright</div></div>'
        . '<div class="after">after</div></div>';
}

/** @return array{0:list<string>,1:array<string,list<string>>} words per page, box spots by id */
function ivPaginate(float $lead): array
{
    $tree = layout(ivDocument($lead), 272.0, 272.0);
    $pages = (new Fragmenter(272.0))->fragment($tree);

    $words = [];
    $spots = [];

    foreach ($pages as $pi => $page) {
        $onPage = [];

        foreach ($page as $f) {
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace && trim($item->text ?? '') !== '') {
                        $onPage[] = trim($item->text);
                    }
                }
            }

            if (($f->node->anchorId ?? '') !== '') {
                $spots[$f->node->anchorId][] = sprintf('p%d/%.3f/%.3f', $pi, $f->y, $f->h);
            }
        }

        $words[] = implode(' ', $onPage);
    }

    return [$words, $spots];
}

[$ivWords, $ivSpots] = ivPaginate(190.0);

ok('a positioned child follows the containing block the fold split, rather than leading it',
    $ivWords === ['page one', 'lead stack topleft bottomright', 'after'],
    implode(' || ', $ivWords));

ok('and it lands where Chrome puts it, to within Chrome\'s own margin snapping',
    ($ivSpots['a1'] ?? []) === ['p1/198.000/15.000']
        && ($ivSpots['a2'] ?? []) === ['p1/257.000/15.000'],
    sprintf('a1 %s, a2 %s, Chrome tops 198.000 and 257.250',
        implode(' ', $ivSpots['a1'] ?? ['none']), implode(' ', $ivSpots['a2'] ?? ['none'])));

[$ivTallWords, $ivTallSpots] = ivPaginate(260.0);

ok('the one line of a child the fold cuts is painted once, on the page Chrome puts it on',
    $ivTallWords === ['page one', 'lead stack', 'after topleft bottomright'],
    implode(' || ', $ivTallWords));

// The box itself still spans both pages, which is what Chrome draws: a 4.5pt
// sliver of background at the foot of page 2 and 12pt at the head of page 3.
// It is the TEXT that moves whole, so this is the control that says the cut was
// not simply pushed forward.
ok('CONTROL: the background of that child is still cut across the two pages',
    ($ivTallSpots['a1'] ?? []) === ['p1/268.000/4.000', 'p2/0.000/11.000'],
    implode(' ', $ivTallSpots['a1'] ?? ['none']));

/*
 * Round 92, defect IY: a paragraph beside a float pushed BELOW the float when a
 * page break follows later in the document.
 *
 * `ZF-float-then-break.html` is the probe and the public examples found it, the
 * same way the Duppie documents found IV. A heading, a 130pt box floated right,
 * a paragraph long enough to wrap beside it, then a 600pt block whose only job
 * is to force a further page. Layout puts the paragraph beside the float in
 * every build. The Fragmenter's band walk placed the float, left the cursor at
 * the float's bottom, and the paragraph's band, whose own top is the float's,
 * advanced zero from there: one float height too low. A body that fits its
 * page is emitted whole and never walked, which is why the same section was
 * right in every partial build of the page and wrong in the whole document.
 *
 * Chrome's answers, printed from the probe at `@page { size: 400pt 300pt;
 * margin: 20pt }` and read with `streamdump.py`. Chrome's own margin snaps to
 * 20.25pt, so the engine's y is a quarter of a point from Chrome's by
 * construction:
 *
 *   float box top        63.75 from the page top, 43.50 into the content box
 *   paragraph first line 227.25 from the page bottom, its line box starting at
 *                        the float's own top
 *   pages                3
 *
 * The engine before the fix: paragraph first line 165.90, which is 227.50 less
 * the float's 61.6pt, and 4 pages.
 */
function iyDocument(bool $tall, bool $kept): string
{
    $section = '<h2>Floats</h2>'
        . '<div class="floatbox" id="f">This block floats right. The text beside it wraps around it, '
        . 'with a real exclusion of the line boxes rather than a margin.</div>'
        . '<div class="flowtext" id="t">A floated block takes space away from the line boxes beside it, '
        . 'and the lines stay shorter for as long as the block is next to them. Once the text runs out '
        . 'below the block, the lines return to the full width. That is the difference between a real '
        . 'float and a block with a margin beside it: with a margin, every line stays short, including '
        . 'the ones below the block. This paragraph is made long enough to show the difference, so the '
        . 'first lines sit beside the block and the last lines run on below it across the full column '
        . 'width of this page.</div>';

    return '<style>*{box-sizing:border-box}html,body{margin:0;padding:0}'
        . 'body{font-family:Helvetica;font-size:9pt;line-height:1.5}'
        . 'h2{font-size:8pt;letter-spacing:1.8pt;text-transform:uppercase;color:#2f6f4e;'
        . 'border-bottom:0.5pt solid #dbe6e0;padding-bottom:4pt;margin:18pt 0 9pt}'
        . '.floatbox{float:right;width:130pt;background:#f5f9f7;padding:8pt;margin:0 0 6pt 10pt;font-size:7.6pt}'
        . '.flowtext{font-size:8pt;text-align:justify;hyphens:auto}'
        . '.keep{break-inside:avoid}</style>'
        . ($kept ? '<div class="keep">' . $section . '</div>' : $section)
        . ($tall ? '<div style="height:600pt;background:#eee">tall block that forces a second page</div>' : '');
}

/** @return array{0:int,1:array<string,list<string>>} pages, box spots by id */
function iyPaginate(string $html): array
{
    $tree  = layout($html, 360.0, 260.0);
    $pages = (new Fragmenter(260.0))->fragment($tree);
    $spots = [];

    foreach ($pages as $pi => $page) {
        foreach ($page as $f) {
            if (($f->node->anchorId ?? '') !== '') {
                $spots[$f->node->anchorId][] = sprintf('p%d/%.3f/%.3f', $pi, $f->y, $f->h);
            }
        }
    }

    return [count($pages), $spots];
}

[$iyPages, $iySpots] = iyPaginate(iyDocument(true, false));

ok('a paragraph beside a float starts where the float starts when a page break follows later',
    ($iySpots['f'] ?? []) === ['p0/43.500/61.600'] && ($iySpots['t'] ?? []) === ['p0/43.500/96.000'],
    sprintf('float %s, paragraph %s, Chrome puts both at 43.500',
        implode(' ', $iySpots['f'] ?? ['none']), implode(' ', $iySpots['t'] ?? ['none'])));

ok('and the document has Chrome\'s three pages rather than four',
    $iyPages === 3,
    sprintf('%d pages', $iyPages));

[$iyNoTallPages, $iyNoTallSpots] = iyPaginate(iyDocument(false, false));

ok('CONTROL, PASSES ON BOTH TREES: with no further page the body is emitted whole and the paragraph sits beside the float',
    $iyNoTallPages === 1 && ($iyNoTallSpots['t'] ?? []) === ['p0/43.500/96.000'],
    sprintf('%d pages, paragraph %s', $iyNoTallPages, implode(' ', $iyNoTallSpots['t'] ?? ['none'])));

[$iyKeptPages, $iyKeptSpots] = iyPaginate(iyDocument(true, true));

ok('CONTROL, PASSES ON BOTH TREES: the break-inside: avoid workaround the showcase carried gives the same answer',
    $iyKeptPages === 3 && ($iyKeptSpots['t'] ?? []) === ['p0/43.500/96.000'],
    sprintf('%d pages, paragraph %s', $iyKeptPages, implode(' ', $iyKeptSpots['t'] ?? ['none'])));

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
