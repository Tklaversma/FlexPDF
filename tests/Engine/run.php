<?php
declare(strict_types=1);

/*
 * The engine suites predate the package and are standalone scripts rather
 * than Pest tests, each with top-level code and its own constants. They run
 * as separate processes for that reason, and are excluded from phpunit.xml
 * so Pest never tries to collect them.
 */

const SUITES = [
    'conformance',
    'inline',
    'fonts',
    'css',
    'tables',
    'features',
    'grid',
    'effects',
    'regressions',
];

$php = PHP_BINARY;
$failed = [];
$totalPassed = 0;

foreach (SUITES as $suite) {
    $script = __DIR__ . '/' . $suite . '.php';

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([$php, $script], $descriptors, $pipes);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $passed = 0;
    if (preg_match('/(\d+) passed, (\d+) failed/', $stdout, $m) === 1) {
        $passed = (int) $m[1];
        $totalPassed += $passed;

        if ((int) $m[2] > 0) {
            $failed[$suite] = $m[2] . ' failed';
        }
    }

    if ($status !== 0 && !isset($failed[$suite])) {
        $failed[$suite] = 'exited ' . $status;
    }

    if ($passed === 0 && !isset($failed[$suite])) {
        $failed[$suite] = 'no results parsed';
    }

    printf("  %-13s %s\n", $suite, $failed[$suite] ?? $passed . ' passed');

    if (isset($failed[$suite])) {
        echo $stdout, $stderr;
    }
}

echo str_repeat('-', 40), "\n";

if ($failed !== []) {
    printf("FAILED: %s\n", implode(', ', array_keys($failed)));
    exit(1);
}

printf("%d passed across %d suites\n", $totalPassed, count(SUITES));
exit(0);
