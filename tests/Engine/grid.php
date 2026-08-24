<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, Fragmenter, Node, Html};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

/** @return array{0:Node,1:Node[]} the grid container and its items */
function grid(string $css, string $body, float $w = 400.0, float $h = 600.0): array
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><style>' . $css . '</style><div class="g">' . $body . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $r = new StyleResolver();
    $r->addStylesheet(SUITE_BODY_RESET);
    $r->addStylesheet($css);
    $t = (new HtmlBuilder($r))->build($d);
    $t->width = $w;
    (new FlexLayout())->layout($t, $w, $h);

    // Defect DG: laid out from the real root, asserted against the body.
    $grid = bodyOf($t)->children[0];

    return [$grid, $grid->children];
}

/** @param Node[] $items @return float[] */
function widths(array $items): array
{
    return array_map(static fn(Node $n): float => round($n->layoutWidth, 2), $items);
}
function xs(array $items): array
{
    return array_map(static fn(Node $n): float => round($n->x, 2), $items);
}
function ys(array $items): array
{
    return array_map(static fn(Node $n): float => round($n->y, 2), $items);
}

echo "\nCSS Grid layout\n\n";

// 1. Track lists ------------------------------------------------------
$sr = new StyleResolver();
ok('repeat() expands',
    count($sr->trackList('repeat(4, 1fr)', 12.0, 12.0)) === 4);
ok('repeat() with a multi-track pattern expands in order',
    count($sr->trackList('repeat(2, 1fr 60pt)', 12.0, 12.0)) === 4);
$mm = $sr->trackList('minmax(50pt, 1fr)', 12.0, 12.0)[0];
ok('minmax() sets separate min and max sizing functions',
    $mm['minType'] === 'fixed' && abs($mm['minValue'] - 50.0) < 0.01 && $mm['maxType'] === 'fr');
ok('bracketed line names are accepted and ignored',
    count($sr->trackList('[start] 1fr [mid] 1fr [end]', 12.0, 12.0)) === 2);

// 2. fr distribution --------------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:1fr 2fr 100pt;gap:10pt;width:400pt}',
    '<div>a</div><div>b</div><div>c</div>'
);
// 400 - 20 gaps - 100 fixed = 280 over 3fr => 93.33 / 186.67
ok('fr tracks take an exact share of the leftover space',
    abs($items[0]->layoutWidth - 93.33) < 0.05 && abs($items[1]->layoutWidth - 186.67) < 0.05
    && abs($items[2]->layoutWidth - 100.0) < 0.01,
    implode(' | ', widths($items)));

[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(4,1fr);width:400pt}',
    '<div>a</div><div>b</div><div>c</div><div>d</div>');
ok('equal fr tracks divide the container evenly',
    widths($items) === [100.0, 100.0, 100.0, 100.0], implode(' | ', widths($items)));

// 3. minmax -----------------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:minmax(150pt,1fr) 1fr;width:200pt}',
    '<div>a</div><div>b</div>');
ok('minmax() floors a track at its minimum',
    $items[0]->layoutWidth >= 149.9, sprintf('%.1f', $items[0]->layoutWidth));

[$g, $items] = grid('.g{display:grid;grid-template-columns:minmax(0pt,80pt) 1fr;width:400pt}',
    '<div>a</div><div>b</div>');
ok('minmax() caps a track at its maximum',
    abs($items[0]->layoutWidth - 80.0) < 0.01 && abs($items[1]->layoutWidth - 320.0) < 0.01,
    implode(' | ', widths($items)));

// 4. Explicit placement -----------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:1fr 1fr;width:400pt}.s{grid-column:1 / 3}',
    '<div class="s">span</div><div>b</div><div>c</div>');
ok('grid-column: 1 / 3 covers both tracks',
    abs($items[0]->layoutWidth - 400.0) < 0.01 && ys($items) === [0.0, ys($items)[1], ys($items)[2]]
    && ys($items)[1] > 0.0,
    'first item ' . $items[0]->layoutWidth . 'pt, rest on row 2');

[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(3,1fr);width:300pt}.b{grid-column:3}',
    '<div class="b">third</div><div>a</div>');
