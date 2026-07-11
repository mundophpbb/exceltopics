<?php
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../exception/xlsx_exception.php';
require_once __DIR__ . '/../service/xlsx_reader.php';
require_once __DIR__ . '/../service/table_renderer.php';

use mundophpbb\exceltopics\exception\xlsx_exception;
use mundophpbb\exceltopics\service\table_renderer;
use mundophpbb\exceltopics\service\xlsx_reader;

function expect_true($condition, $message)
{
    if (!$condition)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class fake_cache
{
    public $values = array();
    public $ttls = array();

    public function get($key)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : false;
    }

    public function put($key, $value, $ttl = 0)
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;
    }
}

class unavailable_cache
{
    public function get($key)
    {
        throw new \RuntimeException('cache get unavailable');
    }

    public function put($key, $value, $ttl = 0)
    {
        throw new \RuntimeException('cache put unavailable');
    }
}

class fake_language
{
    private $strings = array(
        'EXCEL_TOPIC_FILE_AND_SHEET' => '%1$s — aba %2$s',
        'EXCEL_TOPIC_META_SHEET' => 'Aba: %s',
        'EXCEL_TOPIC_META_UPDATED' => 'Atualizada em %s',
        'EXCEL_TOPIC_META_DIMENSIONS' => '%1$d linhas · %2$d colunas',
        'EXCEL_TOPIC_SCROLL_HINT' => 'Deslize para ver mais',
        'EXCEL_TOPIC_EMPTY' => 'Vazia',
        'EXCEL_TOPIC_TRUNCATED_ROWS' => 'Limitada a %d linhas.',
        'EXCEL_TOPIC_TRUNCATED_COLUMNS' => 'Limitada a %d colunas.',
        'EXCEL_TOPIC_TRUNCATED_CELLS' => 'Células limitadas a %d caracteres.',
        'EXCEL_TOPIC_TRUNCATED_CONTENT' => 'Conteúdo limitado a %d MiB.',
        'EXCEL_TOPIC_TRUNCATED_OUTPUT' => 'HTML limitado a %d MiB.',
        'EXCEL_TOPIC_HIDDEN_ROWS_SKIPPED' => '%d linhas ocultas ignoradas.',
        'EXCEL_TOPIC_ERROR_READ' => 'Erro de leitura.',
        'EXCEL_TOPIC_ERROR_FILE_MISSING' => 'Arquivo ausente.',
        'EXCEL_TOPIC_ERROR_INVALID_XLSX' => 'XLSX inválido.',
        'EXCEL_TOPIC_ERROR_OUTPUT_TOO_LARGE' => 'Saída grande demais.',
    );

    public function lang($key)
    {
        $arguments = func_get_args();
        array_shift($arguments);
        $format = isset($this->strings[$key]) ? $this->strings[$key] : $key;
        return empty($arguments) ? $format : vsprintf($format, $arguments);
    }
}

class fake_log
{
    public $calls = array();

    public function add($mode, $user_id, $log_ip, $operation, $log_time = false, $additional_data = array())
    {
        $this->calls[] = array(
            'mode' => $mode,
            'user_id' => $user_id,
            'log_ip' => $log_ip,
            'operation' => $operation,
            'additional_data' => $additional_data,
        );
        return count($this->calls);
    }
}

class fake_user
{
    public $data = array('user_id' => 123);
    public $ip = '127.0.0.1';

    public function format_date($time)
    {
        return 'data-' . $time;
    }
}

class fake_reader extends xlsx_reader
{
    public $reads = 0;

    public function read($path)
    {
        $this->reads++;
        return array(
            'sheet_name' => 'Produtos',
            'context_rows' => array(
                array('CATÁLOGO 2026', ''),
                array('RESPONSÁVEL', '<Admin>'),
            ),
            'rows' => array(
                array('Produto', 'Preço'),
                array('<script>alert(1)</script>', 'R$ 10 & R$ 20'),
            ),
            'truncated_rows' => true,
            'truncated_columns' => true,
            'truncated_cells' => 1,
            'truncated_content' => true,
            'hidden_rows_skipped' => 2,
            'selected_by_active_tab' => true,
            'max_rows' => 1000,
            'max_columns' => 50,
            'max_cell_characters' => 4096,
            'max_total_cell_bytes' => 2097152,
        );
    }
}

class failing_reader extends xlsx_reader
{
    public $reads = 0;

    public function read($path)
    {
        $this->reads++;
        throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX', 'Broken ZIP central directory <script>alert(1)</script>');
    }
}

class large_reader extends xlsx_reader
{
    public function read($path)
    {
        $rows = array(array('Coluna A', 'Coluna B'));
        for ($index = 0; $index < 30; $index++)
        {
            $rows[] = array(str_repeat('A', 700), str_repeat('&', 700));
        }

        return array(
            'sheet_name' => 'Grande',
            'rows' => $rows,
            'truncated_rows' => false,
            'truncated_columns' => false,
            'truncated_cells' => 0,
            'truncated_content' => false,
            'hidden_rows_skipped' => 0,
            'selected_by_active_tab' => true,
            'max_rows' => 1000,
            'max_columns' => 50,
            'max_cell_characters' => 4096,
            'max_total_cell_bytes' => 2097152,
        );
    }
}

class tiny_output_renderer extends table_renderer
{
    const MAX_RENDERED_HTML_BYTES = 4096;
    const OUTPUT_RESERVE_BYTES = 512;
}

$temporary_file = tempnam(sys_get_temp_dir(), 'excel-topics-renderer-');
file_put_contents($temporary_file, 'first version');

$language = new fake_language();
$user = new fake_user();
$attachment = array(
    'attach_id' => 10,
    'physical_filename' => basename($temporary_file),
    'real_filename' => 'preços<2026>.xlsx',
    'filetime' => 100,
    'filesize' => filesize($temporary_file),
    'poster_id' => 77,
);

