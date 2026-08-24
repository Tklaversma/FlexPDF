<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\Font;
use FlexPDF\Engine\Node;
use FlexPDF\Engine\FlexLayout;

$pass = 0; $fail = 0;

function check(string $name, array $got, array $want): void
{
    global $pass, $fail;
    $ok = true;
    foreach ($want as $i => $w) {
        foreach ($w as $k => $v) {
            if (abs($got[$i][$k] - $v) > 0.01) { $ok = false; }
        }
    }
    if ($ok) {
        $pass++;
        printf("  \033[32mPASS\033[0m  %s\n", $name);
    } else {
        $fail++;
        printf("  \033[31mFAIL\033[0m  %s\n", $name);
        printf("        want %s\n", json_encode($want));
        printf("        got  %s\n", json_encode($got));
    }
}

function run(Node $root, float $w = 300, float $h = 300): array
{
    (new FlexLayout())->layout($root, $w, $h);
    return array_map(fn(Node $n) => $n->rect(), $root->children);
}

function box(array $s = []): Node { return new Node($s + ['display' => 'rect']); }

echo "\nFlexbox conformance, expected values derived from CSS Flexible Box Level 1 §9\n\n";

// 1 ---------------------------------------------------------------
check('justify-content: space-between',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0,'justifyContent'=>'space-between'], [
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
    ])),
    [['x'=>0,'w'=>50], ['x'=>125,'w'=>50], ['x'=>250,'w'=>50]]
);

// 2 ---------------------------------------------------------------
check('flex-grow: 1 1 1, basis 0',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0], [
        box(['flexGrow'=>1.0,'flexBasis'=>0.0,'height'=>20.0]),
        box(['flexGrow'=>1.0,'flexBasis'=>0.0,'height'=>20.0]),
        box(['flexGrow'=>1.0,'flexBasis'=>0.0,'height'=>20.0]),
    ])),
    [['x'=>0,'w'=>100], ['x'=>100,'w'=>100], ['x'=>200,'w'=>100]]
);

// 3 ---------------------------------------------------------------
check('flex-grow: 1 2 1, basis 0',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0], [
        box(['flexGrow'=>1.0,'flexBasis'=>0.0,'height'=>20.0]),
        box(['flexGrow'=>2.0,'flexBasis'=>0.0,'height'=>20.0]),
        box(['flexGrow'=>1.0,'flexBasis'=>0.0,'height'=>20.0]),
    ])),
    [['x'=>0,'w'=>75], ['x'=>75,'w'=>150], ['x'=>225,'w'=>75]]
);

// 4 ---------------------------------------------------------------
check('flex-shrink: proportional to base x factor',
    run(new Node(['display'=>'flex','width'=>100.0,'height'=>100.0], [
        box(['flexBasis'=>60.0,'flexShrink'=>1.0,'height'=>20.0]),
        box(['flexBasis'=>60.0,'flexShrink'=>1.0,'height'=>20.0]),
        box(['flexBasis'=>60.0,'flexShrink'=>1.0,'height'=>20.0]),
    ]), 100.0),
    [['w'=>33.333], ['w'=>33.333], ['w'=>33.333]]
);

// 5 ---------------------------------------------------------------
check('min-width violation forces a second pass',
    run(new Node(['display'=>'flex','width'=>100.0,'height'=>100.0], [
        box(['flexBasis'=>100.0,'flexShrink'=>1.0,'minWidth'=>60.0,'height'=>20.0]),
        box(['flexBasis'=>100.0,'flexShrink'=>1.0,'height'=>20.0]),
    ]), 100.0),
    [['w'=>60], ['w'=>40]]
);

// 6 ---------------------------------------------------------------
check('align-items: center',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0,'alignItems'=>'center'], [
        box(['width'=>50.0,'height'=>40.0]),
    ])),
    [['y'=>30,'h'=>40]]
);

// 7 ---------------------------------------------------------------
check('gap: 10',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0,'gap'=>10.0], [
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
    ])),
    [['x'=>0], ['x'=>60], ['x'=>120]]
);

// 8 ---------------------------------------------------------------
check('flex-direction: column + space-between',
    run(new Node(['display'=>'flex','flexDirection'=>'column','width'=>200.0,'height'=>300.0,'justifyContent'=>'space-between'], [
        box(['width'=>50.0,'height'=>50.0]),
        box(['width'=>50.0,'height'=>50.0]),
        box(['width'=>50.0,'height'=>50.0]),
    ])),
    [['y'=>0], ['y'=>125], ['y'=>250]]
);