ok('an explicit column pins an item regardless of source order',
    abs($items[0]->x - 200.0) < 0.01, sprintf('x=%.1f', $items[0]->x));

// 5. Spans ------------------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(3,1fr);width:300pt}.s{grid-column:span 2}',
    '<div>a</div><div class="s">wide</div><div>c</div>');
ok('grid-column: span 2 widens an auto-placed item',
    abs($items[1]->layoutWidth - 200.0) < 0.01, implode(' | ', widths($items)));
ok('an item that will not fit the row wraps to the next',
    $items[2]->y > $items[0]->y, sprintf('y=%s', implode(',', ys($items))));

[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(2,1fr);width:200pt}.t{grid-row:span 2}',
    '<div class="t">tall</div><div>b</div><div>c</div>');
ok('grid-row: span 2 covers two rows',
    $items[0]->layoutHeight >= $items[1]->layoutHeight * 2 - 0.5,
    sprintf('%.1f vs %.1f', $items[0]->layoutHeight, $items[1]->layoutHeight));

// 6. Auto flow --------------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(2,1fr);width:200pt}',
    '<div>a</div><div>b</div><div>c</div>');
ok('auto placement fills rows first by default',
    ys($items)[0] === ys($items)[1] && ys($items)[2] > ys($items)[0]);

[$g, $items] = grid(
    '.g{display:grid;grid-template-rows:repeat(2,40pt);grid-auto-flow:column;'
    . 'grid-template-columns:repeat(2,1fr);width:200pt}',
    '<div>a</div><div>b</div><div>c</div>'
);
ok('grid-auto-flow: column fills columns first',
    xs($items)[0] === xs($items)[1] && xs($items)[2] > xs($items)[0],
    'x=' . implode(',', xs($items)));

// 7. Implicit tracks --------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(2,1fr);grid-auto-rows:50pt;width:200pt}',
    '<div>a</div><div>b</div><div>c</div><div>d</div>');
ok('grid-auto-rows sizes rows the template does not declare',
    abs($items[2]->y - 50.0) < 0.01, sprintf('row 2 at y=%.1f', $items[2]->y));

// 8. Gaps -------------------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(2,1fr);row-gap:30pt;column-gap:10pt;width:210pt}',
    '<div>a</div><div>b</div><div>c</div>');
ok('row-gap and column-gap apply independently',
    abs($items[1]->x - 110.0) < 0.01 && $items[2]->y >= 30.0,
    sprintf('col gap %.0f, row y %.1f', $items[1]->x - 100.0, $items[2]->y));

// 9. Item alignment ---------------------------------------------------
foreach ([['stretch', 100.0, 0.0], ['center', 10.0, 45.0], ['end', 10.0, 90.0], ['start', 10.0, 0.0]] as [$mode, $w, $x]) {
    [$g, $items] = grid(
        ".g{display:grid;grid-template-columns:100pt;grid-template-rows:60pt;width:300pt;justify-items:$mode}",
        '<div>ab</div>'
    );
    ok("justify-items: $mode",
        abs($items[0]->layoutWidth - $w) < 0.5 && abs($items[0]->x - $x) < 0.5,
        sprintf('x=%.1f w=%.1f', $items[0]->x, $items[0]->layoutWidth));
}

[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:100pt;grid-template-rows:60pt;width:300pt;align-items:center}',
    '<div>ab</div>'
);
ok('align-items: center centres within the row',
    abs($items[0]->y - (60.0 - $items[0]->layoutHeight) / 2) < 0.5,
    sprintf('y=%.1f', $items[0]->y));

[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:100pt;grid-template-rows:60pt;width:300pt;justify-items:start}'
    . '.o{justify-self:end}',
    '<div class="o">ab</div>'
);
ok('justify-self overrides justify-items',
    $items[0]->x > 80.0, sprintf('x=%.1f', $items[0]->x));

// 10. Content distribution --------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:50pt 50pt;width:300pt;justify-content:space-between}',
    '<div>a</div><div>b</div>'
);
ok('justify-content distributes the tracks themselves',
    abs($items[0]->x) < 0.5 && abs($items[1]->x - 250.0) < 0.5,
    'x=' . implode(',', xs($items)));

