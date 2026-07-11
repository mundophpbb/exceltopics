<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\service;

use mundophpbb\exceltopics\exception\xlsx_exception;

class table_renderer
{
    const CACHE_VERSION = '6';
    const CACHE_TTL = 86400;
    const ERROR_CACHE_TTL = 300;
    const MAX_RENDERED_HTML_BYTES = 4194304; // 4 MiB
    const OUTPUT_RESERVE_BYTES = 65536;

    /** @var \phpbb\cache\driver\driver_interface */
    protected $cache;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var xlsx_reader */
    protected $reader;

    /** @var \phpbb\log\log_interface */
    protected $log;

    /** @var \phpbb\user */
    protected $user;

    /**
     * @param \phpbb\cache\driver\driver_interface $cache
     * @param \phpbb\language\language $language
     * @param xlsx_reader $reader
     * @param \phpbb\log\log_interface $log
     * @param \phpbb\user $user
     */
    public function __construct($cache, $language, xlsx_reader $reader, $log, $user)
    {
        $this->cache = $cache;
        $this->language = $language;
        $this->reader = $reader;
        $this->log = $log;
        $this->user = $user;
    }

    /**
     * @param array<string, mixed> $attachment
     * @param string $path
     * @param array<string, int> $context
     * @return string
     */
    public function render(array $attachment, $path, array $context = array())
    {
        $filename = isset($attachment['real_filename'])
            ? (string) $attachment['real_filename']
            : 'spreadsheet.xlsx';
        $cache_key = $this->build_cache_key($attachment, $path);
        $cached = false;

        try
        {
            $cached = $this->cache->get($cache_key);
        }
        catch (\Throwable $ignored)
        {
            // Cache availability must never decide whether a topic can render.
        }

        try
        {
            if (is_array($cached) && isset($cached['status']))
            {
                if ($cached['status'] === 'error' && !empty($cached['language_key']))
                {
                    return $this->render_error($filename, (string) $cached['language_key']);
                }

                if ($cached['status'] === 'ok' && isset($cached['data']) && is_array($cached['data']))
                {
                    return $this->render_table($filename, $cached['data'], $attachment);
                }
            }

            if ($path === '')
            {
                throw new xlsx_exception('EXCEL_TOPIC_ERROR_FILE_MISSING');
            }

            $data = $this->reader->read($path);

            try
            {
                $this->cache->put(
                    $cache_key,
                    array(
                        'status' => 'ok',
                        'data' => $data,
                    ),
                    self::CACHE_TTL
                );
            }
            catch (\Throwable $ignored)
            {
                // The parsed table is still valid when the cache backend fails.
            }

            return $this->render_table($filename, $data, $attachment);
        }
        catch (xlsx_exception $exception)
        {
            return $this->handle_failure(
                $attachment,
                $filename,
                $cache_key,
                $exception->get_language_key(),
                $exception,
                $context
            );
        }
        catch (\Throwable $exception)
        {
            return $this->handle_failure(
                $attachment,
                $filename,
                $cache_key,
                'EXCEL_TOPIC_ERROR_READ',
                $exception,
                $context
            );
        }
    }

    /**
     * Build a cache key from phpBB attachment metadata and the current file state.
     *
     * @param array<string, mixed> $attachment
     * @param string $path
     * @return string
     */
    protected function build_cache_key(array $attachment, $path)
    {
        $fingerprint = array(
            'version' => self::CACHE_VERSION,
            'attach_id' => isset($attachment['attach_id']) ? (int) $attachment['attach_id'] : 0,
            'physical_filename' => isset($attachment['physical_filename']) ? (string) $attachment['physical_filename'] : '',
            'attachment_filetime' => isset($attachment['filetime']) ? (int) $attachment['filetime'] : 0,
            'attachment_filesize' => isset($attachment['filesize']) ? (int) $attachment['filesize'] : 0,
            'path_available' => ($path !== ''),
        );

        if ($path !== '')
        {
            clearstatcache(true, $path);
            $stat = @stat($path);
            if ($stat !== false)
            {
                $fingerprint += array(
                    'disk_mtime' => isset($stat['mtime']) ? (int) $stat['mtime'] : 0,
                    'disk_ctime' => isset($stat['ctime']) ? (int) $stat['ctime'] : 0,
                    'disk_size' => isset($stat['size']) ? (int) $stat['size'] : 0,
                    'disk_inode' => isset($stat['ino']) ? (int) $stat['ino'] : 0,
                );
            }
        }

        $encoded = json_encode($fingerprint);
        if ($encoded === false)
        {
            $encoded = serialize($fingerprint);
        }

        return '_mundophpbb_exceltopics_' . sha1($encoded);
    }

