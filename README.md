# XLSX Benchmark
FastExcelWriter vs PhpSpreadsheet vs OpenSpout

This repository contains a benchmark suite to compare **XLSX write and read performance** between:

- avadim/fast-excel-writer
- avadim/fast-excel-reader
- phpoffice/phpspreadsheet
- openspout/openspout

It measures:

- Execution time (ms)
- Peak memory usage (MB)
- Speed (cells/sec, rows/sec)
- Relative speed (% vs baseline)

Results are stored in `/results/*.jsonl` and can be viewed:

- In console (report.php)
- As Markdown report
- In browser (public/index.php) with interactive charts

---------------------------------------------------------------------

# 🇬🇧 ENGLISH

## 1. Installation

Install dependencies via Composer:

    composer require avadim/fast-excel-benchmark

The `/results` and `/tmp` directories will be created automatically if needed.

---------------------------------------------------------------------

## 2. Running the benchmark

Main command:

    php console/bench.php

Optional parameters:

    php console/bench.php --out=run1.jsonl
    php console/bench.php --phpss-cache=none
    php console/bench.php --phpss-cache=memory
    php console/bench.php --phpss-cache=memory_gzip
    php console/bench.php --phpss-cache=discISAM

What happens:

1. WRITE benchmarks are executed for multiple table sizes (rows × columns).
2. XLSX files are generated using each library.
3. READ benchmarks are executed using files created by FastExcelWriter.
4. All results are appended to `/results/<file>.jsonl`.

Each line of the `.jsonl` file is a separate JSON record.

---------------------------------------------------------------------

## 3. Console report

Print formatted summary in terminal:

    php console/report.php

Or:

    php console/report.php --in=run1.jsonl
    php console/report.php --md=report.md
    php console/report.php --in=run1.jsonl --md=report.md

Markdown reports are saved to `/results/`.

---------------------------------------------------------------------

## 4. Web UI with charts

Open in browser:

    http://localhost/public/

Features:

- Interactive charts (Chart.js)
- Linear / logarithmic Y scale toggle
- Relative speed graph (× baseline)
- Export charts as PNG or SVG
- Relative speed (%) in tables
- Hide empty / FAIL-only rows

Baseline libraries:

- WRITE → avadim/fast-excel-writer
- READ → avadim/fast-excel-reader

---------------------------------------------------------------------

## 5. Metrics explained

time (ms)  
Total execution time.

peak memory (MB)  
Peak memory usage.

cells/s  
Processed cells per second.

rows/s  
Processed rows per second.

%  
Relative speed compared to baseline.

Example:

85%  → library is 15% slower than baseline  
150% → library is 1.5× faster than baseline

---------------------------------------------------------------------

# 🇷🇺 РУССКАЯ ВЕРСИЯ

## 1. Установка

Установите зависимости через Composer:

    composer require avadim/fast-excel-benchmark

Папки `/results` и `/tmp` создаются автоматически.

---------------------------------------------------------------------

## 2. Запуск бенчмарка

Основная команда:

    php console/bench.php

Дополнительные параметры:

    php console/bench.php --out=run1.jsonl
    php console/bench.php --phpss-cache=none
    php console/bench.php --phpss-cache=memory
    php console/bench.php --phpss-cache=memory_gzip
    php console/bench.php --phpss-cache=discISAM

Что происходит:

1. Выполняются тесты записи (WRITE) для разных размеров таблиц.
2. Создаются XLSX-файлы каждой библиотекой.
3. Выполняются тесты чтения (READ) по файлам, созданным FastExcelWriter.
4. Результаты сохраняются в `/results/<файл>.jsonl`.

Каждая строка файла `.jsonl` — отдельная запись JSON.

---------------------------------------------------------------------

## 3. Консольный отчет

Вывод в терминал:

    php console/report.php

Или:

    php console/report.php --in=run1.jsonl
    php console/report.php --md=report.md
    php console/report.php --in=run1.jsonl --md=report.md

Markdown-отчеты сохраняются в `/results/`.

---------------------------------------------------------------------

## 4. Веб-интерфейс с графиками

Откройте в браузере:

    http://localhost/public/

Возможности:

- Интерактивные графики (Chart.js)
- Переключение линейной / логарифмической шкалы
- График относительной скорости (× baseline)
- Экспорт PNG / SVG
- Относительная скорость (%) в таблицах
- Фильтр пустых строк / FAIL

Базовая библиотека (baseline):

- WRITE → avadim/fast-excel-writer
- READ → avadim/fast-excel-reader

---------------------------------------------------------------------

## 5. Описание метрик

time (ms)  
Время выполнения.

peak memory (MB)  
Пиковое потребление памяти.

cells/s  
Количество обработанных ячеек в секунду.

rows/s  
Количество строк в секунду.

%  
Относительная скорость относительно baseline.

Пример:

85%  → библиотека на 15% медленнее baseline  
150% → библиотека в 1.5 раза быстрее baseline

---------------------------------------------------------------------

Рекомендуется:

- Использовать одинаковые размеры кейсов для корректного сравнения.
- Тестировать разные режимы cache для PhpSpreadsheet.
- Для больших кейсов использовать логарифмическую шкалу.
- Сравнивать не только скорость, но и потребление памяти.
