<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$opts = getopt('', [
    'out::',
    'phpss-cache::',
    'cases::',
    'php::',
]);

$resultsDir = bench_results_dir();
$tmpDir     = bench_tmp_dir();

$outName = trim((string)($opts['out'] ?? 'results.jsonl'));
$outName = $outName !== '' ? $outName : 'results.jsonl';
if (!preg_match('~\.jsonl$~i', $outName)) $outName .= '.jsonl';
$outFile = $resultsDir . DIRECTORY_SEPARATOR . basename($outName);

$phpssCache = trim((string)($opts['phpss-cache'] ?? 'none'));
$phpssCache = $phpssCache !== '' ? $phpssCache : 'none';

$cases = bench_parse_cases((string)($opts['cases'] ?? ''));
if (!$cases) {
    $cases = [
        [1000, 10],
        [2000, 50],
        [2000, 100],
        [5000, 20],
        [5000, 100],
    ];
}

$phpBin = trim((string)($opts['php'] ?? PHP_BINARY));
$phpBin = $phpBin !== '' ? $phpBin : PHP_BINARY;

$runner = bench_path('console/runner.php');
if (!is_file($runner)) {
    fwrite(STDERR, "[FATAL] runner.php not found: {$runner}\n");
    exit(2);
}

echo "BASE_PATH: " . BASE_PATH . PHP_EOL;
echo "Results:   {$outFile}" . PHP_EOL;
echo "Tmp:       {$tmpDir}" . PHP_EOL;
echo "PhpSS cache: {$phpssCache}" . PHP_EOL;
echo "Cases:     " . implode(', ', array_map(fn($c) => "{$c[0]}x{$c[1]}", $cases)) . PHP_EOL;
echo PHP_EOL;

$writeLibs = ['fastexcel', 'phpspreadsheet', 'openspout'];
$readLibs  = ['fastexcelreader', 'phpspreadsheet', 'openspout'];
$readWriter = 'fastexcel';

$writtenFilesByCase = [];

foreach ($cases as [$rows, $cols]) {
    $caseKey = "{$rows}x{$cols}";
    echo "== CASE {$caseKey} ==" . PHP_EOL;

    foreach ($writeLibs as $lib) {
        $outXlsx = $tmpDir . DIRECTORY_SEPARATOR . bench_make_tmp_name("bench_{$lib}_{$caseKey}") . '.xlsx';

        $args = [
            '--mode=write',
            '--lib=' . $lib,
            '--rows=' . (string)$rows,
            '--cols=' . (string)$cols,
            '--out=' . $outXlsx,
        ];

        if ($lib === 'phpspreadsheet') {
            $args[] = '--phpss-cache=' . $phpssCache;
        }

        $resultLine = bench_run_runner($phpBin, $runner, $args);
        bench_append_jsonl($outFile, $resultLine);

        $decoded = json_decode($resultLine, true);
        $ok = is_array($decoded) ? (bool)($decoded['ok'] ?? false) : false;

        if ($ok && $lib === 'fastexcel') {
            $writtenFilesByCase[$caseKey] = (string)($decoded['out'] ?? $outXlsx);
        }

        $status = $ok ? '[OK]  ' : '[FAIL]';
        echo "{$status} write {$lib} {$caseKey}" . PHP_EOL;
        if (!$ok && is_array($decoded)) {
            echo "       " . ($decoded['error'] ?? 'Unknown error') . PHP_EOL;
        }
    }

    echo PHP_EOL;
}

// READ BENCH
echo "== READ BENCH (reading files created by {$readWriter}) ==" . PHP_EOL;

foreach ($cases as [$rows, $cols]) {
    $caseKey = "{$rows}x{$cols}";
    $inFile = $writtenFilesByCase[$caseKey] ?? null;

    if (!$inFile || !is_file($inFile)) {
        echo "[SKIP] read {$caseKey} (no input file from {$readWriter})" . PHP_EOL;
        continue;
    }

    foreach ($readLibs as $lib) {
        $args = [
            '--mode=read',
            '--lib=' . $lib,
            '--rows=' . (string)$rows,
            '--cols=' . (string)$cols,
            '--in=' . $inFile,
            '--writer=' . $readWriter,
        ];

        if ($lib === 'phpspreadsheet') {
            $args[] = '--phpss-cache=' . $phpssCache;
        }

        $resultLine = bench_run_runner($phpBin, $runner, $args);
        bench_append_jsonl($outFile, $resultLine);

        $decoded = json_decode($resultLine, true);
        $ok = is_array($decoded) ? (bool)($decoded['ok'] ?? false) : false;

        $status = $ok ? '[OK]  ' : '[FAIL]';
        echo "{$status} read {$lib} {$caseKey}" . PHP_EOL;
        if (!$ok && is_array($decoded)) {
            echo "       " . ($decoded['error'] ?? 'Unknown error') . PHP_EOL;
        }
    }
}

echo PHP_EOL . "Done. Results saved to: {$outFile}" . PHP_EOL;