[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:50pt;width:300pt;justify-content:center}',
    '<div>a</div>'
);
ok('justify-content: center centres a short track list',
    abs($items[0]->x - 125.0) < 0.5, sprintf('x=%.1f', $items[0]->x));

// 11. Content sizing --------------------------------------------------
[$g, $items] = grid('.g{display:grid;grid-template-columns:max-content auto;width:400pt}',
    '<div>short</div><div>the rest of the space</div>');
ok('max-content sizes a track to its widest item',
    $items[0]->layoutWidth > 5.0 && $items[0]->layoutWidth < 60.0,
    sprintf('%.1f', $items[0]->layoutWidth));

[$g, $items] = grid('.g{display:grid;grid-template-columns:repeat(2,1fr);width:200pt}',
    '<div>a</div><div>' . str_repeat('word ', 30) . '</div>');
ok('rows grow to their tallest item',
    abs($g->layoutHeight - $items[1]->layoutHeight) < 1.0,
    sprintf('grid %.1f, tall item %.1f', $g->layoutHeight, $items[1]->layoutHeight));

// 12. Nesting ---------------------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:1fr 1fr;width:400pt}'
    . '.f{display:flex;gap:10pt}.f>div{flex:1}',
    '<div class="f"><div>x</div><div>y</div></div><div>b</div>'
);
$flex = $items[0];
ok('a flex container nests inside a grid item',
    abs($flex->layoutWidth - 200.0) < 0.5
    && abs($flex->children[0]->layoutWidth - 95.0) < 0.5,
    sprintf('flex %.1f, child %.1f', $flex->layoutWidth, $flex->children[0]->layoutWidth));

[$g, $items] = grid(
    '.g{display:flex;gap:0}.inner{display:grid;grid-template-columns:repeat(2,1fr);flex:1}',
    '<div class="inner"><div>a</div><div>b</div></div>'
);
ok('a grid nests inside a flex item',
    abs($items[0]->children[0]->layoutWidth - 200.0) < 1.0,
    sprintf('%.1f', $items[0]->children[0]->layoutWidth));

// 13. Fragmentation ---------------------------------------------------
$html = '<style>.g{display:grid;grid-template-columns:repeat(2,1fr);gap:6pt}'
    . '.c{background:#eee;padding:4pt}</style><div class="g">'
    . str_repeat('<div class="c">' . str_repeat('word ', 12) . '</div>', 40)
    . '</div>';
$d = new DOMDocument();
libxml_use_internal_errors(true);
$d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();
$r = new StyleResolver();
$r->addStylesheet('.g{display:grid;grid-template-columns:repeat(2,1fr);gap:6pt}.c{background:#eee;padding:4pt}');
$tree = (new HtmlBuilder($r))->build($d);
$tree->width = 400.0;
(new FlexLayout())->layout($tree, 400.0, 300.0);

$count = static function (Node $n) use (&$count): int {
    $t = 0;
    foreach ($n->lineBoxes as $lb) { foreach ($lb->items as $i) { if (!$i->isSpace) { $t++; } } }
    foreach ($n->children as $c) { $t += $count($c); }
    return $t;
};
$before = $count($tree);
$pages = (new Fragmenter(300.0))->fragment($tree);
$after = 0;
$over = 0;
foreach ($pages as $page) {
    foreach ($page as $f) {
        if ($f->y + $f->h > 300.5) { $over++; }
        foreach ($f->lines as $lb) {
            foreach ($lb->items as $i) { if (!$i->isSpace) { $after++; } }
        }
    }
}
ok('a tall grid paginates without losing content or overflowing',
    count($pages) > 1 && $over === 0 && $after >= $before,
    sprintf('%d pages, %d/%d glyph runs', count($pages), $after, $before));

