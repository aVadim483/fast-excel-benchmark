<h1>Benchmark XLSX writers: PhpSpreadsheet, OpenSpout, FastExcelWriter</h1>

<p>
    Install dependencies via Composer:
</p>
<pre>composer require phpoffice/phpspreadsheet openspout/openspout avadim/fast-excel-writer</pre>


<br>
Usage (CLI):
<ul>
   <li>php write_xlsx.php --rows=50000 --cols=10</li>
</ul>

<br>
Result:
<ul>
    <li>Creates: phpspreadsheet.xlsx, openspout.xlsx, fastexcelwriter.xlsx</li>
    <li>Generates: benchmark_write_results.html (HTML table with results)</li>
</ul>

<?php

if (file_exists('benchmark_write_results.html')) {
    include 'benchmark_write_results.html';
}