    /**
     * Cache a public error briefly and record its diagnostic details once.
     *
     * @param array<string, mixed> $attachment
     * @param string $filename
     * @param string $cache_key
     * @param string $language_key
     * @param \Throwable $exception
     * @param array<string, int> $context
     * @return string
     */
    protected function handle_failure(array $attachment, $filename, $cache_key, $language_key, \Throwable $exception, array $context)
    {
        try
        {
            $this->cache->put(
                $cache_key,
                array(
                    'status' => 'error',
                    'language_key' => $language_key,
                ),
                self::ERROR_CACHE_TTL
            );
        }
        catch (\Throwable $ignored)
        {
            // A cache failure must never break topic rendering.
        }

        $this->log_failure($attachment, $filename, $language_key, $exception, $context);

        return $this->render_error($filename, $language_key);
    }

    /**
     * @param array<string, mixed> $attachment
     * @param string $filename
     * @param string $language_key
     * @param \Throwable $exception
     * @param array<string, int> $context
     * @return void
     */
    protected function log_failure(array $attachment, $filename, $language_key, \Throwable $exception, array $context)
    {
        try
        {
            $attach_id = isset($context['attach_id']) ? (int) $context['attach_id'] : (isset($attachment['attach_id']) ? (int) $attachment['attach_id'] : 0);
            $topic_id = isset($context['topic_id']) ? (int) $context['topic_id'] : (isset($attachment['topic_id']) ? (int) $attachment['topic_id'] : 0);
            $post_id = isset($context['post_id']) ? (int) $context['post_id'] : (isset($attachment['post_msg_id']) ? (int) $attachment['post_msg_id'] : 0);
            $poster_id = isset($context['attachment_poster_id']) ? (int) $context['attachment_poster_id'] : (isset($attachment['poster_id']) ? (int) $attachment['poster_id'] : 0);
            $detail = get_class($exception) . ': ' . $exception->getMessage();

            if ($exception->getPrevious() !== null)
            {
                $detail .= ' | Previous: ' . get_class($exception->getPrevious())
                    . ': ' . $exception->getPrevious()->getMessage();
            }

            $mode = $this->is_critical_failure($language_key) ? 'critical' : 'admin';

            $this->log->add(
                $mode,
                $poster_id,
                '',
                'LOG_MUNDOPHPBB_EXCELTOPICS_RENDER_ERROR',
                false,
                array(
                    $this->sanitize_log_value($filename, 255),
                    $topic_id,
                    $post_id,
                    $attach_id,
                    $poster_id,
                    $this->sanitize_log_value($language_key, 100),
                    $this->sanitize_log_value($detail, 1000),
                )
            );
        }
        catch (\Throwable $ignored)
        {
            // Logging is diagnostic only and must never affect the page.
        }
    }

    /**
     * @param string $language_key
     * @return bool
     */
    protected function is_critical_failure($language_key)
    {
        return in_array(
            $language_key,
            array(
                'EXCEL_TOPIC_ERROR_READ',
                'EXCEL_TOPIC_ERROR_ZIP_EXTENSION',
                'EXCEL_TOPIC_ERROR_XML_EXTENSION',
            ),
            true
        );
    }

