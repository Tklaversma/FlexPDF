<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';


use FlexPDF\Engine\{BoxPainter, Font, Node, InlineRun, FlexLayout, Fragmenter, Fragment, Pdf};

const INK    = [0.12, 0.13, 0.16];
const MUTED  = [0.45, 0.47, 0.52];
const ACCENT = [0.14, 0.40, 0.85];
const PANEL  = [0.95, 0.96, 0.97];
const HEAD   = [0.88, 0.90, 0.93];
const WHITE  = [1.0, 1.0, 1.0];
const RULE   = [0.84, 0.85, 0.88];

const PAGE_W = 595.28;
const PAGE_H = 841.89;
const MARGIN = 50.0;
const CONTENT_W = PAGE_W - 2 * MARGIN;
const CONTENT_H = PAGE_H - 2 * MARGIN;

function t(string $s, float $size = 9.0, bool $bold = false, array $color = INK, array $extra = []): Node
{
    return new Node($extra + ['display'=>'text','text'=>$s,'fontSize'=>$size,'bold'=>$bold,'color'=>$color]);
}
function col(array $c, array $s = []): Node { return new Node($s + ['display'=>'flex','flexDirection'=>'column'], $c); }
function rw(array $c, array $s = []): Node  { return new Node($s + ['display'=>'flex','flexDirection'=>'row'], $c); }

// =====================================================================
echo "\n";
echo "FRAGMENTATION SPIKE\n";
echo str_repeat('=', 70) . "\n";

// ---------------------------------------------------------------------
// Q1. Can a flex line split mid-item, or must whole items move over?
// ---------------------------------------------------------------------
echo "\nQ1  Breaking a column of flex rows (a 'table')\n";

function tableRow(string $desc, string $qty, string $amt, bool $header = false, bool $alt = false): Node
{
    return rw([
        t($desc, $header ? 8.0 : 9.0, $header, $header ? INK : INK, ['flexGrow'=>1.0,'flexBasis'=>0.0]),
        t($qty,  $header ? 8.0 : 9.0, $header, MUTED, ['width'=>70.0]),
        t($amt,  $header ? 8.0 : 9.0, $header, INK,   ['width'=>80.0]),
    ], [
        'padding'    => ['top'=>7.0,'bottom'=>7.0,'left'=>10.0,'right'=>10.0],
        'background' => $header ? HEAD : ($alt ? PANEL : WHITE),
        'alignItems' => 'center',
        'repeatOnBreak' => $header,
    ]);
}

$rows = [tableRow('Description', 'Hours', 'Amount', true)];
for ($i = 1; $i <= 60; $i++) {
    $rows[] = tableRow("Work package $i — layout, measurement and paint", (string)(8 + $i % 20) . ' h', number_format(120 * (8 + $i % 20), 2), false, $i % 2 === 0);
}
$table = col($rows, ['width' => CONTENT_W]);

(new FlexLayout())->layout($table, CONTENT_W, CONTENT_H);
$frag = new Fragmenter(CONTENT_H);
$pages = $frag->fragment($table);

$rowFrags = [];
foreach ($pages as $pi => $page) {
    foreach ($page as $f) {
        if ($f->node->display === 'flex' && $f->node->isRow()) {
            $rowFrags[] = [$pi, $f];
        }
    }
}
$split = array_filter($rowFrags, fn($r) => $r[1]->splitsAfter || $r[1]->isContinuation);

printf("    %d rows over %d pages\n", count($rows), count($pages));
printf("    rows sliced mid-item: %d\n", count($split));
printf("    -> %s\n", count($split) === 0
    ? "flex rows moved WHOLE to the next page. The line is atomic."
    : "some rows were sliced.");

// ---------------------------------------------------------------------
// Q3. Repeating headers (checked here because it shares the fixture)
// ---------------------------------------------------------------------
echo "\nQ3  Repeating header across pages\n";
$headerPerPage = [];
foreach ($pages as $pi => $page) {
    $n = 0;
    foreach ($page as $f) {
        if ($f->node->repeatOnBreak && $f->node->display === 'flex') { $n++; }
    }
    $headerPerPage[$pi] = $n;
}
printf("    header fragments per page: %s\n", implode(', ', $headerPerPage));
printf("    -> %s\n", min($headerPerPage) >= 1
    ? "header repeats on every page. Works."
    : "header missing on some pages.");

// ---------------------------------------------------------------------
// Q2. What happens to align-items: stretch when a line IS cut?
// ---------------------------------------------------------------------
echo "\nQ2  Forcing a break inside a single flex line with align-items: stretch\n";

$tall = rw([
    col([t('Left column', 9.0, true), t(str_repeat('Content on the left side. ', 60), 9.0)],
        ['flexGrow'=>1.0,'flexBasis'=>0.0,'background'=>PANEL,'padding'=>10.0]),
    col([t('Right column', 9.0, true), t(str_repeat('Content on the right. ', 40), 9.0)],
        ['flexGrow'=>1.0,'flexBasis'=>0.0,'background'=>HEAD,'padding'=>10.0]),
], ['width'=>CONTENT_W, 'gap'=>16.0, 'alignItems'=>'stretch']);