// 14. End to end ------------------------------------------------------
$out = '/tmp/grid_pipeline.pdf';
$n = Html::make(
    '<style>.g{display:grid;grid-template-columns:repeat(3,1fr);gap:8pt}'
    . '.c{background:#eef;padding:6pt}</style>'
    . '<h1>Grid</h1><div class="g">'
    . str_repeat('<div class="c">cell content here</div>', 30)
    . '</div>'
)->save($out);
$probe = trim((string) shell_exec(
    'python3 -c "from pypdf import PdfReader; r=PdfReader(\'' . $out . '\');'
    . ' print(len(r.pages)); print(sum(p.extract_text().count(chr(99)+\'ell content\') for p in r.pages))"'
));
[$pageCount, $cellCount] = array_map('intval', explode("\n", $probe));
ok('HTML with a grid in, paginated PDF out',
    $pageCount === $n && $cellCount === 30, "$n pages, $cellCount cells");


// 15. grid-template-areas --------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-areas:"head head" "side main";'
    . 'grid-template-columns:100pt 1fr;width:400pt}'
    . '.h{grid-area:head}.s{grid-area:side}.m{grid-area:main}',
    '<div class="h">H</div><div class="s">S</div><div class="m">M</div>'
);
ok('grid-template-areas places items by name',
    abs($items[0]->layoutWidth - 400.0) < 0.5     // head spans both columns
    && abs($items[1]->x) < 0.5 && $items[1]->y > 0.0   // side, row 2 col 1
    && abs($items[2]->x - 100.0) < 0.5,                 // main, row 2 col 2
    sprintf('head %.0fpt wide, side x=%.0f, main x=%.0f',
        $items[0]->layoutWidth, $items[1]->x, $items[2]->x));

ok('areas define the explicit grid when no row template is given',
    $items[1]->y === $items[2]->y && $items[1]->y > $items[0]->y);

// 16. Named lines -----------------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:[a] 100pt [b] 1fr [c];width:400pt}.x{grid-column-start:b}',
    '<div class="x">X</div>'
);
ok('a named line resolves in placement',
    abs($items[0]->x - 100.0) < 0.5, sprintf('x=%.1f', $items[0]->x));

[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:[s] 1fr [m] 1fr [e];width:400pt}'
    . '.x{grid-column-start:s;grid-column-end:e}',
    '<div class="x">X</div>'
);
ok('a named line pair spans the tracks between them',
    abs($items[0]->layoutWidth - 400.0) < 0.5, sprintf('%.1f', $items[0]->layoutWidth));

// 17. auto-fill -------------------------------------------------------
[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:repeat(auto-fill,100pt);gap:10pt;width:430pt}',
    '<div>a</div><div>b</div><div>c</div><div>d</div><div>e</div>'
);
// 4 x 100 + 3 x 10 = 430 exactly, so four columns fit and the fifth wraps.
ok('repeat(auto-fill) fits as many tracks as the container allows',
    xs($items) === [0.0, 110.0, 220.0, 330.0, 0.0],
    'x=' . implode(',', xs($items)));

[$g, $items] = grid(
    '.g{display:grid;grid-template-columns:repeat(auto-fill,100pt);gap:10pt;width:250pt}',
    '<div>a</div><div>b</div><div>c</div>'
);
ok('a narrower container fits fewer auto-fill tracks',
    xs($items) === [0.0, 110.0, 0.0], 'x=' . implode(',', xs($items)));

// 18. Dense packing ---------------------------------------------------
// A full-width item forces a new row, leaving two cells free in the first.
// Sparse placement walks past them; dense placement goes back and fills one.
$layout = '.g{display:grid;grid-template-columns:repeat(3,1fr);width:300pt}.w{grid-column:span 3}';
$content = '<div>a</div><div class="w">wide</div><div>c</div>';

[$g, $sparse] = grid($layout, $content);
[$g, $dense] = grid($layout . '.g{grid-auto-flow:row dense}', $content);

ok('sparse placement leaves the hole behind a full-width item',
    ys($sparse)[2] > ys($sparse)[1],
    'y=' . implode(',', ys($sparse)));
ok('dense packing back-fills that hole',
    abs(ys($dense)[2] - ys($dense)[0]) < 0.01 && xs($dense)[2] > xs($dense)[0],
    'y=' . implode(',', ys($dense)) . ' x=' . implode(',', xs($dense)));

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