    /**
     * @param string $filename
     * @param array<string, mixed> $data
     * @param array<string, mixed> $attachment
     * @return string
     * @throws xlsx_exception
     */
    protected function render_table($filename, array $data, array $attachment)
    {
        $sheet_name = isset($data['sheet_name']) ? (string) $data['sheet_name'] : '';
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array();
        $title = $this->language->lang('EXCEL_TOPIC_FILE_AND_SHEET', $filename, $sheet_name);
        $updated_at = $this->format_attachment_time($attachment);

        $html = '<section class="excel-topic-table-card">';
        $html .= '<div class="excel-topic-table-title">' . $this->escape($filename) . '</div>';

        if (empty($rows))
        {
            $html .= $this->build_metadata_html($sheet_name, $updated_at, null, null);
            $html .= '<p class="excel-topic-table-notice">'
                . $this->escape($this->language->lang('EXCEL_TOPIC_EMPTY'))
                . '</p></section>';
            return $html;
        }

        $column_count = 0;
        foreach ($rows as $row)
        {
            if (is_array($row))
            {
                $column_count = max($column_count, min(count($row), xlsx_reader::MAX_COLUMNS));
            }
        }

        if ($column_count === 0)
        {
            $html .= $this->build_metadata_html($sheet_name, $updated_at, null, null);
            $html .= '<p class="excel-topic-table-notice">'
                . $this->escape($this->language->lang('EXCEL_TOPIC_EMPTY'))
                . '</p></section>';
            return $html;
        }

        $header = array_shift($rows);
        $header = is_array($header) ? array_slice(array_pad($header, $column_count, ''), 0, $column_count) : array_fill(0, $column_count, '');
        $data_row_count = count($rows);

        $html .= $this->build_metadata_html($sheet_name, $updated_at, $data_row_count, $column_count);
        $html .= $this->render_sheet_context(isset($data['context_rows']) && is_array($data['context_rows']) ? $data['context_rows'] : array());
        $scroll_hint = $this->language->lang('EXCEL_TOPIC_SCROLL_HINT');
        $html .= '<div class="excel-topic-table-scroll" role="region" tabindex="0"'
            . ' aria-label="' . $this->escape($title) . '"'
            . ' data-scroll-hint="' . $this->escape($scroll_hint) . '">';
        $html .= '<table class="excel-topic-table">';
        $html .= '<caption class="excel-topic-visually-hidden">' . $this->escape($title) . '</caption>';
        $html .= '<thead><tr>';
        foreach ($header as $value)
        {
            $html .= '<th scope="col">' . $this->escape((string) $value) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $maximum = static::MAX_RENDERED_HTML_BYTES;
        $reserve = min(static::OUTPUT_RESERVE_BYTES, max(1024, (int) floor($maximum / 10)));
        $output_truncated = false;

        if (strlen($html) + $reserve >= $maximum)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_OUTPUT_TOO_LARGE', 'Table header exceeds HTML output limit');
        }

        foreach ($rows as $row)
        {
            $row = is_array($row) ? array_slice(array_pad($row, $column_count, ''), 0, $column_count) : array_fill(0, $column_count, '');
            $row_html = '<tr>';
            for ($column = 0; $column < $column_count; $column++)
            {
                $row_html .= '<td>' . $this->escape((string) $row[$column]) . '</td>';
            }
            $row_html .= '</tr>';

            if (strlen($html) + strlen($row_html) + $reserve >= $maximum)
            {
                $output_truncated = true;
                break;
            }

            $html .= $row_html;
        }

        $html .= '</tbody></table></div>';

        $notices = array();
        if (!empty($data['truncated_rows']))
        {
            $limit = isset($data['max_rows']) ? (int) $data['max_rows'] : xlsx_reader::MAX_ROWS;
            $notices[] = $this->language->lang('EXCEL_TOPIC_TRUNCATED_ROWS', $limit);
        }

        if (!empty($data['truncated_columns']))
        {
            $limit = isset($data['max_columns']) ? (int) $data['max_columns'] : xlsx_reader::MAX_COLUMNS;
            $notices[] = $this->language->lang('EXCEL_TOPIC_TRUNCATED_COLUMNS', $limit);
        }

        if (!empty($data['truncated_cells']))
        {
            $limit = isset($data['max_cell_characters'])
                ? (int) $data['max_cell_characters']
                : xlsx_reader::MAX_CELL_CHARACTERS;
            $notices[] = $this->language->lang('EXCEL_TOPIC_TRUNCATED_CELLS', $limit);
        }

        if (!empty($data['truncated_content']))
        {
            $limit = isset($data['max_total_cell_bytes'])
                ? (int) $data['max_total_cell_bytes']
                : xlsx_reader::MAX_TOTAL_CELL_BYTES;
            $notices[] = $this->language->lang(
                'EXCEL_TOPIC_TRUNCATED_CONTENT',
                max(1, (int) ceil($limit / 1048576))
            );
        }

        if (!empty($data['hidden_rows_skipped']))
        {
            $notices[] = $this->language->lang('EXCEL_TOPIC_HIDDEN_ROWS_SKIPPED', (int) $data['hidden_rows_skipped']);
        }

        if ($output_truncated)
        {
            $notices[] = $this->language->lang(
                'EXCEL_TOPIC_TRUNCATED_OUTPUT',
                max(1, (int) ceil($maximum / 1048576))
            );
        }

        foreach ($notices as $notice)
        {
            $fragment = '<p class="excel-topic-table-notice">' . $this->escape($notice) . '</p>';
            if (strlen($html) + strlen($fragment) + strlen('</section>') < $maximum)
            {
                $html .= $fragment;
            }
        }

        $html .= '</section>';
        return $html;
    }

