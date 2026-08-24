<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\{Font, InlineRun, InlineFormatter, Node, FlexLayout};

$pass = 0; $fail = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

echo "\nInline formatting context\n\n";

$inline = new InlineFormatter();
$reg = new Font();
$bold = new Font('Helvetica-Bold', true);

// 1. A word must not be split across a style change ------------------
$runs = [
    new InlineRun('Hello ', 12.0, false),
    new InlineRun('world', 12.0, true),
];
$lines = $inline->format($runs, 500.0);
$expected = $reg->stringWidth('Hello', 12.0)
    + $reg->stringWidth(' ', 12.0)
    + $bold->stringWidth('world', 12.0);
ok('mixed bold/regular measured with the correct font per run',
    count($lines) === 1 && abs($lines[0]->width - $expected) < 0.01,
    sprintf('%.3fpt', $lines[0]->width));

$words = array_values(array_filter($lines[0]->items, fn($i) => !$i->isSpace));
ok('runs stay separate items on the line',
    count($words) === 2
    && $words[0]->run->bold === false
    && $words[1]->run->bold === true);

ok('second run is offset by the first run plus the space',
    abs($words[1]->x - ($reg->stringWidth('Hello', 12.0) + $reg->stringWidth(' ', 12.0))) < 0.01,
    sprintf('x=%.3f', $words[1]->x));

// 2. Shared baseline across font sizes -------------------------------
$runs = [
    new InlineRun('Big ', 20.0, true),
    new InlineRun('and small', 9.0, false),
];
$lines = $inline->format($runs, 500.0);
$lb = $lines[0];

// The band each run contributes, which is the face's own box with the leading
// spent on either side of it. Read through `lineBand()` rather than re-derived
// here: the subject of these three cases is the MAX rule, and a second copy of
// the metric arithmetic only breaks when the metrics get closer to Chrome,
// which is what round 71 did to the base-14 pair.
[$above20, $below20] = $bold->lineBand(20.0, 1.35 * 20.0);
[$above9, $below9]   = $reg->lineBand(9.0, 1.35 * 9.0);

ok('line box baseline = max half-leading ascent of its runs',
    abs($lb->baseline - max($above20, $above9)) < 0.001,
    sprintf('%.3fpt', $lb->baseline));

ok('line box height = max above + max below (not size x line-height)',
    abs($lb->height - (max($above20, $above9) + max($below20, $below9))) < 0.001,
    sprintf('%.3fpt vs naive %.3fpt', $lb->height, 20.0 * 1.35));

ok('both runs share one baseline regardless of size',
    count(array_filter($lb->items, fn($i) => !$i->isSpace)) === 3);

// 3. Wrapping across runs -------------------------------------------
$runs = [
    new InlineRun('The quick brown fox ', 10.0, false),
    new InlineRun('jumps over ', 10.0, true),
    new InlineRun('the lazy dog and keeps running onward', 10.0, false),
];
$lines = $inline->format($runs, 120.0);
$allFit = true;
foreach ($lines as $l) { if ($l->width > 120.0 + 0.01) { $allFit = false; } }
ok('wraps across run boundaries, every line within the limit',
    $allFit && count($lines) > 1, sprintf('%d lines', count($lines)));

$wordsIn = 0;
foreach ($runs as $r) { $wordsIn += count(array_filter(preg_split('/\s+/', trim($r->text)))); }
$wordsOut = 0;
foreach ($lines as $l) { $wordsOut += count(array_filter($l->items, fn($i) => !$i->isSpace)); }
ok('no words lost or duplicated when wrapping across runs',
    $wordsIn === $wordsOut, "$wordsOut of $wordsIn");

// 4. text-align ------------------------------------------------------
$runs = [new InlineRun('short line', 10.0)];
$left   = $inline->format($runs, 300.0, 'left')[0];
$right  = $inline->format($runs, 300.0, 'right')[0];
$center = $inline->format($runs, 300.0, 'center')[0];

ok('text-align: left starts at 0', abs($left->items[0]->x) < 0.001);
ok('text-align: right ends flush with the right edge',
    abs((end($right->items)->x + end($right->items)->width) - 300.0) < 0.01);
ok('text-align: center is symmetric',
    abs($center->items[0]->x - (300.0 - $center->width) / 2) < 0.01);

