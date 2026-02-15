<?php
declare(strict_types=1);

/**
 * public/index.php
 *
 * HTML-страница для просмотра результатов бенчмарка из папки /results (JSONL).
 * Включает:
 * - таблицы WRITE/READ
 * - метрики скорости (cells/s и rows/s)
 * - диаграммы Chart.js: time(ms), peak mem(MB), speed(cells/s), relative speed(x baseline)
 * - переключатель Y-scale: linear/log
 * - экспорт PNG и SVG (SVG = wrapper с embedded PNG, максимально совместимо)
 */

$rootDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$resultsDir = $rootDir . DIRECTORY_SEPARATOR . 'results';

if (!is_dir($resultsDir)) {
    http_response_code(500);
    echo "Results directory not found: " . htmlspecialchars($resultsDir, ENT_QUOTES, 'UTF-8');
    exit;
}

$files = listResultFiles($resultsDir);
if (!$files) {
    echo renderPage('Benchmark results', renderEmpty("No *.jsonl files found in /results"));
    exit;
}

$selected = isset($_GET['file']) ? (string)$_GET['file'] : '';
$selected = sanitizeFileName($selected);

// pick default: newest by mtime
if ($selected === '' || !isset($files[$selected])) {
    $selected = array_key_first($files);
}

$hideMissing = isset($_GET['hide_missing']) ? (bool)$_GET['hide_missing'] : false;
$hideFail = isset($_GET['hide_fail']) ? (bool)$_GET['hide_fail'] : false;

$selectedPath = $resultsDir . DIRECTORY_SEPARATOR . $selected;
if (!is_file($selectedPath)) {
    http_response_code(404);
    echo renderPage('Benchmark results', renderEmpty("Selected file not found: " . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8')));
    exit;
}

$rows = readJsonl($selectedPath);
[$writeRows, $readRows] = splitByMode($rows);

$writeGrouped = groupWrite($writeRows);
$readGrouped  = groupRead($readRows);

$chartPayload = buildChartsPayload($writeGrouped, $readGrouped);

$content = '';
$content .= renderHeader($selected, $files, $hideMissing, $hideFail);

$content .= renderCharts($chartPayload);

$content .= '<div class="section">';
$content .= '<h2>Write benchmark</h2>';
$content .= $writeRows
    ? renderTable($writeGrouped, ['fastexcel', 'phpspreadsheet', 'openspout'], 'fastexcel', $hideMissing, $hideFail)
    : renderEmpty('No write results in this file.');
$content .= '</div>';

$content .= '<div class="section">';
$content .= '<h2>Read benchmark</h2>';
if (!$readRows) {
    $content .= renderEmpty('No read results in this file.');
} else {
    foreach ($readGrouped as $writer => $cases) {
        $content .= '<h3>Reading files created by: <code>' . e($writer) . '</code></h3>';
        $content .= renderTable($cases, ['fastexcelreader', 'phpspreadsheet', 'openspout'], 'fastexcelreader', $hideMissing, $hideFail);
    }
}
$content .= '</div>';

$content .= renderFooter($selectedPath);

echo renderPage('Benchmark results', $content);


/* ============================ IO/helpers ============================ */

function listResultFiles(string $resultsDir): array
{
    $items = glob($resultsDir . DIRECTORY_SEPARATOR . '*.jsonl') ?: [];
    $map = [];
    foreach ($items as $path) {
        $name = basename($path);
        $map[$name] = [
            'path' => $path,
            'mtime' => @filemtime($path) ?: 0,
            'size' => @filesize($path) ?: 0,
        ];
    }

    // sort by mtime desc, newest first
    uasort($map, static function($a, $b) {
        return ($b['mtime'] <=> $a['mtime']) ?: (($b['size'] ?? 0) <=> ($a['size'] ?? 0));
    });

    return $map;
}

function sanitizeFileName(string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    if (preg_match('~^[A-Za-z0-9._-]+$~', $name) !== 1) return '';
    if (!str_ends_with(strtolower($name), '.jsonl')) return '';
    return $name;
}

function readJsonl(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'rb');
    if (!$fh) return $rows;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $decoded = json_decode($line, true);
        if (is_array($decoded)) $rows[] = $decoded;
    }
    fclose($fh);
    return $rows;
}

