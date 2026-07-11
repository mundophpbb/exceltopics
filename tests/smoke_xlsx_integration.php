<?php
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !function_exists('simplexml_load_string'))
{
    echo "XLSX integration smoke test: SKIPPED (ZipArchive/XMLReader/SimpleXML unavailable)\n";
    exit(0);
}

require_once __DIR__ . '/../exception/xlsx_exception.php';
require_once __DIR__ . '/../service/xlsx_reader.php';

use mundophpbb\exceltopics\service\xlsx_reader;

function integration_expect($condition, $message)
{
    if (!$condition)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reader = new xlsx_reader();
$data = $reader->read(__DIR__ . '/fixtures/basic.xlsx');

integration_expect($data['sheet_name'] === 'Produtos', 'first visible worksheet was selected');
integration_expect(count($data['rows']) === 5, 'all fixture rows were read');
integration_expect($data['rows'][0][0] === 'Produto', 'header cell was read');
integration_expect($data['rows'][1][0] === 'Camiseta', 'UTF-8 text cell was read');
integration_expect($data['rows'][1][2] === '12', 'numeric cell was read');
integration_expect(!$data['truncated_rows'], 'fixture rows were not truncated');
integration_expect(!$data['truncated_columns'], 'fixture columns were not truncated');
integration_expect(!$data['truncated_cells'], 'fixture cells were not truncated');
integration_expect(!$data['truncated_content'], 'fixture content was not truncated');

$structured = $reader->read(__DIR__ . '/fixtures/structured_table.xlsx');
integration_expect($structured['sheet_name'] === 'Projeto 1', 'structured table worksheet was selected');
integration_expect($structured['selected_structured_table'] === true, 'structured Excel table was detected');
integration_expect($structured['table_name'] === 'Projeto1', 'structured table name was read');
integration_expect($structured['table_range'] === 'B4:E7', 'structured table range was read');
integration_expect(count($structured['rows']) === 4, 'only structured table rows were returned');
integration_expect(count($structured['rows'][0]) === 4, 'only structured table columns were returned');
integration_expect($structured['rows'][0][0] === '% Concluída', 'structured table header was read');
integration_expect($structured['rows'][1][1] === 'Planejamento', 'structured table data was read');
integration_expect($structured['rows'][0][0] !== 'REMODELAÇÃO DE CASA', 'content above structured table was excluded');

echo "XLSX integration smoke test: OK\n";
