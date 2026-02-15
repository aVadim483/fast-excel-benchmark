<?php
declare(strict_types=1);

/**
 * console/runner.php
 *
 * Executes a single benchmark run (write or read) for one library and one case.
 * Prints exactly one JSON line to STDOUT.
 *
 * Usage examples:
 *   php console/runner.php --mode=write --lib=fastexcel --rows=1000 --cols=10 --out=/path/file.xlsx
 *   php console/runner.php --mode=read  --lib=openspout  --rows=1000 --cols=10 --in=/path/file.xlsx --writer=fastexcel
 *
 * PhpSpreadsheet cache:
 *   --phpss-cache=none|memory|memory_gzip|discISAM
 */

require __DIR__ . '/_bootstrap.php';

$opts = getopt('', [
    'mode:',
    'lib:',
    'rows:',
    'cols:',
    'out::',
    'in::',
    'writer::',
    'phpss-cache::',
]);

$ts = date('c');

$mode = (string)($opts['mode'] ?? '');
$lib  = (string)($opts['lib'] ?? '');
$rows = (int)($opts['rows'] ?? 0);
$cols = (int)($opts['cols'] ?? 0);

$outFile = (string)($opts['out'] ?? '');
$inFile  = (string)($opts['in'] ?? '');
$writer  = (string)($opts['writer'] ?? '');
$phpssCache = (string)($opts['phpss-cache'] ?? 'none');
$phpssCache = $phpssCache !== '' ? $phpssCache : 'none';

$result = [
    'ts' => $ts,
    'mode' => $mode,
    'lib' => $lib,
    'rows' => $rows,
    'cols' => $cols,
];

if ($mode === 'write') {
    $result['out'] = $outFile;
    if ($lib === 'phpspreadsheet') {
        $result['phpss_cache'] = $phpssCache;
    }
} elseif ($mode === 'read') {
    $result['in'] = $inFile;
    $result['writer'] = $writer;
    if ($lib === 'phpspreadsheet') {
        $result['phpss_cache'] = $phpssCache;
    }
}

$startMem = memory_get_usage(true);
$startPeak = memory_get_peak_usage(true);
$start = microtime(true);

try {
    validateInput($mode, $lib, $rows, $cols, $outFile, $inFile);

    if ($mode === 'write') {
        switch ($lib) {
            case 'fastexcel':
                runFastExcelWrite($outFile, $rows, $cols);
                break;

            case 'phpspreadsheet':
                configurePhpSpreadsheetCache($phpssCache, bench_tmp_dir() . '/phpss_cache');
                runPhpSpreadsheetWrite($outFile, $rows, $cols);
                break;

            case 'openspout':
                runOpenSpoutWrite($outFile, $rows, $cols);
                break;

            default:
                throw new RuntimeException("Unknown lib for write: {$lib}");
        }
    } else { // read
        switch ($lib) {
            case 'fastexcelreader':
                $read = runFastExcelReaderRead($inFile);
                $result['read_rows']  = $read['rows'];
                $result['read_cells'] = $read['cells'];
                break;

            case 'phpspreadsheet':
                configurePhpSpreadsheetCache($phpssCache, bench_tmp_dir() . '/phpss_cache');
                $read = runPhpSpreadsheetRead($inFile);
                $result['read_rows']  = $read['rows'];
                $result['read_cells'] = $read['cells'];
                break;

            case 'openspout':
                $read = runOpenSpoutRead($inFile);
                $result['read_rows']  = $read['rows'];
                $result['read_cells'] = $read['cells'];
                break;

            default:
                throw new RuntimeException("Unknown lib for read: {$lib}");
        }
    }

    $elapsedMs = (int)round((microtime(true) - $start) * 1000);
    $peakMemMb = (memory_get_peak_usage(true) / 1024 / 1024);

    $result['ok'] = true;
    $result['time_ms'] = $elapsedMs;
    $result['peak_mem_mb'] = round($peakMemMb, 3);
    $result['mem_start_mb'] = round($startMem / 1024 / 1024, 3);
    $result['mem_peak_start_mb'] = round($startPeak / 1024 / 1024, 3);

} catch (Throwable $e) {
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);
    $peakMemMb = (memory_get_peak_usage(true) / 1024 / 1024);

    $result['ok'] = false;
    $result['time_ms'] = $elapsedMs;
    $result['peak_mem_mb'] = round($peakMemMb, 3);
    $result['error'] = $e->getMessage();
    $result['exception'] = get_class($e);
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/* ---------------- validation ---------------- */

