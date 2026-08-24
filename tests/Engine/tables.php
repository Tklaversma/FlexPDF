<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, Fragmenter, Html, Node};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

function build(string $html, string $css = '', float $w = 400.0, float $h = 600.0): Node
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $r = new StyleResolver();
    $r->addStylesheet(SUITE_BODY_RESET);
    if ($css !== '') { $r->addStylesheet($css); }
    $t = (new HtmlBuilder($r))->build($d);
    (new FlexLayout())->layout($t, $w, $h);
    return $t;
}

function table(Node $root): Node
{
    if ($root->display === 'table') { return $root; }
    foreach ($root->children as $c) {
        $t = table($c);
        if ($t->display === 'table') { return $t; }
    }
    return $root;
}

/** @return float[] */
function cols(Node $row): array
{
    return array_map(fn(Node $c) => round($c->layoutWidth, 2), $row->children);
}

echo "\nCSS table layout\n\n";

// 1. Structure -------------------------------------------------------
$t = table(build('<table><thead><tr><th>A</th><th>B</th></tr></thead>'
    . '<tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></tbody></table>'));
ok('thead/tbody are flattened into a flat list of rows',
    $t->display === 'table' && count($t->children) === 3,
    count($t->children) . ' rows');
ok('every row is a table-row of table-cells',
    $t->children[0]->display === 'table-row'
    && $t->children[0]->children[0]->display === 'table-cell');
ok('thead rows are flagged to repeat after a break',
    $t->children[0]->isHeaderRow && $t->children[0]->repeatOnBreak
    && !$t->children[1]->isHeaderRow);

// 2. Column widths ---------------------------------------------------
$t = table(build(
    '<table><tr><td>A very much longer cell of text here</td><td>x</td></tr>'
    . '<tr><td>short</td><td>y</td></tr></table>'
));
$w = cols($t->children[0]);
// An auto-width table is shrink-to-fit, so it stops at what its columns ask
// for rather than filling the 400pt offered. Chrome: 142.08 + 6.00 = 148.08,
// re-measured in round 18o when defect BV put the UA cell padding back to
// Chrome's 1px. The old figure carried the engine's own 3px 5px inside it.
ok('an auto table shrink-wraps to its columns instead of filling',
    abs(array_sum($w) - 148.1) < 1.0, implode(' + ', $w));
ok('a column with more content gets more width',
    $w[0] > $w[1] * 2, sprintf('%.1f vs %.1f', $w[0], $w[1]));
ok('every row shares one set of column widths',
    cols($t->children[1]) === $w);

// 3. Explicit widths -------------------------------------------------
$t = table(build('<table><tr><td class="a">a</td><td>b</td></tr></table>',
    '.a { width: 120pt }'));
$w = cols($t->children[0]);
ok('an explicit cell width is respected as a column minimum',
    $w[0] >= 119.9, sprintf('%.1f', $w[0]));

// 4. colspan ---------------------------------------------------------
// Borders collapsed, so the spanned width is the columns and nothing else:
// with Chrome's 2px UA `border-spacing` a colspan also swallows the gutters
// between the columns it covers, which is not what this case is about.
$t = table(build(
    '<table style="border-collapse:collapse"><tr><td>one</td><td>two</td><td>three</td></tr>'
    . '<tr><td colspan="2">spans two</td><td>three</td></tr></table>'
));
$normal = cols($t->children[0]);
$spanned = $t->children[1]->children;
ok('a colspan cell covers the width of the columns it spans',
    abs($spanned[0]->layoutWidth - ($normal[0] + $normal[1])) < 0.1,
    sprintf('%.1f vs %.1f + %.1f', $spanned[0]->layoutWidth, $normal[0], $normal[1]));
ok('the row after a colspan still aligns to the grid',
    abs($spanned[1]->x - ($normal[0] + $normal[1])) < 0.1);

// 5. rowspan ---------------------------------------------------------
$t = table(build(
    '<table><tr><td rowspan="2">tall</td><td>a</td></tr><tr><td>b</td></tr>'
    . '<tr><td>c</td><td>d</td></tr></table>'
));
$rows = $t->children;
ok('a rowspan cell reserves its grid slots so later cells shift over',
    count($rows[1]->children) === 1
    && abs($rows[1]->children[0]->x - $rows[0]->children[1]->x) < 0.1,
    'row 2 cell aligns under column 2');
ok('a rowspan cell is as tall as the rows it covers',
    $rows[0]->children[0]->layoutHeight >= $rows[0]->layoutHeight + $rows[1]->layoutHeight - 0.1,
    sprintf('%.1f over %.1f + %.1f',
        $rows[0]->children[0]->layoutHeight, $rows[0]->layoutHeight, $rows[1]->layoutHeight));

// 6. Row heights -----------------------------------------------------
$t = table(build(
    '<table><tr><td>one line</td>'
    . '<td>a cell with a great deal more text in it so that it has to wrap onto several lines</td>'
    . '</tr></table>', '', 260.0
));
$row = $t->children[0];
// The floor is a sanity check that the wrapped cell really did make the row
// several lines tall; what the case is for is the clause after it. It came
// down from 25.0 in round 18o with defect BV, because 4pt of that was UA
// padding this engine had and Chrome did not.
ok('row height is the tallest cell in it',
    $row->layoutHeight > 20.0 && abs($row->children[0]->layoutHeight - $row->layoutHeight) < 0.1,
    sprintf('%.1fpt', $row->layoutHeight));

