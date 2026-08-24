<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\{Node, FlexLayout};

function buildDoc(int $rows): Node
{
    $children = [];
    for ($i = 0; $i < $rows; $i++) {
        $children[] = new Node(['display'=>'flex','flexDirection'=>'row','gap'=>8.0,'alignItems'=>'center'], [
            new Node(['display'=>'text','text'=>"Line item number $i with a reasonably long description",'fontSize'=>9.0,'flexGrow'=>1.0,'flexBasis'=>0.0]),
            new Node(['display'=>'text','text'=>'12 h','fontSize'=>9.0,'width'=>60.0]),
            new Node(['display'=>'text','text'=>'1,440.00','fontSize'=>9.0,'width'=>80.0]),
            new Node(['display'=>'rect','width'=>40.0,'height'=>10.0,'flexShrink'=>0.0]),
        ]);
    }
    return new Node(['display'=>'flex','flexDirection'=>'column','width'=>495.0], $children);
}

echo "\n  rows    nodes    layout      per-node    ~pages\n";
echo "  " . str_repeat('-', 52) . "\n";

foreach ([10, 100, 500, 2000, 10000] as $rows) {
    $doc = buildDoc($rows);

    $count = 0;
    $walk = function (Node $n) use (&$walk, &$count) { $count++; foreach ($n->children as $c) { $walk($c); } };
    $walk($doc);

    // warm up, then measure
    (new FlexLayout())->layout(buildDoc($rows), 495.0, 742.0);

    $t = microtime(true);
    (new FlexLayout())->layout($doc, 495.0, 742.0);
    $ms = (microtime(true) - $t) * 1000;

    printf("  %-7d %-8d %8.1f ms  %7.3f ms   %6.0f\n",
        $rows, $count, $ms, $ms / $count, $doc->layoutHeight / 742.0);
}

printf("\n  peak memory: %.1f MB\n\n", memory_get_peak_usage(true) / 1048576);
