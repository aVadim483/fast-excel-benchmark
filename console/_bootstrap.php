<?php
declare(strict_types=1);

/**
 * console/_bootstrap.php
 * Shared bootstrap + helpers for console scripts.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
}

$autoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

function bench_path(string $relative): string
{
    $relative = ltrim($relative, "/\\");
    return BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function bench_ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function bench_results_dir(): string
{
    $dir = bench_path('results');
    bench_ensure_dir($dir);
    return $dir;
}

function bench_tmp_dir(): string
{
    $dir = bench_path('tmp');
    bench_ensure_dir($dir);
    return $dir;
}

function bench_read_jsonl(string $file): array
{
    $out = [];
    $fh = fopen($file, 'rb');
    if (!$fh) return $out;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $j = json_decode($line, true);
        if (is_array($j)) $out[] = $j;
    }
    fclose($fh);
    return $out;
}

function bench_append_jsonl(string $file, string $line): void
{
    file_put_contents($file, rtrim($line) . "\n", FILE_APPEND);
}

function bench_make_tmp_name(string $prefix): string
{
    return $prefix . '_' . substr(sha1($prefix . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 12);
}

function bench_parse_cases(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];

    $parts = preg_split('~\s*,\s*~', $raw) ?: [];
    $cases = [];

    foreach ($parts as $p) {
        if (!preg_match('~^(\d+)x(\d+)$~i', trim($p), $m)) continue;
        $r = (int)$m[1];
        $c = (int)$m[2];
        if ($r > 0 && $c > 0) $cases[] = [$r, $c];
    }

    return $cases;
}

function bench_run_process(string $cmd, ?string $cwd = null): array
{
    $descriptors = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, $cwd ?? BASE_PATH);

    if (!is_resource($proc)) {
        return [false, 127, '', 'Failed to start process'];
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($proc);
    return [true, $exit, $stdout, $stderr];
}

/**
 * Run runner.php and return one JSON line (or an error JSON line).
 */
function bench_run_runner(string $phpBin, string $runnerPath, array $args): string
{
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($runnerPath);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    [$ok, $exit, $stdout, $stderr] = bench_run_process($cmd, BASE_PATH);

    $stdout = trim($stdout);

    if (!$ok || $exit !== 0 || $stdout === '') {
        return json_encode([
            'ts' => date('c'),
            'ok' => false,
            'error' => 'Runner failed. Exit code: ' . $exit,
            'raw' => $stdout !== '' ? $stdout : trim($stderr),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $stdout;
}

function bench_resolve_in_results(string $opt, string $resultsDir, string $default = 'results.jsonl'): string
{
    $opt = trim($opt);
    if ($opt === '') $opt = $default;

    $looksAbsWin = preg_match('~^[A-Za-z]:\\\\~', $opt) === 1;
    $looksAbsNix = str_starts_with($opt, '/');
    $hasDir = str_contains($opt, '/') || str_contains($opt, '\\');

    if ($looksAbsWin || $looksAbsNix || $hasDir) {
        return $opt;
    }

    return $resultsDir . DIRECTORY_SEPARATOR . $opt;
}

function bench_format_metric_cell(?array $r): string
{
    if (!$r) return '—';
    if (!($r['ok'] ?? false)) return 'FAIL';
    $ms  = (int)($r['time_ms'] ?? -1);
    $mem = number_format((float)($r['peak_mem_mb'] ?? 0), 1);
    return "{$ms}ms / {$mem}MB";
}

function bench_print_console_table(array $byCase, array $libOrder): void
{
    $header = array_merge(['case'], $libOrder);
    $widths = array_fill_keys($header, 22);
    $widths['case'] = 12;

    echo bench_format_row(array_combine($header, array_map('strtoupper', $header)), $widths);
    echo str_repeat('-', 12 + 22 * count($libOrder)) . "\n";

    foreach ($byCase as $case => $libs) {
        $line = ['case' => $case];
        foreach ($libOrder as $lib) {
            $line[$lib] = bench_format_metric_cell($libs[$lib] ?? null);
        }
        echo bench_format_row($line, $widths);
    }
    echo "\n";
}

function bench_format_row(array $cols, array $widths): string
{
    $out = '';
    foreach ($cols as $k => $v) {
        $w = $widths[$k] ?? 20;
        $out .= str_pad((string)$v, $w);
    }
    return rtrim($out) . "\n";
}

function bench_group_write(array $rows): array
{
    $byCase = [];
    foreach ($rows as $r) {
        $case = ($r['rows'] ?? '?') . 'x' . ($r['cols'] ?? '?');
        $lib  = $r['lib'] ?? 'unknown';
        $byCase[$case][$lib] = $r;
    }
    ksort($byCase, SORT_NATURAL);
    return $byCase;
}

function bench_group_read(array $rows): array
{
    $byGroup = [];
    foreach ($rows as $r) {
        $writer = $r['writer'] ?? 'unknown';
        $case = ($r['rows'] ?? '?') . 'x' . ($r['cols'] ?? '?');
        $lib  = $r['lib'] ?? 'unknown';
        $byGroup[$writer][$case][$lib] = $r;
    }
    foreach ($byGroup as $writer => $cases) {
        ksort($cases, SORT_NATURAL);
        $byGroup[$writer] = $cases;
    }
    ksort($byGroup, SORT_NATURAL);
    return $byGroup;
}

function bench_split_by_mode(array $rows): array
{
    $w = [];
    $r = [];
    foreach ($rows as $x) {
        $mode = $x['mode'] ?? null;
        if ($mode === 'write') $w[] = $x;
        elseif ($mode === 'read') $r[] = $x;
    }
    return [$w, $r];
}

function bench_render_markdown_table(array $byCase, array $libOrder): string
{
    $lines = [];
    $lines[] = '| case | ' . implode(' | ', $libOrder) . ' |';
    $lines[] = '|---|' . str_repeat('---|', count($libOrder));

    foreach ($byCase as $case => $libs) {
        $cells = [];
        foreach ($libOrder as $lib) {
            $cells[] = str_replace('|', '\|', bench_format_metric_cell($libs[$lib] ?? null));
        }
        $lines[] = '| ' . str_replace('|', '\|', (string)$case) . ' | ' . implode(' | ', $cells) . ' |';
    }

    return implode("\n", $lines);
}