// 7. vertical-align --------------------------------------------------
$t = table(build(
    '<table><tr><td class="v">short</td>'
    . '<td>a much longer cell that will wrap over several lines to make the row tall</td>'
    . '</tr></table>',
    '.v { vertical-align: bottom }', 240.0
));
$cell = $t->children[0]->children[0];
$text = $cell->children[0];
ok('vertical-align: bottom pushes cell content down',
    $text->y > $cell->layoutHeight * 0.4,
    sprintf('text y=%.1f in a %.1fpt cell', $text->y, $cell->layoutHeight));

// The declaration is written out because the UA default is `middle`, not
// `top`: HTML's rendering sheet centres an undeclared cell in its row, which
// is what defect CA landed and what `regressions.php` pins.
$t2 = table(build(
    '<table><tr><td class="v">short</td>'
    . '<td>a much longer cell that will wrap over several lines to make the row tall</td>'
    . '</tr></table>', '.v { vertical-align: top }', 240.0
));
ok('vertical-align: top leaves content at the top',
    $t2->children[0]->children[0]->children[0]->y < 5.0);

// 8. border-spacing --------------------------------------------------
$t = table(build('<table><tr><td>a</td><td>b</td></tr></table>',
    'table { border-spacing: 6pt }'));
$c = $t->children[0]->children;
ok('border-spacing separates columns',
    abs($c[1]->x - ($c[0]->x + $c[0]->layoutWidth + 6.0)) < 0.1,
    sprintf('gap %.1fpt', $c[1]->x - $c[0]->x - $c[0]->layoutWidth));

// 9. Font-size regression --------------------------------------------
// Computed font-size used to be stored unitless, so each nested level
// re-read it as px and shrank it by 0.75. A table is six levels deep.
$root = build('<div><div><table><tr><td>deep</td></tr></table></div></div>');
$findRun = function (Node $n) use (&$findRun) {
    if ($n->display === 'text' && $n->runs !== []) { return $n->runs[0]; }
    foreach ($n->children as $c) { $r = $findRun($c); if ($r !== null) { return $r; } }
    return null;
};
$run = $findRun($root);
ok('font-size does not compound through nesting',
    $run !== null && abs($run->fontSize - 9.0) < 0.01,
    $run ? sprintf('%.2fpt at depth', $run->fontSize) : 'no run');

// 10. Pagination -----------------------------------------------------
$rowsHtml = '';
for ($i = 1; $i <= 80; $i++) {
    $rowsHtml .= "<tr><td>Item $i with a description</td><td>$i</td><td>10.00</td></tr>";
}
$root = build(
    '<table><thead><tr><th>Description</th><th>Qty</th><th>Price</th></tr></thead>'
    . "<tbody>$rowsHtml</tbody></table>",
    '', 400.0, 300.0
);
$pages = (new Fragmenter(300.0))->fragment($root);

$sliced = 0;
$headerPages = 0;
$dataRows = 0;
foreach ($pages as $page) {
    $sawHeader = false;
    foreach ($page as $f) {
        if ($f->node->display === 'table-row') {
            if ($f->isContinuation || $f->splitsAfter) { $sliced++; }
            if ($f->node->isHeaderRow) { $sawHeader = true; }
            else { $dataRows++; }
        }
    }
    if ($sawHeader) { $headerPages++; }
}
ok('a long table paginates', count($pages) > 3, count($pages) . ' pages');
ok('table rows are never sliced mid-cell', $sliced === 0);
ok('the header row repeats on every page',
    $headerPages === count($pages), "$headerPages of " . count($pages));
ok('every data row survives exactly once', $dataRows === 80, "$dataRows of 80");

// 11. End-to-end -----------------------------------------------------
$out = '/tmp/table_pipeline.pdf';
$n = Html::make('<style>td,th{border:0.5pt solid #ccc}</style>'
    . '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody>'
    . str_repeat('<tr><td>cell one</td><td>cell two</td></tr>', 120)
    . '</tbody></table>')->save($out);
$txt = (string) shell_exec(
    'python3 -c "from pypdf import PdfReader; r=PdfReader(\'' . $out . '\');'
    . 'print(len(r.pages));'
    . 'print(sum(p.extract_text().count(chr(39)+chr(39)) or p.extract_text().count(\'cell one\') for p in r.pages))"'
);
[$pageCount, $cellCount] = array_map('intval', explode("\n", trim($txt)));
ok('HTML table in, paginated PDF out with every row intact',
    $n > 1 && $pageCount === $n && $cellCount === 120,
    "$n pages, $cellCount rows");


// 12. Overflow clamping ----------------------------------------------
// A rowspan cell whose rows straddle a page break used to paint straight
// off the bottom of the page.
$rowsHtml = '';
for ($i = 1; $i <= 10; $i++) {
    $rowsHtml .= '<tr>' . ($i === 1 ? '<td rowspan="10" class="r">Group</td>' : '')
        . "<td>Product $i</td><td>10</td></tr>";
}
$root = build("<table>$rowsHtml</table>", '.r { background: #eef2fa }', 400.0, 90.0);
$pageH = 90.0;
$pages = (new Fragmenter($pageH))->fragment($root);

$overflowing = 0;
$continuations = 0;
foreach ($pages as $page) {
    foreach ($page as $f) {
        if ($f->y + $f->h > $pageH + 0.01) { $overflowing++; }
        if ($f->isContinuation && $f->node->display === 'table-cell') { $continuations++; }
    }
}
ok('no fragment paints past the bottom of its page', $overflowing === 0,
    count($pages) . ' pages');
ok('a rowspan cell straddling a break continues on the next page',
    $continuations >= 1, "$continuations continuation(s)");

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