function splitByMode(array $rows): array
{
    $write = [];
    $read = [];
    foreach ($rows as $r) {
        $mode = $r['mode'] ?? null;
        if ($mode === 'write') $write[] = $r;
        elseif ($mode === 'read') $read[] = $r;
    }
    return [$write, $read];
}

function groupWrite(array $writeRows): array
{
    $byCase = [];
    foreach ($writeRows as $r) {
        $case = ($r['rows'] ?? '?') . 'x' . ($r['cols'] ?? '?');
        $lib  = $r['lib'] ?? 'unknown';
        $byCase[$case][$lib] = $r;
    }
    ksort($byCase, SORT_NATURAL);
    return $byCase;
}

function groupRead(array $readRows): array
{
    $byGroup = [];
    foreach ($readRows as $r) {
        $writer = (string)($r['writer'] ?? 'unknown');
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

/* ============================ metrics ============================ */

function metricCell(?array $r): array
{
    if (!$r) return ['text' => '—', 'class' => 'muted'];
    if (!($r['ok'] ?? false)) return ['text' => 'FAIL', 'class' => 'fail'];

    $ms  = (int)($r['time_ms'] ?? -1);
    $mem = number_format((float)($r['peak_mem_mb'] ?? 0), 1);

    $sec = ($ms > 0) ? ($ms / 1000.0) : 0.0;

    [$rowsTotal, $cellsTotal] = calcWorkload($r);

    $cellsPerSec = ($sec > 0 && $cellsTotal > 0) ? ($cellsTotal / $sec) : 0.0;
    $rowsPerSec  = ($sec > 0 && $rowsTotal > 0)  ? ($rowsTotal  / $sec) : 0.0;

    $speedPart = '';
    if ($cellsPerSec > 0) {
        $speedPart = ' · ' . formatRate($cellsPerSec) . ' cells/s';
        $speedPart .= ' · ' . formatRate($rowsPerSec) . ' rows/s';
    }

    $text = "{$ms} ms · {$mem} MB{$speedPart}";
    return ['text' => $text, 'class' => 'ok'];
}

/**
 * @return array{0:int,1:int} [rowsTotal, cellsTotal]
 */
function calcWorkload(array $r): array
{
    $mode = (string)($r['mode'] ?? '');
    $rowsParam = (int)($r['rows'] ?? 0);
    $colsParam = (int)($r['cols'] ?? 0);

    // В данных генератора: header + $rows data rows
    $rowsTotal = $rowsParam > 0 ? ($rowsParam + 1) : 0;

    if ($mode === 'read') {
        $readRows  = isset($r['read_rows']) ? (int)$r['read_rows'] : 0;
        $readCells = isset($r['read_cells']) ? (int)$r['read_cells'] : 0;

        if ($readRows > 0) $rowsTotal = $readRows;

        $cellsTotal = ($readCells > 0)
            ? $readCells
            : (($rowsTotal > 0 && $colsParam > 0) ? $rowsTotal * $colsParam : 0);

        return [$rowsTotal, $cellsTotal];
    }

    $cellsTotal = ($rowsTotal > 0 && $colsParam > 0) ? $rowsTotal * $colsParam : 0;
    return [$rowsTotal, $cellsTotal];
}

function formatRate(float $value): string
{
    if ($value >= 1_000_000_000) return formatNumber($value / 1_000_000_000) . 'G';
    if ($value >= 1_000_000)     return formatNumber($value / 1_000_000) . 'M';
    if ($value >= 1_000)         return formatNumber($value / 1_000) . 'k';
    return formatNumber($value);
}

function formatNumber(float $value): string
{
    if ($value >= 100) return number_format($value, 0, '.', '');
    if ($value >= 10)  return number_format($value, 1, '.', '');
    return number_format($value, 2, '.', '');
}

/* ============================ HTML parts ============================ */

function renderTable(array $byCase, array $libOrder, string $baselineLib, bool $hideMissing, bool $hideFail): string
{
    if (!$byCase) return renderEmpty('No rows.');

    $html = '<div class="table-wrap"><table><thead><tr>';
    $html .= '<th class="case">case</th>';
    foreach ($libOrder as $lib) {
        $title = $lib;
        if ($lib === $baselineLib) {
            $title .= ' (baseline)';
        }
        $html .= '<th>' . e($title) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($byCase as $case => $libs) {
        // baseline speed for this case (cells/s)
        $baselineSpeed = null;
        if (isset($libs[$baselineLib]) && ($libs[$baselineLib]['ok'] ?? false)) {
            $baselineSpeed = calcCellsPerSec($libs[$baselineLib]);
            if (!is_float($baselineSpeed) || $baselineSpeed <= 0) {
                $baselineSpeed = null;
            }
        }

        $cells = [];
        $allMissing = true;
        $allFailOrMissing = true;

        foreach ($libOrder as $lib) {
            $r = $libs[$lib] ?? null;
            $cell = metricCell($r);

            // добавляем относительную скорость в %
            if ($r && ($r['ok'] ?? false)) {
                $speed = calcCellsPerSec($r);

                if (is_float($speed) && $speed > 0 && $baselineSpeed) {
                    $pct = ($speed / $baselineSpeed) * 100.0;
                    // округление: 0 знаков после запятой обычно достаточно
                    $pctStr = number_format($pct, 0, '.', '');
                    $cell['text'] .= " · {$pctStr}%";
                } elseif ($lib === $baselineLib && $baselineSpeed) {
                    $cell['text'] .= " · 100%";
                }
            }

            $cells[$lib] = $cell;

            if ($cell['text'] !== '—') $allMissing = false;
            if ($cell['text'] !== '—' && $cell['text'] !== 'FAIL') $allFailOrMissing = false;
        }

        if ($hideMissing && $allMissing) continue;
        if ($hideFail && $allFailOrMissing) continue;

        $html .= '<tr>';
        $html .= '<td class="case"><code>' . e($case) . '</code></td>';
        foreach ($libOrder as $lib) {
            $cell = $cells[$lib];
            $html .= '<td class="' . e($cell['class']) . '">' . e($cell['text']) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    $html .= '<div class="legend">Legend: time (ms), peak memory (MB), speed (cells/s, rows/s), relative speed (% of baseline)</div>';

    return $html;
}

function renderHeader(string $selected, array $files, bool $hideMissing, bool $hideFail): string
{
    $html = '<div class="topbar">';
    $html .= '<div>';
    $html .= '<h1>Benchmark results</h1>';
    $html .= '<div class="sub">Source: <code>' . e($selected) . '</code></div>';
    $html .= '</div>';

    $html .= '<form method="get" class="controls">';
    $html .= '<label>File ';
    $html .= '<select name="file" onchange="this.form.submit()">';
    foreach ($files as $name => $meta) {
        $sel = ($name === $selected) ? ' selected' : '';
        $label = $name . ' · ' . formatBytes((int)$meta['size']) . ' · ' . date('Y-m-d H:i:s', (int)$meta['mtime']);
        $html .= '<option value="' . e($name) . '"' . $sel . '>' . e($label) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="chk"><input type="checkbox" name="hide_missing" value="1"' . ($hideMissing ? ' checked' : '') . ' onchange="this.form.submit()"> hide empty rows</label>';
    $html .= '<label class="chk"><input type="checkbox" name="hide_fail" value="1"' . ($hideFail ? ' checked' : '') . ' onchange="this.form.submit()"> hide FAIL-only rows</label>';

    $html .= '<noscript><button type="submit">Apply</button></noscript>';
    $html .= '</form>';

    $html .= '</div>';

    return $html;
}

function renderFooter(string $selectedPath): string
{
    $html = '<div class="footer">';
    $html .= '<div>File path: <code>' . e($selectedPath) . '</code></div>';
    $html .= '</div>';
    return $html;
}

function renderEmpty(string $msg): string
{
    return '<div class="empty">' . e($msg) . '</div>';
}

function renderPage(string $title, string $body): string
{
    $css = <<<CSS
:root { color-scheme: light dark; }
body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 24px; }
h1 { margin: 0 0 6px; font-size: 24px; }
h2 { margin: 0 0 12px; font-size: 18px; }
h3 { margin: 18px 0 10px; font-size: 15px; }
h4 { margin: 14px 0 10px; font-size: 13px; opacity: .92; }
.sub { opacity: .75; font-size: 13px; }
.topbar { display: flex; gap: 16px; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; margin-bottom: 18px; }
.controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
select { padding: 6px 8px; border-radius: 8px; border: 1px solid rgba(127,127,127,.35); }
.chk { font-size: 13px; opacity: .9; display: flex; gap: 6px; align-items: center; }
.section { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(127,127,127,.25); }
.table-wrap { overflow-x: auto; border: 1px solid rgba(127,127,127,.25); border-radius: 12px; }
table { border-collapse: collapse; width: 100%; min-width: 920px; }
th, td { padding: 10px 12px; border-bottom: 1px solid rgba(127,127,127,.18); text-align: left; font-size: 13px; vertical-align: top; }
th { position: sticky; top: 0; background: rgba(127,127,127,.08); backdrop-filter: blur(6px); }
td.case, th.case { width: 120px; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; }
.legend { margin-top: 8px; font-size: 12px; opacity: .75; }
.empty { padding: 14px 16px; border: 1px dashed rgba(127,127,127,.35); border-radius: 12px; opacity: .85; }
.muted { opacity: .55; }
.fail { color: #b00020; font-weight: 700; }
.footer { margin-top: 22px; padding-top: 12px; border-top: 1px solid rgba(127,127,127,.25); opacity: .75; font-size: 12px; }

.charts-controls { display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin: 10px 0 12px; }
.charts-controls .chk { margin: 0; }
.charts-grid { display: grid; grid-template-columns: repeat(2, minmax(280px, 1fr)); gap: 12px; margin: 12px 0 18px; }
.chart-card { height: 320px; border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 10px; position: relative; }
.chart-head { display:flex; justify-content: space-between; align-items:center; gap:10px; margin-bottom: 6px; }
.chart-title { font-size: 12px; opacity: .85; }
.chart-actions { display:flex; gap:8px; }
.btn { padding: 6px 10px; border-radius: 10px; border: 1px solid rgba(127,127,127,.35); background: rgba(127,127,127,.10); cursor: pointer; font-size: 12px; }
.btn:hover { background: rgba(127,127,127,.16); }
.chart-canvas { height: 270px; }
@media (max-width: 1000px) {
  .charts-grid { grid-template-columns: 1fr; }
  .chart-card { height: 300px; }
  .chart-canvas { height: 250px; }
}
CSS;

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . e($title) . "</title>\n<style>\n{$css}\n</style>\n</head>\n<body>\n{$body}\n</body>\n</html>";
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $kb = $bytes / 1024;
    if ($kb < 1024) return number_format($kb, 1) . ' KB';
    $mb = $kb / 1024;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    $gb = $mb / 1024;
    return number_format($gb, 2) . ' GB';
}

/* ============================ charts (payload) ============================ */

function buildChartsPayload(array $writeGrouped, array $readGrouped): array
{
    $payload = [
        'write' => buildGroupCharts($writeGrouped, ['fastexcel', 'phpspreadsheet', 'openspout']),
        'read' => [],
    ];

    foreach ($readGrouped as $writer => $cases) {
        $payload['read'][$writer] = buildGroupCharts($cases, ['fastexcelreader', 'phpspreadsheet', 'openspout']);
    }

    return $payload;
}

function buildGroupCharts(array $byCase, array $libOrder): array
{
    $labels = array_keys($byCase);

    $charts = [
        'time_ms' => ['labels' => $labels, 'datasets' => []],
        'peak_mem_mb' => ['labels' => $labels, 'datasets' => []],
        'cells_per_sec' => ['labels' => $labels, 'datasets' => []],
    ];

    foreach ($libOrder as $lib) {
        $time = [];
        $mem  = [];
        $cps  = [];

        foreach ($byCase as $case => $libs) {
            $r = $libs[$lib] ?? null;

            if (!$r || !($r['ok'] ?? false)) {
                $time[] = null;
                $mem[]  = null;
                $cps[]  = null;
                continue;
            }

            $ms = (int)($r['time_ms'] ?? 0);
            $peak = (float)($r['peak_mem_mb'] ?? 0);

            $time[] = $ms ?: null;
            $mem[]  = $peak ?: null;

            $cps[]  = calcCellsPerSec($r);
        }

        $charts['time_ms']['datasets'][] = ['label' => $lib, 'data' => $time];
        $charts['peak_mem_mb']['datasets'][] = ['label' => $lib, 'data' => $mem];
        $charts['cells_per_sec']['datasets'][] = ['label' => $lib, 'data' => $cps];
    }

    return $charts;
}

function calcCellsPerSec(array $r): ?float
{
    $ms = (int)($r['time_ms'] ?? 0);
    if ($ms <= 0) return null;

    $sec = $ms / 1000.0;

    [, $cellsTotal] = calcWorkload($r);
    if ($cellsTotal <= 0) return null;

    return $cellsTotal / $sec;
}

/* ============================ charts (render) ============================ */

function renderCharts(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = '<div class="section">';
    $html .= '<h2>Charts</h2>';
    $html .= '<div class="sub">Time (ms), Peak memory (MB), Speed (cells/s), and Relative speed (× baseline) per case.</div>';

    $html .= <<<HTML
<div class="charts-controls">
  <label class="chk">
    <input type="checkbox" id="yscaleLog">
    log scale on Y
  </label>
  <span class="sub">Baseline for relative speed: first dataset in legend (WRITE: <code>fastexcel</code>, READ: <code>fastexcelreader</code>).</span>
</div>
HTML;

    $html .= '<h3>WRITE</h3>';
    $html .= renderChartsBlock('write');

    $html .= '<h3>READ</h3>';
    if (empty($payload['read'])) {
        $html .= renderEmpty('No read results for charts.');
    } else {
        foreach (array_keys($payload['read']) as $writer) {
            $safeId = preg_replace('~[^a-z0-9_-]+~i', '_', (string)$writer);
            $html .= '<h4>Files created by <code>' . e($writer) . '</code></h4>';
            $html .= renderChartsBlock('read_' . $safeId);
        }
    }

    $html .= <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const PAYLOAD = $json;
window.CHARTS = window.CHARTS || {};

function makeLineChart(canvasId, title, labels, datasets, valueSuffix = '', yScaleType = 'linear') {
  const el = document.getElementById(canvasId);
  if (!el) return null;

  if (window.CHARTS[canvasId]) {
    window.CHARTS[canvasId].destroy();
    delete window.CHARTS[canvasId];
  }

  const chart = new Chart(el, {
    type: 'line',
    data: {
      labels,
      datasets: datasets.map(ds => ({
        label: ds.label,
        data: ds.data,
        spanGaps: true,
        tension: 0.25,
        pointRadius: 2,
      })),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        title: { display: true, text: title },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed.y;
              if (v === null || v === undefined) return ctx.dataset.label + ': —';
              let vv = v;
              if (typeof vv === 'number') vv = (Math.round(vv * 100) / 100);
              return ctx.dataset.label + ': ' + vv + valueSuffix;
            }
          }
        }
      },
      scales: {
        y: {
          type: yScaleType,
          beginAtZero: (yScaleType === 'linear'),
          min: (yScaleType === 'logarithmic') ? undefined : 0
        }
      }
    }
  });

  window.CHARTS[canvasId] = chart;
  return chart;
}

function buildRelativeDatasets(speedDatasets) {
  if (!speedDatasets || speedDatasets.length === 0) return [];
  const base = speedDatasets[0].data;

  return speedDatasets.map(ds => {
    const rel = ds.data.map((v, i) => {
      const b = base[i];
      if (v === null || v === undefined || b === null || b === undefined) return null;
      if (typeof v !== 'number' || typeof b !== 'number') return null;
      if (b <= 0) return null;
      return v / b;
    });
    return { label: ds.label, data: rel };
  });
}

function wireExportButtons(canvasId) {
  const btnPng = document.querySelector('[data-export="png"][data-chart="' + canvasId + '"]');
  const btnSvg = document.querySelector('[data-export="svg"][data-chart="' + canvasId + '"]');

  if (btnPng) btnPng.onclick = () => exportPng(canvasId);
  if (btnSvg) btnSvg.onclick = () => exportSvg(canvasId);
}

function exportPng(canvasId) {
  const chart = window.CHARTS[canvasId];
  if (!chart) return;

  const a = document.createElement('a');
  a.href = chart.toBase64Image('image/png', 1);
  a.download = canvasId + '.png';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

// SVG wrapper with embedded PNG (максимально совместимо)
function exportSvg(canvasId) {
  const chart = window.CHARTS[canvasId];
  if (!chart) return;

  const pngDataUrl = chart.toBase64Image('image/png', 1);
  const canvas = chart.canvas;
  const w = canvas.width || 1200;
  const h = canvas.height || 600;

  const svg =
    '<?xml version="1.0" encoding="UTF-8"?>' +
    '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '">' +
    '<image href="' + pngDataUrl + '" width="' + w + '" height="' + h + '"/>' +
    '</svg>';

  const blob = new Blob([svg], {type: 'image/svg+xml;charset=utf-8'});
  const url = URL.createObjectURL(blob);

  const a = document.createElement('a');
  a.href = url;
  a.download = canvasId + '.svg';
  document.body.appendChild(a);
  a.click();
  a.remove();

  URL.revokeObjectURL(url);
}

function renderGroup(prefix, group, yScaleType) {
  if (!group) return;

  makeLineChart(prefix + '_time', 'Time (ms)', group.time_ms.labels, group.time_ms.datasets, ' ms', yScaleType);
  makeLineChart(prefix + '_mem',  'Peak memory (MB)', group.peak_mem_mb.labels, group.peak_mem_mb.datasets, ' MB', yScaleType);

  makeLineChart(prefix + '_cps',  'Speed (cells/s)', group.cells_per_sec.labels, group.cells_per_sec.datasets, ' cells/s', yScaleType);

  const relDatasets = buildRelativeDatasets(group.cells_per_sec.datasets);
  makeLineChart(prefix + '_rel', 'Relative speed (× baseline)', group.cells_per_sec.labels, relDatasets, '×', yScaleType);

  wireExportButtons(prefix + '_time');
  wireExportButtons(prefix + '_mem');
  wireExportButtons(prefix + '_cps');
  wireExportButtons(prefix + '_rel');
}

function renderAll(yScaleType) {
  renderGroup('write', PAYLOAD.write, yScaleType);

  Object.keys(PAYLOAD.read || {}).forEach(writer => {
    const safeId = 'read_' + writer.replace(/[^a-z0-9_-]+/ig, '_');
    renderGroup(safeId, PAYLOAD.read[writer], yScaleType);
  });
}

// initial
renderAll('linear');

// toggle y-scale
const chk = document.getElementById('yscaleLog');
if (chk) {
  chk.addEventListener('change', () => {
    const yScale = chk.checked ? 'logarithmic' : 'linear';
    renderAll(yScale);
  });
}
</script>
HTML;

    $html .= '</div>';
    return $html;
}

function renderChartsBlock(string $prefix): string
{
    return <<<HTML
<div class="charts-grid">
  <div class="chart-card">
    <div class="chart-head">
      <div class="chart-title">Time</div>
      <div class="chart-actions">
        <button type="button" class="btn" data-export="png" data-chart="{$prefix}_time">PNG</button>
        <button type="button" class="btn" data-export="svg" data-chart="{$prefix}_time">SVG</button>
      </div>
    </div>
    <div class="chart-canvas"><canvas id="{$prefix}_time"></canvas></div>
  </div>

  <div class="chart-card">
    <div class="chart-head">
      <div class="chart-title">Peak memory</div>
      <div class="chart-actions">
        <button type="button" class="btn" data-export="png" data-chart="{$prefix}_mem">PNG</button>
        <button type="button" class="btn" data-export="svg" data-chart="{$prefix}_mem">SVG</button>
      </div>
    </div>
    <div class="chart-canvas"><canvas id="{$prefix}_mem"></canvas></div>
  </div>

  <div class="chart-card">
    <div class="chart-head">
      <div class="chart-title">Speed (cells/s)</div>
      <div class="chart-actions">
        <button type="button" class="btn" data-export="png" data-chart="{$prefix}_cps">PNG</button>
        <button type="button" class="btn" data-export="svg" data-chart="{$prefix}_cps">SVG</button>
      </div>
    </div>
    <div class="chart-canvas"><canvas id="{$prefix}_cps"></canvas></div>
  </div>

  <div class="chart-card">
    <div class="chart-head">
      <div class="chart-title">Relative speed (× baseline)</div>
      <div class="chart-actions">
        <button type="button" class="btn" data-export="png" data-chart="{$prefix}_rel">PNG</button>
        <button type="button" class="btn" data-export="svg" data-chart="{$prefix}_rel">SVG</button>
      </div>
    </div>
    <div class="chart-canvas"><canvas id="{$prefix}_rel"></canvas></div>
  </div>
</div>
HTML;
}
