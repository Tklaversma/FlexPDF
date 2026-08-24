<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, Fragmenter, Node, Html, FontRegistry};
use FlexPDF\Engine\Support\Limits;

/**
 * Generates random documents and asserts invariants that must hold for any
 * input. The point is to reach code paths the hand-written suites never
 * touch: the class of bug that renders something plausible instead of
 * failing loudly.
 */

require_once __DIR__ . '/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

$seed = (int) ($argv[1] ?? 20260727);
$iterations = (int) ($argv[2] ?? 400);
mt_srand($seed);

$violations = [];
$checked = 0;

function pick(array $options): string
{
    return $options[mt_rand(0, count($options) - 1)];
}

function maybe(string $decl, int $percent = 40): string
{
    return mt_rand(1, 100) <= $percent ? $decl : '';
}

function randomStyle(): string
{
    $parts = [
        maybe('display: ' . pick(['block', 'flex', 'inline', 'none', 'table', 'grid', 'grid']), 25),
        maybe('grid-template-columns: ' . pick([
            'repeat(3, 1fr)', '1fr 2fr', 'minmax(20pt, 1fr) auto', 'repeat(2, minmax(0, 1fr))',
            '100pt 100pt 100pt', 'max-content min-content', 'none', '0 1fr',
            'repeat(0, 1fr)', 'repeat(200, 1pt)', '1fr 1fr 1fr 1fr 1fr 1fr',
        ]), 20),
        maybe('grid-template-rows: ' . pick([
            'repeat(2, 40pt)', 'auto auto', 'minmax(10pt, auto)', '1fr', 'none',
        ]), 12),
        maybe('grid-auto-flow: ' . pick(['row', 'column']), 10),
        maybe('grid-auto-rows: ' . pick(['30pt', 'auto', 'minmax(10pt, 1fr)']), 8),
        maybe('grid-column: ' . pick(['1 / 3', 'span 2', '2', 'span 5', '3 / 2', '-1', '0']), 12),
        maybe('grid-row: ' . pick(['1 / 3', 'span 2', '2', 'span 4']), 10),
        maybe('justify-items: ' . pick(['stretch', 'center', 'start', 'end']), 8),
        maybe('justify-self: ' . pick(['stretch', 'center', 'end']), 6),
        maybe('align-content: ' . pick(['start', 'center', 'space-between', 'space-evenly']), 6),
        maybe('overflow: hidden', 12),
        maybe('column-count: ' . pick(['2', '3', '1', '0']), 8),
        maybe('column-width: ' . pick(['80pt', '0', '5000pt']), 6),
        maybe('hyphens: ' . pick(['auto', 'manual', 'none']), 10),
        maybe('object-fit: ' . pick(['cover', 'contain', 'none', 'fill']), 6),
        maybe('mix-blend-mode: ' . pick(['multiply', 'screen', 'nonsense']), 5),
        maybe('text-shadow: ' . pick(['1pt 1pt 0 #333', '0 0 0 red', '-2pt -2pt']), 5),
        maybe('grid-template-areas: ' . pick(['"a a" "b c"', '"a" "a"', '"x . y"']), 6),
        // Round 22 folded in the widenings six saved copies of this file have
        // been carrying since round 10: the two reverse directions, the
        // shorthand, `order`, baseline alignment and `box-sizing`. Every one of
        // them was a property this generator named zero times, which is what
        // made its census say nothing about the rows that landed them.
        maybe('flex-direction: ' . pick(['row', 'column', 'row-reverse', 'column-reverse'])),
        maybe('flex-flow: ' . pick(['row wrap', 'column-reverse nowrap', 'wrap', 'column', 'row-reverse wrap']), 10),
        maybe('order: ' . pick(['1', '-1', '0', '3', '-2', '99']), 15),
        maybe('flex-wrap: ' . pick(['nowrap', 'wrap'])),
        maybe('justify-content: ' . pick(['flex-start', 'center', 'space-between', 'space-around', 'space-evenly', 'flex-end'])),
        maybe('align-items: ' . pick(['stretch', 'center', 'flex-start', 'flex-end', 'baseline'])),
        maybe('align-self: ' . pick(['baseline', 'center', 'flex-end', 'stretch', 'auto']), 12),
        maybe('flex: ' . pick(['1', '0 0 auto', '2 1 0', 'none', '0.5'])),
        maybe('box-sizing: ' . pick(['border-box', 'content-box'])),
        maybe('width: ' . pick(['0', '1pt', '50%', '100%', '200pt', 'auto', 'calc(50% - 10pt)'])),
        maybe('height: ' . pick(['0', '1pt', '40pt', 'auto', '120%'])),
        maybe('min-width: ' . pick(['0', '30pt', '200pt'])),
        maybe('max-width: ' . pick(['0', '20pt', '100%'])),
        maybe('margin: ' . pick(['0', '4pt', '-6pt', '10pt 0', '-3pt 2pt 8pt -1pt'])),
        maybe('padding: ' . pick(['0', '3pt', '12pt 4pt'])),
        maybe('float: ' . pick(['left', 'right', 'none']), 20),
        maybe('clear: ' . pick(['left', 'right', 'both']), 10),
        maybe('position: ' . pick(['relative', 'absolute']), 15),
        maybe('top: ' . pick(['0', '10pt', '-20pt', '50%']), 15),
        maybe('left: ' . pick(['0', '15pt', '-5pt']), 15),
        maybe('right: ' . pick(['0', '8pt']), 10),
        maybe('transform: ' . pick(['rotate(15deg)', 'scale(0)', 'scale(2)', 'translate(5pt,-5pt)', 'rotate(0.5turn)', 'matrix(1,0,0,1,5,-5)', 'matrix(0.966,0.259,-0.259,0.966,0,0)', 'matrix(1,0,0.36,1,0,0)', 'matrix(0,0,0,0,0,0)']), 12),
        maybe('opacity: ' . pick(['0', '0.5', '1']), 10),
        maybe('font-size: ' . pick(['0', '1px', '9px', '40px', '2em', '0.1em'])),
        maybe('line-height: ' . pick(['0', '0.5', '1.4', '3'])),
        maybe('gap: ' . pick(['0', '6pt', '40pt'])),
        maybe('border: ' . pick(['0.5pt solid #333', '4pt solid red'])),
        maybe('text-align: ' . pick(['left', 'right', 'center', 'justify'])),
        maybe('direction: rtl', 8),
        maybe('break-inside: avoid', 10),
        // Round 75 folded in the fragmentation family, which this generator
        // named ZERO times: no `break-before`, no `break-after` and no `page`
        // property at all, on a `grep -c` rather than an estimate. Round 74
        // landed three rows in that family and every corpus in the repository
        // read 0 of 8,000 because none of them could write the declaration.
        // Same shape as round 22's widening and round 71's finishing of it.
        maybe('break-before: ' . pick(['page', 'page', 'auto', 'avoid', 'left', 'right']), 8),
        maybe('break-after: ' . pick(['page', 'auto', 'avoid']), 5),
        maybe('page: ' . pick(['cover', 'plain']), 5),
        maybe('vertical-align: ' . pick(['top', 'middle', 'bottom']), 10),
        maybe('width: calc(' . pick(['50% - 10pt', '100% + 40pt', '3 * 20pt', '0pt - 50pt']) . ')', 8),
        maybe('padding: var(--gap, ' . pick(['4pt', '20pt']) . ')', 8),
        maybe('border-collapse: ' . pick(['collapse', 'separate']), 8),
        maybe('border-spacing: ' . pick(['0', '4pt', '30pt']), 6),
        maybe('overflow: hidden', 5),
        maybe('white-space: nowrap', 5),
        maybe('font-weight: ' . pick(['bold', 'normal', '700']), 10),
        maybe('font-style: italic', 8),
        maybe('background: ' . pick(['#eee', 'rgba(0,0,0,0.2)', 'transparent', 'notacolour']), 12),
        maybe('transform-origin: ' . pick(['left top', '0 0', '100% 100%']), 5),
        maybe(pick([
            'width: ;', 'height: !important', 'margin: 4pt 4pt 4pt 4pt 4pt',
            'padding: -;', 'flex: a b c', 'color: #12345', 'font-size: 1e9px',
            'width: 99999999pt', 'line-height: -2', 'gap: -10pt',
            'transform: rotate()', 'transform: rotate(NaNdeg)', 'transform: matrix(1,2,3)', 'opacity: 5',
            'flex-basis: calc()', 'width: calc(1pt / 0)',
        ]), 10),
    ];
    return implode('; ', array_filter($parts));
}