(new FlexLayout())->layout($tall, CONTENT_W, CONTENT_H);

$leftH  = $tall->children[0]->layoutHeight;
$rightH = $tall->children[1]->layoutHeight;
printf("    unfragmented: line height %.0fpt, left %.0fpt, right %.0fpt (stretched equal: %s)\n",
    $tall->layoutHeight, $leftH, $rightH, abs($leftH - $rightH) < 0.5 ? 'yes' : 'no');

$f2 = new Fragmenter(300.0, new Font(), forceSplitFlexLines: true);
$pages2 = $f2->fragment(new Node(['display'=>'flex','flexDirection'=>'column','width'=>CONTENT_W], [$tall]));
printf("    forced split over %d pages\n", count($pages2));
foreach ($f2->notes as $note) { printf("    note: %s\n", $note); }

$leftFrags = $rightFrags = 0;
$textFrags = 0;
foreach ($pages2 as $page) {
    foreach ($page as $f) {
        if ($f->node->background === PANEL) { $leftFrags++; }
        if ($f->node->background === HEAD)  { $rightFrags++; }
        if ($f->node->display === 'text' && $f->lines !== []) { $textFrags++; }
    }
}
printf("    left-column decoration fragments: %d, right-column: %d, text fragments: %d\n",
    $leftFrags, $rightFrags, $textFrags);

// Do the two columns still line up after the cut?
$mismatch = false;
foreach ($pages2 as $pi => $page) {
    $l = $r = null;
    foreach ($page as $f) {
        if ($f->node->background === PANEL) { $l = $f; }
        if ($f->node->background === HEAD)  { $r = $f; }
    }
    if ($l && $r && abs($l->h - $r->h) > 0.5) {
        printf("    page %d: left slice %.0fpt vs right slice %.0fpt — MISMATCH\n", $pi + 1, $l->h, $r->h);
        $mismatch = true;
    }
}
echo "    -> the stretched height was resolved against the WHOLE line before\n";
echo "       the cut, so each item's background must be re-derived per page.\n";
printf("       slices %s after fragmentation.\n", $mismatch ? 'DIVERGE' : 'stay aligned');

// ---------------------------------------------------------------------
// Render a real multi-page document
// ---------------------------------------------------------------------
$intro = str_repeat(
    'This paragraph exists to be split across a page boundary. Orphan and widow '
    . 'control should keep at least two lines on either side of the break. ', 14
);

$doc = col([
    t('Quarterly statement', 20.0, true, INK),
    t('Northbound Systems BV — period ending 30 June 2026', 9.0, false, MUTED, ['margin'=>['top'=>4.0,'bottom'=>18.0]]),
    t($intro, 9.5, false, INK, ['margin'=>['bottom'=>18.0]]),
    col($rows, ['margin'=>['bottom'=>18.0]]),
    col([
        t('Summary', 11.0, true, INK, ['margin'=>['bottom'=>8.0]]),
        t('This panel is marked break-inside: avoid, so it moves whole rather than being sliced.', 9.0, false, MUTED),
    ], ['padding'=>14.0,'background'=>PANEL,'borderRadius'=>6.0,'breakInside'=>'avoid']),
], ['width' => CONTENT_W]);

$t0 = microtime(true);
(new FlexLayout())->layout($doc, CONTENT_W, CONTENT_H);
$layoutMs = (microtime(true) - $t0) * 1000;

$t1 = microtime(true);
$fr = new Fragmenter(CONTENT_H);
$docPages = $fr->fragment($doc);
$fragMs = (microtime(true) - $t1) * 1000;

$pdf = new Pdf(PAGE_W, PAGE_H);
$font = new Font();
foreach ($docPages as $i => $page) {
    $pdf->beginPage();
    foreach ($page as $f) {
        BoxPainter::paint($pdf, $f->node, $f->x + MARGIN, $f->y + MARGIN, $f->w, $f->h, $f->lines);
    }
    $pn = new Node(['display'=>'text','text'=>sprintf('Page %d of %d', $i + 1, count($docPages)),
                    'fontSize'=>8.0,'color'=>MUTED]);
    (new FlexLayout())->layout(new Node(['display'=>'flex','width'=>60.0], [$pn]), 60.0, 20.0);
    $pdf->paintLines($pn->lineBoxes, PAGE_W - MARGIN - 60, PAGE_H - MARGIN + 6);
    $pdf->endPage();
}
$pdf->save(__DIR__ . '/statement.pdf');

printf("\nRENDERED  %d pages   layout %.1f ms   fragment %.1f ms\n", count($docPages), $layoutMs, $fragMs);
echo str_repeat('=', 70) . "\n\n";