$cache = new fake_cache();
$log = new fake_log();
$reader = new fake_reader();
$renderer = new table_renderer($cache, $language, $reader, $log, $user);

$html = $renderer->render($attachment, $temporary_file);
expect_true(strpos($html, '<table class="excel-topic-table">') !== false, 'responsive table was rendered');
expect_true(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'cell HTML was escaped');
expect_true(strpos($html, '<script>alert(1)</script>') === false, 'raw cell HTML was not emitted');
expect_true(strpos($html, 'preços&lt;2026&gt;.xlsx') !== false, 'filename was escaped');
expect_true(strpos($html, 'Aba: Produtos') !== false, 'sheet metadata was rendered');
expect_true(strpos($html, 'CATÁLOGO 2026') !== false, 'worksheet context title was rendered');
expect_true(strpos($html, 'RESPONSÁVEL') !== false, 'worksheet context label was rendered');
expect_true(strpos($html, '&lt;Admin&gt;') !== false, 'worksheet context value was escaped');
expect_true(strpos($html, '<Admin>') === false, 'raw worksheet context HTML was not emitted');
expect_true(strpos($html, 'Atualizada em data-100') !== false, 'attachment update date was rendered');
expect_true(strpos($html, '1 linhas · 2 colunas') !== false, 'table dimensions were rendered');
expect_true(strpos($html, 'data-scroll-hint="Deslize para ver mais"') !== false, 'localized mobile scroll hint was rendered');
expect_true(strpos($html, 'Limitada a 1000 linhas.') !== false, 'row limit notice was rendered');
expect_true(strpos($html, 'Limitada a 50 colunas.') !== false, 'column limit notice was rendered');
expect_true(strpos($html, 'Células limitadas a 4096 caracteres.') !== false, 'cell text notice was rendered');
expect_true(strpos($html, 'Conteúdo limitado a 2 MiB.') !== false, 'total content notice was rendered');
expect_true(strpos($html, '2 linhas ocultas ignoradas.') !== false, 'hidden row notice was rendered');
expect_true($reader->reads === 1, 'reader was called once');
expect_true(count($log->calls) === 0, 'successful rendering created no error log');

$renderer->render($attachment, $temporary_file);
expect_true($reader->reads === 1, 'second render used success cache');

$no_cache_log = new fake_log();
$no_cache_reader = new fake_reader();
$no_cache_renderer = new table_renderer(new unavailable_cache(), $language, $no_cache_reader, $no_cache_log, $user);
$no_cache_html = $no_cache_renderer->render($attachment, $temporary_file);
expect_true(strpos($no_cache_html, '<table class="excel-topic-table">') !== false, 'cache outage did not block rendering');
expect_true($no_cache_reader->reads === 1, 'cache outage fell back to direct reading');
expect_true(count($no_cache_log->calls) === 0, 'cache outage did not create a spreadsheet error log');

file_put_contents($temporary_file, ' second version', FILE_APPEND);
clearstatcache(true, $temporary_file);
$renderer->render($attachment, $temporary_file);
expect_true($reader->reads === 2, 'file-size change invalidated success cache');

$error_cache = new fake_cache();
$error_log = new fake_log();
$error_reader = new failing_reader();
$error_renderer = new table_renderer($error_cache, $language, $error_reader, $error_log, $user);
$error_html = $error_renderer->render($attachment, $temporary_file);
$error_html_again = $error_renderer->render($attachment, $temporary_file);
expect_true(strpos($error_html, 'XLSX inválido.') !== false, 'specific public error was rendered');
expect_true($error_html_again === $error_html, 'cached public error remained stable');
expect_true($error_reader->reads === 1, 'reader error was cached briefly');
expect_true(count($error_log->calls) === 1, 'reader error was logged only once');
expect_true($error_log->calls[0]['mode'] === 'admin', 'expected spreadsheet failures used the admin log');
expect_true($error_log->calls[0]['operation'] === 'LOG_MUNDOPHPBB_EXCELTOPICS_RENDER_ERROR', 'vendor-specific log key was used');
expect_true(strpos($error_log->calls[0]['additional_data'][0], 'preços&lt;2026&gt;.xlsx') !== false, 'log filename was HTML-escaped');
expect_true($error_log->calls[0]['additional_data'][4] === 77, 'attachment author was used in log details');
expect_true(strpos($error_log->calls[0]['additional_data'][6], 'Broken ZIP central directory') !== false, 'technical detail was preserved in the admin log');
expect_true(strpos($error_log->calls[0]['additional_data'][6], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'technical log detail was HTML-escaped');
expect_true(strpos($error_log->calls[0]['additional_data'][6], '<script>') === false, 'technical log detail emitted no raw HTML');
expect_true(in_array(table_renderer::ERROR_CACHE_TTL, $error_cache->ttls, true), 'error cache used the short TTL');

$output_cache = new fake_cache();
$output_log = new fake_log();
$output_renderer = new tiny_output_renderer($output_cache, $language, new large_reader(), $output_log, $user);
$output_html = $output_renderer->render(
    array_merge($attachment, array('attach_id' => 11, 'real_filename' => 'grande.xlsx')),
    $temporary_file
);
expect_true(strlen($output_html) < tiny_output_renderer::MAX_RENDERED_HTML_BYTES, 'rendered HTML stayed below the hard output limit');
expect_true(strpos($output_html, 'HTML limitado a 1 MiB.') !== false, 'HTML truncation notice was rendered');
expect_true(count($output_log->calls) === 0, 'safe HTML truncation did not create an error log');

unlink($temporary_file);
echo "renderer smoke test: OK\n";