function randomText(): string
{
    $pools = [
        'The quick brown fox jumps over the lazy dog',
        'Příliš žluťoučký kůň úpěl ďábelské ódy',
        'مرحبا بالعالم هذا نص عربي',
        'שלום עולם זהו טקסט עברי',
        'Съешь же ещё этих мягких булок',
        'a',
        '',
        'supercalifragilisticexpialidociousandthensome',
        '   ',
        '2026 €12.50 — «quoted» (parens) [brackets]',
    ];
    $n = mt_rand(1, 4);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $pools[mt_rand(0, count($pools) - 1)];
    }
    return implode(' ', $out);
}

/** Replaced elements: raster images and vector graphics. */
function randomAsset(): string
{
    $style = randomStyle();
    return match (mt_rand(1, 4)) {
        1 => sprintf('<img src="/tmp/fz.jpg" style="%s">', $style),
        2 => sprintf('<img src="/tmp/fz.png" style="%s">', $style),
        3 => sprintf('<img src="/tmp/fz.svg" style="%s">', $style),
        default => sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" style="%s">'
            . '<circle cx="10" cy="10" r="%d" fill="#2a6"/>'
            . '<path d="M0 0 L20 20 Q30 5 40 20 T60 20 Z" fill="none" stroke="#333"/></svg>',
            mt_rand(1, 60), mt_rand(1, 60), $style, mt_rand(0, 20)
        ),
    };
}

