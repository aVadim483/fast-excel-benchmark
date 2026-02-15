<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$opts = getopt('', ['in::', 'md::']);

$resultsDir = bench_results_dir();

$inOpt = (string)($opts['in'] ?? 'results.jsonl');
$inFile = bench_resolve_in_results($inOpt, $resultsDir, 'results.jsonl');

if (!is_file($inFile)) {
    fwrite(STDERR, "Results file not found: {$inFile}\n");
    exit(1);
}

$mdEnabled = array_key_exists('md', $opts);
$mdOptRaw  = $opts['md'] ?? null;

$mdFile = null;
if ($mdEnabled) {
    if ($mdOptRaw === false || $mdOptRaw === null || trim((string)$mdOptRaw) === '') {
        $mdFile = bench_resolve_in_results('report.md', $resultsDir, 'report.md');
    } else {
        $mdFile = bench_resolve_in_results((string)$mdOptRaw, $resultsDir, 'report.md');
    }
}

$rows = bench_read_jsonl($inFile);
if (!$rows) {
    echo "No results found in {$inFile}\n";
    exit(0);
}

echo "Results file: {$inFile}\n\n";

[$writeRows, $readRows] = bench_split_by_mode($rows);

if ($writeRows) {
    echo "=== WRITE BENCH ===\n\n";
    $byCase = bench_group_write($writeRows);
    bench_print_console_table($byCase, ['fastexcel', 'phpspreadsheet', 'openspout']);
}

if ($readRows) {
    echo "\n=== READ BENCH ===\n\n";
    $byGroup = bench_group_read($readRows);
    foreach ($byGroup as $writer => $cases) {
        echo "--- Files created by: {$writer} ---\n\n";
        bench_print_console_table($cases, ['fastexcelreader', 'phpspreadsheet', 'openspout']);
        echo "\n";
    }
}

echo "Done.\n";

if ($mdEnabled && $mdFile) {
    @mkdir(dirname($mdFile), 0777, true);

    $md = [];
    $md[] = '# XLSX Benchmark Report';
    $md[] = '';
    $md[] = '- Source: `' . basename($inFile) . '`';
    $md[] = '- Generated: `' . date('c') . '`';
    $md[] = '';

    if ($writeRows) {
        $md[] = '## Write benchmark';
        $md[] = '';
        $byCase = bench_group_write($writeRows);
        $md[] = bench_render_markdown_table($byCase, ['fastexcel', 'phpspreadsheet', 'openspout']);
        $md[] = '';
    }

    if ($readRows) {
        $md[] = '## Read benchmark';
        $md[] = '';
        $byGroup = bench_group_read($readRows);

        foreach ($byGroup as $writer => $cases) {
            $md[] = '### Reading files created by `' . $writer . '`';
            $md[] = '';
            $md[] = bench_render_markdown_table($cases, ['fastexcelreader', 'phpspreadsheet', 'openspout']);
            $md[] = '';
        }
    }

    $md[] = '---';
    $md[] = 'Cell format: `time_ms / peak_mem_mb`';
    $md[] = '';

    file_put_contents($mdFile, implode("\n", $md));
    echo "\nMarkdown saved to: {$mdFile}\n";
}
