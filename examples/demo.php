<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\{Font, FontRegistry, Node, FlexLayout, Pdf, Painter};

require_once __DIR__ . '/../tests/Engine/support/bootstrap.php';

define('DEJAVU', dejavu_dir());
FontRegistry::default()->registerTrueType('DejaVu', DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf');

const INK    = [0.12, 0.13, 0.16];
const MUTED  = [0.45, 0.47, 0.52];
const ACCENT = [0.14, 0.40, 0.85];
const PANEL  = [0.96, 0.96, 0.97];
const WHITE  = [1.0, 1.0, 1.0];
const RULE   = [0.85, 0.86, 0.88];

function text(string $s, float $size = 10.0, bool $bold = false, array $color = INK, array $extra = []): Node
{
    return new Node($extra + [
        'display'   => 'text',
        'fontFamily'=> 'DejaVu',
        'text'      => $s,
        'fontSize'  => $size,
        'bold'      => $bold,
        'color'     => $color,
        'flexShrink'=> 1.0,
    ]);
}

function column(array $children, array $style = []): Node
{
    return new Node($style + ['display' => 'flex', 'flexDirection' => 'column'], $children);
}

function row(array $children, array $style = []): Node
{
    return new Node($style + ['display' => 'flex', 'flexDirection' => 'row'], $children);
}

// ---------------------------------------------------------------------
// A bar chart, built entirely out of flex boxes. No SVG, no canvas, no JS.
// ---------------------------------------------------------------------
function barChart(array $data, float $height = 110.0): Node
{
    $max = max($data);
    $bars = [];
    foreach ($data as $label => $value) {
        $barHeight = $height * ($value / $max);
        $bars[] = column([
            text(number_format($value), 7.0, true, MUTED, ['alignSelf' => 'center']),
            new Node([
                'display'      => 'rect',
                'height'       => $barHeight,
                'background'   => ACCENT,
                'borderRadius' => 2.0,
            ]),
            text($label, 7.0, false, MUTED, ['alignSelf' => 'center', 'margin' => ['top' => 4.0]]),
        ], [
            'flexGrow'      => 1.0,
            'flexBasis'     => 0.0,
            'justifyContent'=> 'flex-end',
        ]);
    }

    return row($bars, ['gap' => 8.0, 'alignItems' => 'flex-end']);
}

// ---------------------------------------------------------------------
// The document
// ---------------------------------------------------------------------
$items = [
    ['Layout engine design',       '24 h', '2,880.00'],
    ['Flexbox solver (spec §9.7)', '31 h', '3,720.00'],
    ['Text measurement + shaping', '18 h', '2,160.00'],
    ['PDF writer',                 '12 h', '1,440.00'],
];

$rows = [];
foreach ($items as $i => [$desc, $hours, $amount]) {
    $rows[] = row([
        text($desc,   9.0, false, INK,   ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
        text($hours,  9.0, false, MUTED, ['width' => 60.0]),
        text($amount, 9.0, false, INK,   ['width' => 80.0]),
    ], [
        'padding'    => ['top' => 7.0, 'bottom' => 7.0, 'left' => 10.0, 'right' => 10.0],
        'background' => $i % 2 === 0 ? PANEL : WHITE,
        'alignItems' => 'center',
    ]);
}

$doc = column([
    // ---- header: two panels side by side. This is the exact layout that
    // ---- no pure-PHP library has ever been able to render. ----
    row([
        column([
            text('FROM', 7.0, true, MUTED),
            text('Northbound Systems BV', 11.0, true),
            text('Keizersgracht 241', 9.0, false, MUTED),
            text('1016 EA Amsterdam', 9.0, false, MUTED),
        ], ['flexGrow' => 1.0, 'flexBasis' => 0.0, 'gap' => 2.0]),

        column([
            text('BILL TO', 7.0, true, MUTED),
            text('Harbour Freight Co.', 11.0, true),
            text('Docklands 14', 9.0, false, MUTED),
            text('3011 BN Rotterdam', 9.0, false, MUTED),
        ], ['flexGrow' => 1.0, 'flexBasis' => 0.0, 'gap' => 2.0]),

        column([
            text('INVOICE', 7.0, true, MUTED, ['alignSelf' => 'flex-end']),
            text('#2026-0417', 11.0, true, ACCENT, ['alignSelf' => 'flex-end']),
            text('Due 30 Aug 2026', 9.0, false, MUTED, ['alignSelf' => 'flex-end']),
        ], ['flexGrow' => 1.0, 'flexBasis' => 0.0, 'gap' => 2.0]),
    ], ['gap' => 24.0, 'alignItems' => 'flex-start']),

    new Node(['display' => 'rect', 'height' => 1.0, 'background' => RULE, 'margin' => ['top' => 22.0, 'bottom' => 22.0]]),

    text('Billable work', 13.0, true),

    column($rows, ['margin' => ['top' => 10.0]]),

    // ---- totals, pushed right with justify-content ----
    row([
        column([
            row([
                text('Subtotal', 9.0, false, MUTED, ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
                text('10,200.00', 9.0, false, INK, ['width' => 80.0]),
            ]),
            row([
                text('VAT 21%', 9.0, false, MUTED, ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
                text('2,142.00', 9.0, false, INK, ['width' => 80.0]),
            ]),
            new Node(['display' => 'rect', 'height' => 1.0, 'background' => RULE, 'margin' => ['top' => 5.0, 'bottom' => 5.0]]),
            row([
                text('Total due', 11.0, true, INK, ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
                text('12,342.00', 11.0, true, ACCENT, ['width' => 80.0]),
            ]),
        ], ['width' => 220.0, 'gap' => 4.0]),
    ], ['justifyContent' => 'flex-end', 'margin' => ['top' => 14.0]]),

    // ---- chart section ----
    column([
        text('Hours logged per week', 11.0, true, INK, ['margin' => ['bottom' => 12.0]]),
        barChart(['W22' => 14, 'W23' => 22, 'W24' => 19, 'W25' => 31, 'W26' => 27, 'W27' => 12]),
    ], [
        'margin'       => ['top' => 34.0],
        'padding'      => 16.0,
        'background'   => PANEL,
        'borderRadius' => 6.0,
    ]),

    row([
        text('Payment within 30 days to NL91 ABNA 0417 1643 00', 8.0, false, MUTED, ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
        text('Page 1 of 1', 8.0, false, MUTED),
    ], ['margin' => ['top' => 26.0]]),

], ['width' => 495.28]);   // A4 width minus 50pt margins

// ---------------------------------------------------------------------
$t0 = microtime(true);
(new FlexLayout())->layout($doc, 495.28, 741.89);
$layoutMs = (microtime(true) - $t0) * 1000;

// Shift into the page margin.
$shift = function (Node $n) use (&$shift) {
    $n->x += 50.0; $n->y += 50.0;
    foreach ($n->children as $c) { $shift($c); }
};
$shift($doc);

$pdf = new Pdf();
$pdf->beginPage();
$t1 = microtime(true);
(new Painter($pdf))->paint($doc);
$pdf->endPage();
$pdf->save(__DIR__ . '/invoice.pdf');
$paintMs = (microtime(true) - $t1) * 1000;

$count = 0;
$walk = function (Node $n) use (&$walk, &$count) { $count++; foreach ($n->children as $c) { $walk($c); } };
$walk($doc);

printf("nodes: %d   layout: %.2f ms   paint+write: %.2f ms\n", $count, $layoutMs, $paintMs);
