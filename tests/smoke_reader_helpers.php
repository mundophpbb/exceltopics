<?php
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../exception/xlsx_exception.php';
require_once __DIR__ . '/../service/xlsx_reader.php';

use mundophpbb\exceltopics\exception\xlsx_exception;
use mundophpbb\exceltopics\service\xlsx_reader;

function reader_expect($condition, $message)
{
    if (!$condition)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class inspectable_xlsx_reader extends xlsx_reader
{
    public function column($reference)
    {
        return $this->column_from_reference($reference);
    }

    public function zip_path($workbook_path, $target)
    {
        return $this->resolve_zip_path($workbook_path, $target);
    }

    public function numeric($raw, $format_code, $date_1904 = false)
    {
        return $this->format_numeric_value($raw, $format_code, $date_1904);
    }

    public function limit($value, &$was_truncated)
    {
        return $this->limit_cell_value($value, $was_truncated);
    }

    public function choose(array $sheets, $active_tab = null)
    {
        return $this->select_sheet($sheets, $active_tab);
    }

    public function range($reference)
    {
        return $this->parse_range_reference($reference);
    }

    public function worksheet_rels($worksheet_path)
    {
        return $this->worksheet_relationships_path($worksheet_path);
    }
}

$reader = new inspectable_xlsx_reader();

reader_expect($reader->column('A1') === 1, 'column A was resolved');
reader_expect($reader->column('Z99') === 26, 'column Z was resolved');
reader_expect($reader->column('AA2') === 27, 'column AA was resolved');
reader_expect($reader->column('AX2') === 50, 'column AX was resolved');
reader_expect(
    $reader->zip_path('xl/workbook.xml', 'worksheets/sheet1.xml') === 'xl/worksheets/sheet1.xml',
    'worksheet relationship path was resolved'
);
reader_expect(
    $reader->zip_path('xl/workbook.xml', '/xl/worksheets/sheet1.xml') === 'xl/worksheets/sheet1.xml',
    'absolute package path was normalized'
);
reader_expect(
    $reader->worksheet_rels('xl/worksheets/sheet1.xml') === 'xl/worksheets/_rels/sheet1.xml.rels',
    'worksheet relationship path was built'
);
$range = $reader->range('B7:E16');
reader_expect($range !== null, 'structured table range was parsed');
reader_expect($range['start_column'] === 2 && $range['end_column'] === 5, 'structured table columns were parsed');
reader_expect($range['start_row'] === 7 && $range['end_row'] === 16, 'structured table rows were parsed');
reader_expect($range['width'] === 4 && $range['height'] === 10, 'structured table dimensions were calculated');
reader_expect($reader->range('$B$7:$E$16')['width'] === 4, 'absolute range references were parsed');
reader_expect($reader->range('E16:B7') === null, 'reversed structured table range was rejected');
reader_expect($reader->range('invalid') === null, 'invalid structured table range was rejected');
reader_expect($reader->range('XFE1:XFE2') === null, 'columns beyond Excel limits were rejected');
reader_expect($reader->range('A1048577:A1048577') === null, 'rows beyond Excel limits were rejected');
reader_expect($reader->numeric('0.125', '0.0%') === '12.5%', 'percentage was formatted');
reader_expect($reader->numeric('45292', 'yyyy-mm-dd') === '2024-01-01', 'Excel date was formatted');
reader_expect($reader->numeric('7', '0000') === '0007', 'zero-padded integer was formatted');

$sheets = array(
    array('name' => 'Instrucoes', 'state' => '', 'path' => 'xl/worksheets/sheet1.xml'),
    array('name' => 'Produtos', 'state' => '', 'path' => 'xl/worksheets/sheet2.xml'),
    array('name' => 'Oculta', 'state' => 'hidden', 'path' => 'xl/worksheets/sheet3.xml'),
);
$chosen = $reader->choose($sheets, 1);
reader_expect($chosen['name'] === 'Produtos', 'active visible sheet was selected');
reader_expect($chosen['selected_by_active_tab'] === true, 'active sheet selection was marked');
$chosen = $reader->choose($sheets, 2);
reader_expect($chosen['name'] === 'Instrucoes', 'hidden active sheet fell back to first visible sheet');
reader_expect($chosen['selected_by_active_tab'] === false, 'fallback selection was marked');

$was_truncated = false;
$limited = $reader->limit(str_repeat('A', xlsx_reader::MAX_CELL_CHARACTERS + 10), $was_truncated);
reader_expect($was_truncated, 'oversized ASCII cell was marked as truncated');
reader_expect(strlen($limited) === xlsx_reader::MAX_CELL_CHARACTERS, 'oversized ASCII cell was limited exactly');

$was_truncated = false;
$unicode_exact = str_repeat('á', xlsx_reader::MAX_CELL_CHARACTERS);
$limited = $reader->limit($unicode_exact, $was_truncated);
reader_expect(!$was_truncated, 'multibyte cell at the character limit was preserved');
reader_expect($limited === $unicode_exact, 'multibyte cell was not cut by byte length');

$was_truncated = false;
$unicode_long = str_repeat('á', xlsx_reader::MAX_CELL_CHARACTERS + 1);
$limited = $reader->limit($unicode_long, $was_truncated);
reader_expect($was_truncated, 'multibyte cell above the character limit was truncated');
reader_expect(preg_match_all('/./us', $limited, $matches) === xlsx_reader::MAX_CELL_CHARACTERS, 'multibyte truncation preserved valid UTF-8 characters');

$traversal_was_blocked = false;
try
{
    $reader->zip_path('xl/workbook.xml', '../../../outside.xml');
}
catch (xlsx_exception $exception)
{
    $traversal_was_blocked = true;
}
reader_expect($traversal_was_blocked, 'relationship traversal above archive root was blocked');

echo "reader helper smoke test: OK\n";
