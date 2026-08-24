<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\{FontRegistry, Node, InlineRun, FlexLayout, Pdf};

require_once __DIR__ . '/../tests/Engine/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

FontRegistry::default()->registerTrueType('DejaVu', DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf');
FontRegistry::default()->registerTrueType('DejaVuSerif', DEJAVU . 'DejaVuSerif.ttf', DEJAVU . 'DejaVuSerif-Bold.ttf');

const INK    = [0.12, 0.13, 0.16];
const MUTED  = [0.45, 0.47, 0.52];
const ACCENT = [0.14, 0.40, 0.85];
const PANEL  = [0.96, 0.96, 0.97];

function txt(string $s, float $size = 9.0, bool $bold = false, array $color = INK, string $family = 'DejaVu', array $extra = []): Node
{
    return new Node($extra + ['display'=>'text','text'=>$s,'fontSize'=>$size,'bold'=>$bold,
                              'color'=>$color,'fontFamily'=>$family]);
}
function col(array $c, array $s = []): Node { return new Node($s + ['display'=>'flex','flexDirection'=>'column'], $c); }
function rw(array $c, array $s = []): Node  { return new Node($s + ['display'=>'flex','flexDirection'=>'row'], $c); }

$samples = [
    'Polish'     => 'Zażółć gęślą jaźń — Kraków, Łódź',
    'Norwegian'  => 'Blåbærsyltetøy fra Ålesund',
    'Czech'      => 'Příliš žluťoučký kůň úpěl ďábelské ódy',
    'Turkish'    => 'Pijamalı hasta yağız şoföre çabucak güvendi',
    'Greek'      => 'Ξεσκεπάζω την ψυχοφθόρα βδελυγμία',
    'Russian'    => 'Съешь же ещё этих мягких французских булок',
    'Vietnamese' => 'Tiếng Việt — Hà Nội, Đà Nẵng',
    'Symbols'    => '€ £ ¥ ₽ © ® ™ § ¶ † ‡ • … ‰ ± × ÷ ≠ ≤ ≥ ∞',
];

$rows = [];
$i = 0;
foreach ($samples as $lang => $text) {
    $rows[] = rw([
        txt($lang, 8.0, true, MUTED, 'DejaVu', ['width' => 80.0]),
        txt($text, 10.0, false, INK, 'DejaVu', ['flexGrow' => 1.0, 'flexBasis' => 0.0]),
    ], [
        'padding'    => ['top'=>6.0,'bottom'=>6.0,'left'=>10.0,'right'=>10.0],
        'background' => $i++ % 2 === 0 ? PANEL : [1.0, 1.0, 1.0],
        'alignItems' => 'center',
    ]);
}

$doc = col([
    txt('Embedded fonts', 22.0, true, INK),
    txt('Subset TrueType, Identity-H encoding, extractable text', 9.5, false, MUTED,
        'DejaVu', ['margin'=>['top'=>3.0,'bottom'=>18.0]]),

    col($rows),

    txt('MIXED FAMILIES ON ONE LINE', 7.5, true, MUTED, 'DejaVu', ['margin'=>['top'=>20.0,'bottom'=>6.0]]),
    new Node(['display'=>'text','textAlign'=>'justify','runs'=>[
        new InlineRun('Three faces, one baseline: ', 10.0, false, INK, 1.4, 'DejaVu'),
        new InlineRun('DejaVu Serif at 13pt', 13.0, true, ACCENT, 1.4, 'DejaVuSerif'),
        new InlineRun(', ', 10.0, false, INK, 1.4, 'DejaVu'),
        new InlineRun('DejaVu Sans Bold', 10.0, true, INK, 1.4, 'DejaVu'),
        new InlineRun(', and Helvetica from the base-14 set which needs no embedding at all. '
            . 'Each run is measured with its own metrics and encoded with its own scheme.', 10.0, false, MUTED, 1.4, 'Helvetica'),
    ]]),
], ['width' => 495.28]);

$t0 = microtime(true);
(new FlexLayout())->layout($doc, 495.28, 741.89);
$layoutMs = (microtime(true) - $t0) * 1000;

$shift = function (Node $n) use (&$shift) {
    $n->x += 50.0; $n->y += 50.0;
    foreach ($n->children as $c) { $shift($c); }
};
$shift($doc);

$pdf = new Pdf();
$pdf->beginPage();
$paint = function (Node $n) use (&$paint, $pdf) {
    if ($n->background !== null) {
        $pdf->fillRect($n->x, $n->y, $n->layoutWidth, $n->layoutHeight, $n->background, $n->borderRadius);
    }
    if ($n->display === 'text' && $n->lineBoxes !== []) {
        $pdf->paintLines($n->lineBoxes, $n->x, $n->y);
    }
    foreach ($n->children as $c) { $paint($c); }
};
$paint($doc);
$pdf->endPage();

$t1 = microtime(true);
$pdf->save(__DIR__ . '/unicode.pdf');
$writeMs = (microtime(true) - $t1) * 1000;

printf("layout %.2f ms   subset+write %.2f ms   %d bytes\n",
    $layoutMs, $writeMs, filesize(__DIR__ . '/unicode.pdf'));