// 5. Justify ---------------------------------------------------------
$runs = [new InlineRun(str_repeat('alpha beta gamma delta ', 6), 10.0)];
$just = $inline->format($runs, 200.0, 'justify');
$nonLastFlush = true;
for ($i = 0; $i < count($just) - 1; $i++) {
    $w_ = array_values(array_filter($just[$i]->items, fn($i2) => !$i2->isSpace));
    $last = $w_[count($w_) - 1];
    if (abs(($last->x + $last->width) - 200.0) > 0.01) { $nonLastFlush = false; }
}
ok('justify: every line but the last is flush right', $nonLastFlush,
    sprintf('%d lines', count($just)));

$lastLine = $just[count($just) - 1];
$lastWords = array_values(array_filter($lastLine->items, fn($i) => !$i->isSpace));
$lastItem = $lastWords[count($lastWords) - 1];
ok('justify: the last line is NOT stretched',
    ($lastItem->x + $lastItem->width) < 200.0 - 0.01);

// 6. Whitespace collapsing ------------------------------------------
$runs = [new InlineRun("one    two\n\nthree\tfour", 10.0)];
$lines = $inline->format($runs, 500.0);
$oneSpace = $reg->stringWidth('one two three four', 10.0);
ok('whitespace collapses per white-space: normal',
    abs($lines[0]->width - $oneSpace) < 0.01, sprintf('%.3fpt', $lines[0]->width));

// 7. Integration with flex ------------------------------------------
$para = new Node(['display' => 'text', 'textAlign' => 'justify', 'runs' => [
    new InlineRun('Invoice ', 11.0, true),
    new InlineRun('#2026-0417 is due on ', 9.0, false),
    new InlineRun('30 August 2026', 9.0, true, [0.14, 0.40, 0.85]),
    new InlineRun('. Please remit within thirty days of receipt to the account below.', 9.0, false),
]]);
$root = new Node(['display' => 'flex', 'width' => 240.0], [$para]);
(new FlexLayout())->layout($root, 240.0, 400.0);

$heightFromBoxes = 0.0;
foreach ($para->lineBoxes as $lb) { $heightFromBoxes += $lb->height; }
ok('flex item height comes from the inline formatting context',
    count($para->lineBoxes) > 1 && abs($para->layoutHeight - $heightFromBoxes) < 0.01,
    sprintf('%d lines, %.2fpt', count($para->lineBoxes), $para->layoutHeight));

$mixedLine = null;
foreach ($para->lineBoxes as $lb) {
    $sizes = array_unique(array_map(fn($i) => $i->run->fontSize, array_filter($lb->items, fn($i) => !$i->isSpace)));
    if (count($sizes) > 1) { $mixedLine = $lb; break; }
}
ok('a line mixing 11pt and 9pt runs is taller than a 9pt-only line',
    $mixedLine !== null && $mixedLine->height > 9.0 * 1.35 + 0.01,
    $mixedLine ? sprintf('%.2fpt vs %.2fpt', $mixedLine->height, 9.0 * 1.35) : 'none');


// 8. Where half-leading actually diverges from the naive model ---------
// One run contributes the tallest ascent, a different run the deepest
// descent. max(size x line-height) cannot express that; half-leading can.
$runs = [
    new InlineRun('Tall', 20.0, true, [0,0,0], 0.8),   // tight leading, big font
    new InlineRun('deep', 9.0, false, [0,0,0], 1.8),   // loose leading, small font
];
$lb = $inline->format($runs, 500.0)[0];
$naive = max(20.0 * 0.8, 9.0 * 1.8);
[$above20, $below20] = $bold->lineBand(20.0, 0.8 * 20.0);
[$above9, $below9]   = $reg->lineBand(9.0, 1.8 * 9.0);
$correct = max($above20, $above9) + max($below20, $below9);

ok('half-leading diverges from max(size x line-height) when runs differ',
    abs($lb->height - $correct) < 0.001 && abs($lb->height - $naive) > 0.5,
    sprintf('%.2fpt correct vs %.2fpt naive', $lb->height, $naive));

ok('the tall run sets the ascent side, the loose run the descent side',
    abs($lb->baseline - $above20) < 0.001,
    sprintf('baseline %.2fpt', $lb->baseline));

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