    /**
     * @param string $sheet_name
     * @param string $updated_at
     * @param int|null $row_count
     * @param int|null $column_count
     * @return string
     */
    protected function build_metadata_html($sheet_name, $updated_at, $row_count, $column_count)
    {
        $items = array();

        if ($sheet_name !== '')
        {
            $items[] = $this->language->lang('EXCEL_TOPIC_META_SHEET', $sheet_name);
        }

        if ($updated_at !== '')
        {
            $items[] = $this->language->lang('EXCEL_TOPIC_META_UPDATED', $updated_at);
        }

        if ($row_count !== null && $column_count !== null)
        {
            $items[] = $this->language->lang('EXCEL_TOPIC_META_DIMENSIONS', (int) $row_count, (int) $column_count);
        }

        if (empty($items))
        {
            return '';
        }

        $escaped = array();
        foreach ($items as $item)
        {
            $escaped[] = '<span>' . $this->escape($item) . '</span>';
        }

        return '<div class="excel-topic-table-meta">' . implode(' <span aria-hidden="true">&middot;</span> ', $escaped) . '</div>';
    }

    /**
     * Render meaningful cells located above a structured Excel table.
     * Single-value rows become title lines; multi-value rows become
     * compact label/value pairs. Empty decorative rows are ignored.
     *
     * @param array<int, array<int, string>> $rows
     * @return string
     */
    protected function render_sheet_context(array $rows)
    {
        if (empty($rows))
        {
            return '';
        }

        $html = '<div class="excel-topic-sheet-context">';
        $rendered = 0;

        foreach ($rows as $row)
        {
            if (!is_array($row))
            {
                continue;
            }

            $values = array();
            foreach ($row as $value)
            {
                $value = trim((string) $value);
                if ($value !== '')
                {
                    $values[] = $value;
                }
            }

            if (empty($values))
            {
                continue;
            }

            if (count($values) === 1)
            {
                $class = $rendered === 0
                    ? 'excel-topic-sheet-context-title'
                    : 'excel-topic-sheet-context-subtitle';
                $html .= '<div class="' . $class . '">' . $this->escape($values[0]) . '</div>';
            }
            else
            {
                $label = array_shift($values);
                $html .= '<div class="excel-topic-sheet-context-pair">'
                    . '<span class="excel-topic-sheet-context-label">' . $this->escape($label) . '</span>'
                    . '<span class="excel-topic-sheet-context-value">' . $this->escape(implode(' · ', $values)) . '</span>'
                    . '</div>';
            }

            $rendered++;
        }

        return $rendered > 0 ? $html . '</div>' : '';
    }

    /**
     * @param array<string, mixed> $attachment
     * @return string
     */
    protected function format_attachment_time(array $attachment)
    {
        $time = isset($attachment['filetime']) ? (int) $attachment['filetime'] : 0;
        if ($time <= 0)
        {
            return '';
        }

        try
        {
            if (is_object($this->user) && method_exists($this->user, 'format_date'))
            {
                return (string) $this->user->format_date($time);
            }
        }
        catch (\Throwable $ignored)
        {
            // Fall back to a neutral UTC timestamp below.
        }

        return gmdate('Y-m-d H:i', $time) . ' UTC';
    }

    /**
     * @param string $filename
     * @param string $language_key
     * @return string
     */
    protected function render_error($filename, $language_key)
    {
        $message = $this->language->lang($language_key);
        return '<section class="excel-topic-table-card excel-topic-table-error">'
            . '<div class="excel-topic-table-title">' . $this->escape($filename) . '</div>'
            . '<p class="excel-topic-table-notice">' . $this->escape($message) . '</p>'
            . '</section>';
    }

    /**
     * @param string $value
     * @param int $maximum_bytes
     * @return string
     */
    protected function sanitize_log_value($value, $maximum_bytes)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', (string) $value);
        $value = trim((string) $value);

        if (preg_match('//u', $value) !== 1)
        {
            $value = preg_replace('/[^\x20-\x7E]/', '?', $value);
            $value = (string) $value;
        }

        if (strlen($value) > $maximum_bytes)
        {
            $value = substr($value, 0, $maximum_bytes);
            for ($attempt = 0; $attempt < 4 && preg_match('//u', $value) !== 1; $attempt++)
            {
                $value = substr($value, 0, -1);
            }
        }

        // phpBB log language strings may contain trusted markup. Escape every
        // value derived from the attachment or exception before interpolation.
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $value
     * @return string
     */
    protected function escape($value)
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