function randomNode(int $depth): string
{
    if ($depth <= 0 || mt_rand(1, 100) <= 25) {
        return htmlspecialchars(randomText());
    }

    if (mt_rand(1, 100) <= 10) {
        return randomAsset();
    }

    $style = randomStyle();
    $tag = pick(['div', 'div', 'p', 'span', 'section', 'ul', 'li', 'b', 'i']);

    if (mt_rand(1, 100) <= 12) {
        return randomTable($depth);
    }

    $children = '';
    $count = mt_rand(0, 3);
    for ($i = 0; $i < $count; $i++) {
        $children .= randomNode($depth - 1);
    }
    if ($children === '' && mt_rand(1, 100) <= 60) {
        $children = htmlspecialchars(randomText());
    }

    return sprintf('<%s style="%s">%s</%s>', $tag, $style, $children, $tag);
}

function randomTable(int $depth): string
{
    $cols = mt_rand(1, 4);
    $rows = mt_rand(1, 5);
    $html = '<table style="' . randomStyle() . '">';
    if (mt_rand(1, 100) <= 30) {
        $html .= '<colgroup>';
        for ($c = 0; $c < $cols; $c++) {
            $html .= sprintf('<col span="%d" style="%s">',
                mt_rand(1, 2),
                mt_rand(1, 100) <= 60 ? 'width: ' . pick(['30pt', '50%', '200pt', '0']) : '');
        }
        $html .= '</colgroup>';
    }
    $html .= '<thead><tr>';
    for ($c = 0; $c < $cols; $c++) {
        $html .= '<th>' . htmlspecialchars(randomText()) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    for ($r = 0; $r < $rows; $r++) {
        $html .= '<tr>';
        $c = 0;
        while ($c < $cols) {
            $span = mt_rand(1, 100) <= 20 ? min(mt_rand(2, 3), $cols - $c) : 1;
            $rowspan = mt_rand(1, 100) <= 15 ? mt_rand(2, 3) : 1;
            $html .= sprintf(
                '<td colspan="%d" rowspan="%d" style="%s">%s</td>',
                $span, $rowspan, randomStyle(),
                $depth > 1 && mt_rand(1, 100) <= 20
                    ? randomNode($depth - 2)
                    : htmlspecialchars(randomText())
            );
            $c += $span;
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

// ---------------------------------------------------------------------
function finite(float $v): bool
{
    return is_finite($v);
}

/** @return string[] */
function checkTree(Node $n, string $path = 'root'): array
{
    $problems = [];

    foreach (['x' => $n->x, 'y' => $n->y, 'w' => $n->layoutWidth, 'h' => $n->layoutHeight] as $k => $v) {
        if (!finite($v)) {
            $problems[] = "$path: $k is not finite ($v)";
        }
    }
    if ($n->layoutWidth < -0.01) {
        $problems[] = sprintf('%s: negative width %.3f', $path, $n->layoutWidth);
    }
    if ($n->layoutHeight < -0.01) {
        $problems[] = sprintf('%s: negative height %.3f', $path, $n->layoutHeight);
    }

    foreach ($n->lineBoxes as $i => $lb) {
        if (!finite($lb->height) || !finite($lb->baseline)) {
            $problems[] = "$path: line box $i has a non-finite metric";
        }
        if ($lb->height < -0.01) {
            $problems[] = sprintf('%s: line box %d has negative height %.3f', $path, $i, $lb->height);
        }
        foreach ($lb->items as $j => $item) {
            if (!finite($item->x) || !finite($item->width)) {
                $problems[] = "$path: line item $i.$j has a non-finite position";
            }
        }
    }

    foreach ($n->children as $i => $child) {
        $problems = array_merge($problems, checkTree($child, "$path/$i"));
    }
    return $problems;
}

function countGlyphs(Node $n): int
{
    $total = 0;
    foreach ($n->lineBoxes as $lb) {
        foreach ($lb->items as $item) {
            if (!$item->isSpace) { $total += 1; }
        }
    }
    foreach ($n->children as $c) {
        $total += countGlyphs($c);
    }
    return $total;
}

/**
 * Every line taller than one page, summed.
 *
 * A line taller than the page is legitimately spread over several, and the box
 * it sits on does not carry it: `height: 0` or `overflow: hidden` around it
 * leaves the tree's own height saying nothing about it. That is half of what
 * defect CT turned out to be, measured in round 73 on all four documents the
 * committed sweeps had: `font-size: 1e9px` clamped to `max_font_size` puts a
 * 6,000pt line on a 300pt page, and Chrome paginates one of those over MORE
 * pages than this engine does, 14 against 6 on `VQ-fontsize-clamp.html`.
 */
function pageDemand(Node $n, float $pageH): float
{
    $total = 0.0;

    foreach ($n->lineBoxes as $lb) {
        if ($lb->height > $pageH) {
            $total += $lb->height;
        }
    }

    foreach ($n->children as $c) {
        $total += pageDemand($c, $pageH);
    }

    return $total;
}

/**
 * The deepest box bottom in flow space, which is the other half.
 *
 * `widebs seed 99 #134` has no line taller than a page at all and still asks
 * for 671: an in-flow block of 200,000pt, the `max_length` ceiling, inside an
 * ancestor whose own height is 40pt. The tree's height is 359pt there and the
 * fragmenter is right to paginate what the layout placed.
 */
function pageBottom(Node $n, float $top = 0.0): float
{
    $here   = $top + $n->y;
    $bottom = $here + $n->layoutHeight;

    foreach ($n->children as $c) {
        $bottom = max($bottom, pageBottom($c, $here));
    }

    return $bottom;
}

echo "\nFuzzing layout invariants (seed $seed, $iterations documents)\n\n";

FontRegistry::reset();
FontRegistry::default()->registerTrueType('DejaVu', DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf');

$pageW = 400.0;
$pageH = 300.0;

for ($iter = 0; $iter < $iterations; $iter++) {
    $body = '';
    $blocks = mt_rand(1, 4);
    for ($b = 0; $b < $blocks; $b++) {
        $body .= randomNode(mt_rand(2, 5));
    }
    $sheet = '<style>'
        . (mt_rand(1, 100) <= 20
            ? '@page{size:' . pick(['A4', 'letter', '200pt 100pt']) . ';margin:' . pick(['0', '20pt']) . '}'
            : '')
        . (mt_rand(1, 100) <= 12 ? '@media screen{.x{display:none}}' : '')
        . (mt_rand(1, 100) <= 15
            ? '@page cover{size:' . pick(['300pt 200pt', '540pt 270pt', 'auto'])
                . ';margin:' . pick(['0', '20pt'])
                . (mt_rand(1, 100) <= 40 ? ';@top-left{content:"TL"}' : '') . '}'
            : '')
        . (mt_rand(1, 100) <= 10 ? '} .broken { color: ; ' : '')
        . ':root{--gap:' . pick(['2pt', '10pt', '0']) . ';--loop:var(--loop)}'
        . 'body{font-family:DejaVu;font-size:' . pick(['10px', '6px', '18px']) . '}'
        . (mt_rand(1, 100) <= 20 ? 'td,th{border:0.5pt solid #999}' : '')
        . (mt_rand(1, 100) <= 15 ? 'p{margin:' . pick(['0', '8pt 0', '-4pt 0']) . '}' : '')
        . '</style>';
    $dirAttr = mt_rand(1, 100) <= 12 ? ' dir="rtl"' : '';
    $html = $sheet . '<div' . $dirAttr . '>' . $body . '</div>';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    try {
        $resolver = new StyleResolver();

        /*
         * ROUND 91: the generator's own `<style>` block, which nothing applied
         * until this round.
         *
         * Without this every document in the corpus laid out in base-14
         * Helvetica at 12px whatever its own sheet said, with only inline
         * `style=""` attributes in effect. Read straight off `cdoc-1-0.html`,
         * whose sheet says `body{font-family:DejaVu;font-size:6px}`, the tree
         * came back `fontFamily='Helvetica' fontSize=12.0`. It was still a
         * deterministic 8,000-document regression detector and it caught round
         * 90's change on 5,346 of them; it was not a measurement of the family
         * or the size the generator declares. `Html::make()`, which the paint
         * rows go through, has always applied it, and the two row kinds
         * disagreeing by a factor of seven is what found this.
         *
         * EVERY BASELINE TAKEN BEFORE ROUND 91 IS INCOMPARABLE WITH ONE TAKEN
         * AFTER IT.
         */
        foreach ($dom->getElementsByTagName('style') as $styleElement) {
            $resolver->addStylesheet($styleElement->textContent);
        }

        $tree = (new HtmlBuilder($resolver))->build($dom);
        $tree->width = $pageW;
        (new FlexLayout())->layout($tree, $pageW, $pageH);
    } catch (\Throwable $e) {
        $violations[] = sprintf('#%d layout threw %s: %s', $iter, get_class($e), $e->getMessage());
        continue;
    }

    $checked++;
    foreach (checkTree($tree) as $problem) {
        $violations[] = "#$iter $problem";
        if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
            file_put_contents('/tmp/fuzz_fail.html', $html);
        }
    }

    $before = countGlyphs($tree);

    try {
        $fragmenter = new Fragmenter($pageH);
        $pages      = $fragmenter->fragment($tree);
    } catch (\Throwable $e) {
        $violations[] = sprintf('#%d fragmentation threw %s: %s', $iter, get_class($e), $e->getMessage());
        continue;
    }

    // `clampOverflow()` has three ways to create a page without going through
    // `newPage()`, and each has to honour the ceiling itself: one of them stops
    // at it rather than throwing, deliberately, because the pre-existing
    // behaviour there was to clip. A sweep that only watches for the exception
    // cannot see a path that walks past the ceiling quietly, and reading
    // `newPage()` alone is what missed the first two.
    $maxPages = new Limits()->maxPages;

    if (count($pages) > $maxPages) {
        $violations[] = sprintf(
            '#%d produced %d pages past the %d-page ceiling', $iter, count($pages), $maxPages
        );
        if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
            file_put_contents('/tmp/fuzz_fail.html', $html);
        }
    }

    // Content that occupies N page-heights cannot fit on far fewer pages;
    // if it appears to, a box's own height was dropped during splitting.
    // A margin the fold truncated is flow height that no page carries: CSS
    // Fragmentation 3.5 drops what is left of it below the break and Chrome
    // does the same, at 400pt (`J2-margin-huge.html`), at 1,000pt
    // (`RJ-margin-1000.html`) and at 2,100pt (`WB-margin-2100.html`). Reading
    // the raw tree height instead reported `widepre 99 #66` as 4,206pt of flow
    // on 3 pages for eight rounds, where 3,606pt of it is two truncated
    // margins and the content really is 600pt. That was defect HW, and it is
    // the same shape as CT: an expectation read off a height the content does
    // not live in. Ask the fragmenter rather than working it out again here.
    $flowHeight = $tree->layoutHeight;
    $carried = max(0.0, $flowHeight - $fragmenter->truncatedGaps());
    $needed = (int) floor($carried / $pageH);
    if ($needed > 2 && count($pages) * 3 < $needed) {
        $violations[] = sprintf(
            '#%d %.0fpt of flow collapsed onto %d pages (needs about %d)',
            $iter, $carried, count($pages), $needed
        );
        if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
            file_put_contents('/tmp/fuzz_fail.html', $html);
        }
    }

    // Nested `em` sizes compound, so a document can legitimately be thousands
    // of pages tall. Only flag pagination that outruns the content itself.
    // A line taller than the page is content the tree's own height does not
    // carry, and it is what defect CT turned out to be. {@see pageDemand}.
    $demanded = (int) floor(
        (max($flowHeight, pageBottom($tree)) + pageDemand($tree, $pageH)) / $pageH
    );

    if (count($pages) > 400 && $demanded > 0 && count($pages) > 4 * max($demanded, 1)) {
        $violations[] = sprintf(
            '#%d produced %d pages for %.0fpt of flow (about %d expected)',
            $iter, count($pages), $flowHeight, $demanded
        );
        if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
            file_put_contents('/tmp/fuzz_fail.html', $html);
        }
        continue;
    }

    $after = 0;
    foreach ($pages as $pi => $page) {
        foreach ($page as $f) {
            if (!finite($f->x) || !finite($f->y) || !finite($f->w) || !finite($f->h)) {
                $violations[] = "#$iter fragment on page $pi has a non-finite rect";
                continue;
            }
            if ($f->y + $f->h > $pageH + 0.5) {
                $violations[] = sprintf(
                    '#%d fragment overflows page %d by %.2fpt', $iter, $pi, $f->y + $f->h - $pageH
                );
                if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
                    file_put_contents('/tmp/fuzz_fail.html', $html);
                }
            }
            foreach ($f->lines as $lb) {
                foreach ($lb->items as $item) {
                    if (!$item->isSpace) { $after++; }
                }
            }
        }
    }

    // Every tenth document goes all the way to a PDF and back, so the
    // writer and font subsetter are fuzzed too, not just layout.
    if ($iter % 10 === 0) {
        $out = sys_get_temp_dir() . '/fuzz_out.pdf';
        try {
            @unlink($out);
            Html::make($html)->page($pageW, $pageH)->margin(10.0)->save($out);
        } catch (\Throwable $e) {
            $violations[] = sprintf('#%d PDF write threw %s: %s', $iter, get_class($e), $e->getMessage());
        }
        if (is_file($out)) {
            // Read the PDF back through a fresh interpreter. The probe is a
            // subprocess and can fail for reasons that are nothing to do with
            // the PDF: several sweeps starting at once make the first pypdf
            // imports contend, and one failure per hundred is enough to report
            // a whole seed as broken. A genuinely unreadable PDF fails every
            // time, so the answer only counts once it has been reproduced.
            $read = static function () use ($out): string {
                return trim((string) shell_exec(
                    'python3 -c "from pypdf import PdfReader;'
                    . " r=PdfReader('$out');"
                    . ' print(len(r.pages));'
                    . ' [p.extract_text() for p in r.pages]" 2>&1'
                ));
            };

            $probe = $read();

            if (!ctype_digit($probe)) {
                $probe = $read();
            }

            if (!ctype_digit($probe)) {
                $violations[] = sprintf('#%d produced an unreadable PDF: %s', $iter, substr($probe, 0, 200));
            }
        }
    }

    // Repeating headers legitimately duplicate content, so only a shortfall
    // counts as loss.
    if ($after < $before) {
        $violations[] = sprintf('#%d lost %d of %d glyph runs in fragmentation', $iter, $before - $after, $before);
        if (getenv('FUZZ_DUMP') && !file_exists('/tmp/fuzz_fail.html')) {
            file_put_contents('/tmp/fuzz_fail.html', $html);
        }
    }
}

printf("  documents laid out : %d\n", $checked);
printf("  invariant breaches : %d\n\n", count($violations));

if ($violations !== []) {
    $shown = array_slice($violations, 0, 25);
    foreach ($shown as $v) {
        printf("  \033[31m%s\033[0m\n", $v);
    }
    if (count($violations) > count($shown)) {
        printf("  ... and %d more\n", count($violations) - count($shown));
    }
    echo "\n";
    exit(1);
}

echo "  \033[32mno invariant breaches\033[0m\n\n";
