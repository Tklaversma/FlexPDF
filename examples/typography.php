<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\{Node, InlineRun, FlexLayout, Pdf, Painter};

const INK    = [0.12, 0.13, 0.16];
const MUTED  = [0.45, 0.47, 0.52];
const ACCENT = [0.14, 0.40, 0.85];
const RED    = [0.78, 0.20, 0.22];
const PANEL  = [0.96, 0.96, 0.97];

function para(array $runs, string $align = 'left', array $extra = []): Node
{
    return new Node($extra + ['display' => 'text', 'runs' => $runs, 'textAlign' => $align]);
}
function label(string $s): Node
{
    return new Node(['display'=>'text','text'=>$s,'fontSize'=>7.5,'bold'=>true,'color'=>MUTED,
                     'margin'=>['top'=>18.0,'bottom'=>5.0]]);
}
function col(array $c, array $s = []): Node { return new Node($s + ['display'=>'flex','flexDirection'=>'column'], $c); }
function rw(array $c, array $s = []): Node  { return new Node($s + ['display'=>'flex','flexDirection'=>'row'], $c); }

$body = 'Anthropic recommends that layout engines resolve inline content into line boxes before painting, because a line box is the only place where mixed font sizes can be reconciled against a shared baseline. ';

$doc = col([
    new Node(['display'=>'text','text'=>'Inline formatting','fontSize'=>22.0,'bold'=>true,'color'=>INK]),
    new Node(['display'=>'text','text'=>'Mixed runs, shared baselines, and the four text alignments','fontSize'=>9.5,'color'=>MUTED,'margin'=>['top'=>3.0]]),

    label('MIXED RUNS ON ONE LINE'),
    para([
        new InlineRun('Invoice ', 13.0, true, INK),
        new InlineRun('#2026-0417', 13.0, true, ACCENT),
        new InlineRun(' is ', 9.5, false, INK),
        new InlineRun('overdue', 9.5, true, RED),
        new InlineRun(' by ', 9.5, false, INK),
        new InlineRun('14 days', 9.5, true, INK),
        new InlineRun('. Each span keeps its own face, size and colour, and all of them sit on one baseline.', 9.5, false, MUTED),
    ]),

    label('TEXT-ALIGN: JUSTIFY'),
    para([new InlineRun($body . $body, 9.0, false, INK)], 'justify'),

    label('LEFT / CENTER / RIGHT'),
    rw([
        col([para([new InlineRun($body, 8.0, false, INK)], 'left')],
            ['flexGrow'=>1.0,'flexBasis'=>0.0,'padding'=>9.0,'background'=>PANEL]),
        col([para([new InlineRun($body, 8.0, false, INK)], 'center')],
            ['flexGrow'=>1.0,'flexBasis'=>0.0,'padding'=>9.0,'background'=>PANEL]),
        col([para([new InlineRun($body, 8.0, false, INK)], 'right')],
            ['flexGrow'=>1.0,'flexBasis'=>0.0,'padding'=>9.0,'background'=>PANEL]),
    ], ['gap'=>10.0,'alignItems'=>'stretch']),

    label('BASELINE ACROSS SIZES'),
    para([
        new InlineRun('24', 24.0, true, ACCENT),
        new InlineRun(' pt next to ', 9.0, false, INK),
        new InlineRun('14', 14.0, true, INK),
        new InlineRun(' pt next to ', 9.0, false, INK),
        new InlineRun('8', 8.0, true, MUTED),
        new InlineRun(' pt — one baseline, and the line box grows from half-leading rather than the largest font size.', 9.0, false, MUTED),
    ]),

    label('WRAPPING ACROSS A STYLE BOUNDARY'),
    para([
        new InlineRun('A word is never split by a style change: this run is regular and ', 9.0, false, INK),
        new InlineRun('this one is bold', 9.0, true, INK),
        new InlineRun(', yet the break opportunities are computed over the combined token stream, not per run.', 9.0, false, MUTED),
    ], 'justify', ['width' => 300.0]),
], ['width' => 495.28]);

$t0 = microtime(true);
(new FlexLayout())->layout($doc, 495.28, 741.89);
$ms = (microtime(true) - $t0) * 1000;

$shift = function (Node $n) use (&$shift) {
    $n->x += 50.0; $n->y += 50.0;
    foreach ($n->children as $c) { $shift($c); }
};
$shift($doc);

$pdf = new Pdf();
$pdf->beginPage();
(new Painter($pdf))->paint($doc);
$pdf->endPage();
$pdf->save(__DIR__ . '/typography.pdf');

$lineBoxes = 0; $items = 0;
$walk = function (Node $n) use (&$walk, &$lineBoxes, &$items) {
    foreach ($n->lineBoxes as $lb) { $lineBoxes++; $items += count($lb->items); }
    foreach ($n->children as $c) { $walk($c); }
};
$walk($doc);

printf("%d line boxes, %d positioned runs, layout %.2f ms\n", $lineBoxes, $items, $ms);