// 9 ---------------------------------------------------------------
check('flex-wrap: wrap onto a second line',
    run(new Node(['display'=>'flex','flexWrap'=>'wrap','width'=>100.0,'alignItems'=>'flex-start'], [
        box(['width'=>40.0,'height'=>20.0]),
        box(['width'=>40.0,'height'=>20.0]),
        box(['width'=>40.0,'height'=>20.0]),
    ]), 100.0),
    [['x'=>0,'y'=>0], ['x'=>40,'y'=>0], ['x'=>0,'y'=>20]]
);

// 10 --------------------------------------------------------------
check('flex-basis: 50%',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0], [
        box(['flexBasis'=>'50%','flexGrow'=>0.0,'flexShrink'=>0.0,'height'=>20.0]),
    ])),
    [['w'=>150]]
);

// 11 --------------------------------------------------------------
check('justify-content: space-around',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0,'justifyContent'=>'space-around'], [
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
        box(['width'=>50.0,'height'=>20.0]),
    ])),
    [['x'=>25], ['x'=>125], ['x'=>225]]
);

// 12 --------------------------------------------------------------
check('flex-grow < 1 distributes only that fraction',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0], [
        box(['flexBasis'=>100.0,'flexGrow'=>0.5,'height'=>20.0]),
    ])),
    [['w'=>200]]
);

// 13 --------------------------------------------------------------
check('align-items: stretch (default)',
    run(new Node(['display'=>'flex','width'=>300.0,'height'=>100.0], [
        box(['width'=>50.0]),
    ])),
    [['h'=>100]]
);

// 14 --------------------------------------------------------------
$font = new Font();
$w = $font->stringWidth('Hello', 12.0);
printf(
    "  %s  text metrics: \"Hello\" @12pt Helvetica = %.3fpt (AFM says %.3f)\n",
    abs($w - 27.336) < 0.001 ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    $w, 27.336
);
abs($w - 27.336) < 0.001 ? $pass++ : $fail++;

// 15 --------------------------------------------------------------
$para = new Node([
    'display'=>'text',
    'text'=>'The quick brown fox jumps over the lazy dog and keeps on running',
    'fontSize'=>12.0,
]);
$root = new Node(['display'=>'flex','width'=>200.0], [$para]);
(new FlexLayout())->layout($root, 200.0, 400.0);
$lines = $font->wrap($para->text, 12.0, 200.0);

// Greedy-wrap invariants: every line fits, no line could have taken one more
// word, and the flex item's height follows from the line count.
$ok = true;
foreach ($lines as $i => $line) {
    if ($font->stringWidth($line, 12.0) > 200.0) { $ok = false; }
    if (isset($lines[$i + 1])) {
        $next = strtok($lines[$i + 1], ' ');
        if ($font->stringWidth($line . ' ' . $next, 12.0) <= 200.0) { $ok = false; }
    }
}
if (abs($para->layoutHeight - count($lines) * 12 * 1.35) > 0.01) { $ok = false; }

printf(
    "  %s  text wrapping inside a flex item: %d lines, item height %.1fpt\n",
    $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    count($lines), $para->layoutHeight
);
$ok ? $pass++ : $fail++;


// 16 --------------------------------------------------------------
// Regression: the intrinsic-sizing shortcut returns a width but no line
// boxes. If that partial result gets cached, the real layout pass is
// skipped and the text silently disappears.
$leaf = new Node(['display' => 'text', 'text' => 'text must survive the measure pass', 'fontSize' => 10.0]);
$wrap = new Node(['display' => 'flex', 'width' => 400.0], [$leaf]);
(new FlexLayout())->layout($wrap, 400.0, 200.0);
printf(
    "  %s  intrinsic measure pass does not poison the layout cache  (%d line boxes, h=%.2f)\n",
    count($leaf->lineBoxes) > 0 && $leaf->layoutHeight > 0
        ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    count($leaf->lineBoxes), $leaf->layoutHeight
);
count($leaf->lineBoxes) > 0 && $leaf->layoutHeight > 0 ? $pass++ : $fail++;

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