function validateInput(string $mode, string $lib, int $rows, int $cols, string $outFile, string $inFile): void
{
    if ($mode !== 'write' && $mode !== 'read') {
        throw new RuntimeException('Invalid --mode (expected write|read)');
    }
    if ($lib === '') {
        throw new RuntimeException('Missing --lib');
    }
    if ($rows <= 0 || $cols <= 0) {
        throw new RuntimeException('Invalid --rows/--cols (must be > 0)');
    }

    if ($mode === 'write') {
        if ($outFile === '') {
            throw new RuntimeException('Missing --out for write mode');
        }
        $dir = dirname($outFile);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    } else {
        if ($inFile === '' || !is_file($inFile)) {
            throw new RuntimeException('Missing or not found --in for read mode');
        }
    }
}

/* ---------------- dataset generator ---------------- */

function makeHeader(int $cols): array
{
    $row = [];
    for ($c = 1; $c <= $cols; $c++) {
        $row[] = 'C' . $c;
    }
    return $row;
}

function makeDataRow(int $r, int $cols): array
{
    $row = [];
    for ($c = 1; $c <= $cols; $c++) {
        $row[] = (($r * 1000) + $c);
    }
    return $row;
}

/* ---------------- FastExcelWriter ---------------- */

function runFastExcelWrite(string $outFile, int $rows, int $cols): void
{
    if (!class_exists(\avadim\FastExcelWriter\Excel::class)) {
        throw new RuntimeException('FastExcelWriter not installed (avadim\\FastExcelWriter\\Excel not found)');
    }

    // Важно: НЕ передаём имя файла в create(), а сохраняем явно в save($outFile)
    $excel = \avadim\FastExcelWriter\Excel::create();
    $sheet = $excel->sheet(); // default sheet

    $sheet->writeRow(makeHeader($cols));
    for ($r = 1; $r <= $rows; $r++) {
        $sheet->writeRow(makeDataRow($r, $cols));
    }

    $excel->save($outFile);
}



/* ---------------- FastExcelReader ---------------- */

function runFastExcelReaderRead(string $inFile): array
{
    if (!class_exists(\avadim\FastExcelReader\Excel::class)) {
        throw new RuntimeException('FastExcelReader not installed (avadim\\FastExcelReader\\Excel not found)');
    }

    $excel = \avadim\FastExcelReader\Excel::open($inFile);
    $sheet = $excel->sheet(); // default sheet

    if (!method_exists($sheet, 'nextRow')) {
        throw new RuntimeException('FastExcelReader: sheet::nextRow() not found');
    }

    $rowCount = 0;
    $cellCount = 0;

    foreach ($sheet->nextRow() as $row) {
        $rowCount++;
        if (is_array($row)) {
            $cellCount += count($row);
            foreach ($row as $v) { /* touch */ }
        } else {
            if ($row instanceof Traversable) {
                foreach ($row as $v) { $cellCount++; }
            }
        }
    }

    return ['rows' => $rowCount, 'cells' => $cellCount];
}

/* ---------------- PhpSpreadsheet cache (PSR-16) ---------------- */

function configurePhpSpreadsheetCache(string $mode, string $dir): void
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Settings::class)) {
        throw new RuntimeException('PhpSpreadsheet not installed (PhpOffice\\PhpSpreadsheet\\Settings not found)');
    }

    $mode = strtolower(trim($mode));
    if ($mode === '' || $mode === 'none') {
        \PhpOffice\PhpSpreadsheet\Settings::setCache(null);
        return;
    }

    if ($mode === 'memory') {
        \PhpOffice\PhpSpreadsheet\Settings::setCache(new Psr16ArrayCache(false));
        return;
    }

    if ($mode === 'memory_gzip') {
        \PhpOffice\PhpSpreadsheet\Settings::setCache(new Psr16ArrayCache(true));
        return;
    }

    if ($mode === 'discisam') {
        bench_ensure_dir($dir);
        \PhpOffice\PhpSpreadsheet\Settings::setCache(new Psr16FileCache($dir));
        return;
    }

    throw new RuntimeException("Unknown PhpSpreadsheet cache mode: {$mode}");
}

/**
 * Minimal PSR-16 cache implementations (no external deps).
 */

class Psr16ArrayCache implements \Psr\SimpleCache\CacheInterface
{
    private array $data = [];
    private bool $gzip;

    public function __construct(bool $gzip)
    {
        $this->gzip = $gzip;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->data)) return $default;

        $v = $this->data[$key];

        if (!$this->gzip) return $v;

        if (!is_string($v)) return $default;

        $raw = @gzuncompress($v);
        if ($raw === false) return $default;

        $val = @unserialize($raw);
        return ($val === false && $raw !== serialize(false)) ? $default : $val;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (!$this->gzip) {
            $this->data[$key] = $value;
            return true;
        }

        $raw = serialize($value);
        $zip = @gzcompress($raw, 1);
        if ($zip === false) return false;

        $this->data[$key] = $zip;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            $out[$k] = $this->get($k, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            if (!$this->set((string)$k, $v, $ttl)) return false;
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete((string)$k);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}

class Psr16FileCache implements \Psr\SimpleCache\CacheInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, "/\\");
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $p = $this->path($key);
        if (!is_file($p)) return $default;

        $raw = @file_get_contents($p);
        if ($raw === false) return $default;

        $val = @unserialize($raw);
        return ($val === false && $raw !== serialize(false)) ? $default : $val;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }

        $p = $this->path($key);
        return file_put_contents($p, serialize($value)) !== false;
    }

    public function delete(string $key): bool
    {
        $p = $this->path($key);
        if (is_file($p)) @unlink($p);
        return true;
    }

    public function clear(): bool
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.cache') ?: [];
        foreach ($files as $f) @unlink($f);
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            $out[$k] = $this->get($k, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            if (!$this->set((string)$k, $v, $ttl)) return false;
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete((string)$k);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }
}


/* ---------------- PhpSpreadsheet write/read ---------------- */

function runPhpSpreadsheetWrite(string $outFile, int $rows, int $cols): void
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('PhpSpreadsheet not installed (Spreadsheet class not found)');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sheet1');

    // Header: row 1
    $header = makeHeader($cols);
    for ($c = 1; $c <= $cols; $c++) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1';
        $sheet->setCellValue($cell, $header[$c - 1]);
    }

    // Data rows: start from row 2
    for ($r = 1; $r <= $rows; $r++) {
        $data = makeDataRow($r, $cols);
        $excelRow = $r + 1;

        for ($c = 1; $c <= $cols; $c++) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . (string)$excelRow;
            $sheet->setCellValue($cell, $data[$c - 1]);
        }
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($outFile);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
}


function runPhpSpreadsheetRead(string $inFile): array
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('PhpSpreadsheet not installed (IOFactory not found)');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inFile);
    $sheet = $spreadsheet->getSheet(0);

    $rowCount = 0;
    $cellCount = 0;

    foreach ($sheet->getRowIterator() as $row) {
        $rowCount++;
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);

        foreach ($cellIterator as $cell) {
            $cellCount++;
            $cell->getValue();
        }
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return ['rows' => $rowCount, 'cells' => $cellCount];
}

/* ---------------- OpenSpout write/read (openspout/openspout 5.x) ---------------- */

function runOpenSpoutWrite(string $outFile, int $rows, int $cols): void
{
    if (!class_exists(\OpenSpout\Writer\XLSX\Writer::class)) {
        throw new RuntimeException('OpenSpout not installed (OpenSpout\\Writer\\XLSX\\Writer not found)');
    }
    if (!class_exists(\OpenSpout\Common\Entity\Row::class)) {
        throw new RuntimeException('OpenSpout Row class not found (OpenSpout\\Common\\Entity\\Row)');
    }

    $writer = new \OpenSpout\Writer\XLSX\Writer();
    $writer->openToFile($outFile);

    // Header
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(makeHeader($cols)));

    // Data
    for ($r = 1; $r <= $rows; $r++) {
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(makeDataRow($r, $cols)));
    }

    $writer->close();
}


function runOpenSpoutRead(string $inFile): array
{
    if (!class_exists(\OpenSpout\Reader\XLSX\Reader::class)) {
        throw new RuntimeException('OpenSpout not installed (OpenSpout\\Reader\\XLSX\\Reader not found)');
    }

    $reader = new \OpenSpout\Reader\XLSX\Reader();
    $reader->open($inFile);

    $rowCount = 0;
    $cellCount = 0;

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rowCount++;

            if (method_exists($row, 'toArray')) {
                $values = $row->toArray();
                $cellCount += count($values);
                foreach ($values as $v) { /* touch */ }
                continue;
            }

            if (method_exists($row, 'getCells')) {
                $cells = $row->getCells();
                $cellCount += is_array($cells) ? count($cells) : 0;
                if (is_array($cells)) {
                    foreach ($cells as $cell) {
                        if (is_object($cell) && method_exists($cell, 'getValue')) {
                            $cell->getValue();
                        }
                    }
                }
                continue;
            }

            throw new RuntimeException('OpenSpout Row API mismatch: neither toArray() nor getCells() exists');
        }
        break; // only first sheet
    }

    $reader->close();

    return ['rows' => $rowCount, 'cells' => $cellCount];
}
