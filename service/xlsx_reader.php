<?php
/**
 * Excel Topics extension for phpBB.
 *
 * Minimal, read-only OOXML reader. It deliberately supports only the subset
 * required to turn an attached .xlsx worksheet into a safe HTML table.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\service;

use mundophpbb\exceltopics\exception\xlsx_exception;

class xlsx_reader
{
    const MAX_FILE_BYTES = 5242880;        // 5 MiB compressed XLSX file
    const MAX_XML_BYTES = 20971520;        // 20 MiB per XML part
    const MAX_ROWS = 1000;
    const MAX_COLUMNS = 50;
    const MAX_CONTEXT_ROWS = 12;
    const MAX_CELL_CHARACTERS = 4096;
    const MAX_TOTAL_CELL_BYTES = 2097152;  // 2 MiB of displayed cell content
    const MAX_SHARED_STRINGS = 100000;
    const MAX_SHARED_STRING_BYTES = 8388608; // 8 MiB of shared string text
    const MAX_STYLES = 10000;
    const MAX_ROW_XML_BYTES = 4194304;     // 4 MiB for a single worksheet row
    const MAX_ARCHIVE_ENTRIES = 2000;
    const MAX_TOTAL_UNCOMPRESSED_BYTES = 62914560; // 60 MiB

    /**
     * Read one worksheet from an XLSX file.
     *
     * @param string $path Absolute path to the attachment
     * @return array<string, mixed>
     * @throws xlsx_exception
     */
    public function read($path)
    {
        $this->assert_runtime();

        if ($path === '' || !is_file($path) || !is_readable($path))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_FILE_MISSING');
        }

        $file_size = @filesize($path);
        if ($file_size === false || $file_size <= 0)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_FILE_MISSING');
        }

        if ($file_size > self::MAX_FILE_BYTES)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_FILE_TOO_LARGE');
        }

        $zip = new \ZipArchive();
        $open_result = $zip->open($path);
        if ($open_result !== true)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX', 'ZipArchive error: ' . $open_result);
        }

        try
        {
            $this->assert_archive_limits($zip);

            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false)
            {
                throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX');
            }

            $workbook_xml = $this->get_zip_entry($zip, 'xl/workbook.xml', 'EXCEL_TOPIC_ERROR_WORKBOOK');
            $relationships_xml = $this->get_zip_entry($zip, 'xl/_rels/workbook.xml.rels', 'EXCEL_TOPIC_ERROR_WORKBOOK');
            $workbook = $this->parse_workbook($workbook_xml, $relationships_xml);
            $sheet = $this->select_sheet($workbook['sheets'], $workbook['active_tab']);

            $shared_strings = array();
            if ($zip->locateName('xl/sharedStrings.xml') !== false)
            {
                $shared_strings_xml = $this->get_zip_entry($zip, 'xl/sharedStrings.xml', 'EXCEL_TOPIC_ERROR_READ');
                $shared_strings = $this->parse_shared_strings($shared_strings_xml);
            }

            $styles = array();
            if ($zip->locateName('xl/styles.xml') !== false)
            {
                $styles_xml = $this->get_zip_entry($zip, 'xl/styles.xml', 'EXCEL_TOPIC_ERROR_READ');
                $styles = $this->parse_styles($styles_xml);
            }

            $worksheet_xml = $this->get_zip_entry($zip, $sheet['path'], 'EXCEL_TOPIC_ERROR_SHEET');
            $structured_table = $this->find_first_structured_table($zip, $sheet['path'], $worksheet_xml);
            $worksheet = $this->parse_worksheet(
                $worksheet_xml,
                $shared_strings,
                $styles,
                !empty($workbook['date_1904']),
                $structured_table !== null ? $structured_table['range'] : null,
                $structured_table !== null
            );

            if ($structured_table !== null
                && empty($structured_table['header_row_count'])
                && !empty($structured_table['column_names']))
            {
                if (count($worksheet['rows']) >= self::MAX_ROWS)
                {
                    array_pop($worksheet['rows']);
                    $worksheet['truncated_rows'] = true;
                }

                array_unshift($worksheet['rows'], array_slice(
                    array_pad($structured_table['column_names'], min($structured_table['range']['width'], self::MAX_COLUMNS), ''),
                    0,
                    min($structured_table['range']['width'], self::MAX_COLUMNS)
                ));
            }

            return array(
                'sheet_name' => $sheet['name'],
                'table_name' => $structured_table !== null ? $structured_table['name'] : '',
                'table_range' => $structured_table !== null ? $structured_table['reference'] : '',
                'selected_structured_table' => $structured_table !== null,
                'context_rows' => $worksheet['context_rows'],
                'rows' => $worksheet['rows'],
                'truncated_rows' => $worksheet['truncated_rows'],
                'truncated_columns' => $worksheet['truncated_columns'],
                'truncated_cells' => $worksheet['truncated_cells'],
                'truncated_content' => $worksheet['truncated_content'],
                'hidden_rows_skipped' => $worksheet['hidden_rows_skipped'],
                'selected_by_active_tab' => $sheet['selected_by_active_tab'],
                'max_rows' => self::MAX_ROWS,
                'max_columns' => self::MAX_COLUMNS,
                'max_cell_characters' => self::MAX_CELL_CHARACTERS,
                'max_total_cell_bytes' => self::MAX_TOTAL_CELL_BYTES,
            );
        }
        catch (xlsx_exception $exception)
        {
            throw $exception;
        }
        catch (\Throwable $exception)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_READ', $exception->getMessage(), $exception);
        }
        finally
        {
            $zip->close();
        }
    }

    /**
     * @return void
     * @throws xlsx_exception
     */
    protected function assert_runtime()
    {
        if (!class_exists('ZipArchive'))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_ZIP_EXTENSION');
        }

        if (!class_exists('XMLReader') || !function_exists('simplexml_load_string'))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_XML_EXTENSION');
        }
    }

    /**
     * Reject archives with an excessive number of entries or expansion size.
     *
     * @param \ZipArchive $zip
     * @return void
     * @throws xlsx_exception
     */
    protected function assert_archive_limits(\ZipArchive $zip)
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES)
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX', 'Too many ZIP entries');
        }

        $total_uncompressed = 0;
        for ($index = 0; $index < $zip->numFiles; $index++)
        {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['size']))
            {
                throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX', 'Unable to inspect ZIP entry');
            }

            $total_uncompressed += (int) $stat['size'];
            if ($total_uncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES)
            {
                throw new xlsx_exception('EXCEL_TOPIC_ERROR_INVALID_XLSX', 'Expanded XLSX is too large');
            }
        }
    }

    /**
     * @param \ZipArchive $zip
     * @param string $name
     * @param string $error_key
     * @return string
     * @throws xlsx_exception
     */
    protected function get_zip_entry(\ZipArchive $zip, $name, $error_key)
    {
        $stat = $zip->statName($name);
        if ($stat === false || !isset($stat['size']) || (int) $stat['size'] > self::MAX_XML_BYTES)
        {
            throw new xlsx_exception($error_key, 'Missing or oversized XLSX part: ' . $name);
        }

        $contents = $zip->getFromName($name);
        if ($contents === false)
        {
            throw new xlsx_exception($error_key, 'Unable to read XLSX part: ' . $name);
        }

        return $contents;
    }

    /**
     * @param string $workbook_xml
     * @param string $relationships_xml
     * @return array<string, mixed>
     * @throws xlsx_exception
     */
    protected function parse_workbook($workbook_xml, $relationships_xml)
    {
        $workbook = $this->load_xml($workbook_xml, 'EXCEL_TOPIC_ERROR_WORKBOOK');
        $relationships = $this->load_xml($relationships_xml, 'EXCEL_TOPIC_ERROR_WORKBOOK');

        $workbook_namespaces = $workbook->getNamespaces(true);
        $main_namespace = isset($workbook_namespaces[''])
            ? $workbook_namespaces['']
            : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationship_namespace = isset($workbook_namespaces['r'])
            ? $workbook_namespaces['r']
            : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $relationship_namespaces = $relationships->getNamespaces(true);
        $package_relationship_namespace = isset($relationship_namespaces[''])
            ? $relationship_namespaces['']
            : 'http://schemas.openxmlformats.org/package/2006/relationships';

        $relationship_map = array();
        $relationship_children = $relationships->children($package_relationship_namespace);
        foreach ($relationship_children->Relationship as $relationship)
        {
            $attributes = $relationship->attributes();
            $id = (string) $attributes['Id'];
            $target = (string) $attributes['Target'];
            $type = (string) $attributes['Type'];

            if ($id !== '' && $target !== '' && substr($type, -10) === '/worksheet')
            {
                $relationship_map[$id] = $this->resolve_zip_path('xl/workbook.xml', $target);
            }
        }

        $sheets = array();
        $main_children = $workbook->children($main_namespace);
        foreach ($main_children->sheets as $sheets_container)
        {
            $sheet_children = $sheets_container->children($main_namespace);
            foreach ($sheet_children->sheet as $sheet)
            {
                $attributes = $sheet->attributes();
                $relationship_attributes = $sheet->attributes($relationship_namespace);
                $relationship_id = (string) $relationship_attributes['id'];

                if ($relationship_id === '' || empty($relationship_map[$relationship_id]))
                {
                    continue;
                }

                $sheets[] = array(
                    'name' => (string) $attributes['name'],
                    'state' => (string) $attributes['state'],
                    'path' => $relationship_map[$relationship_id],
                );
            }
        }

        if (empty($sheets))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_WORKBOOK', 'No worksheets found');
        }

        $date_1904 = false;
        foreach ($main_children->workbookPr as $workbook_properties)
        {
            $attributes = $workbook_properties->attributes();
            $value = strtolower((string) $attributes['date1904']);
            $date_1904 = ($value === '1' || $value === 'true');
            break;
        }

        $active_tab = null;
        foreach ($main_children->bookViews as $book_views_container)
        {
            $book_views = $book_views_container->children($main_namespace);
            foreach ($book_views->workbookView as $workbook_view)
            {
                $attributes = $workbook_view->attributes();
                $value = (string) $attributes['activeTab'];
                if ($value !== '' && ctype_digit($value))
                {
                    $active_tab = (int) $value;
                    break 2;
                }
            }
        }

        return array(
            'sheets' => $sheets,
            'date_1904' => $date_1904,
            'active_tab' => $active_tab,
        );
    }

    /**
     * Select the workbook's active visible worksheet, or the first visible
     * worksheet when the active sheet is hidden or unavailable.
     *
     * @param array<int, array<string, string>> $sheets
     * @param int|null $active_tab
     * @return array<string, string|bool>
     */
    protected function select_sheet(array $sheets, $active_tab = null)
    {
        if (is_int($active_tab) && isset($sheets[$active_tab]))
        {
            $active_sheet = $sheets[$active_tab];
            $state = strtolower($active_sheet['state']);
            if ($state === '' || $state === 'visible')
            {
                $active_sheet['selected_by_active_tab'] = true;
                return $active_sheet;
            }
        }

        foreach ($sheets as $sheet)
        {
            $state = strtolower($sheet['state']);
            if ($state === '' || $state === 'visible')
            {
                $sheet['selected_by_active_tab'] = false;
                return $sheet;
            }
        }

        $sheets[0]['selected_by_active_tab'] = false;
        return $sheets[0];
    }

    /**
     * Return the first structured Excel table linked to the selected worksheet.
     * Invalid or incomplete table metadata is ignored so older workbooks keep
     * the original whole-sheet fallback.
     *
     * @param \ZipArchive $zip
     * @param string $worksheet_path
     * @param string $worksheet_xml
     * @return array<string, mixed>|null
     */
    protected function find_first_structured_table(\ZipArchive $zip, $worksheet_path, $worksheet_xml)
    {
        try
        {
            $worksheet = $this->load_xml($worksheet_xml, 'EXCEL_TOPIC_ERROR_SHEET');
            $namespaces = $worksheet->getNamespaces(true);
            $relationship_namespace = isset($namespaces['r'])
                ? $namespaces['r']
                : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            $table_parts = $worksheet->xpath('.//*[local-name()="tableParts"]/*[local-name()="tablePart"]');
            if ($table_parts === false || empty($table_parts))
            {
                return null;
            }

            $relationship_ids = array();
            foreach ($table_parts as $table_part)
            {
                $attributes = $table_part->attributes($relationship_namespace);
                $relationship_id = (string) $attributes['id'];
                if ($relationship_id !== '')
                {
                    $relationship_ids[] = $relationship_id;
                }
            }

            if (empty($relationship_ids))
            {
                return null;
            }

            $relationships_path = $this->worksheet_relationships_path($worksheet_path);
            if ($zip->locateName($relationships_path) === false)
            {
                return null;
            }

            $relationships_xml = $this->get_zip_entry(
                $zip,
                $relationships_path,
                'EXCEL_TOPIC_ERROR_SHEET'
            );
            $relationships = $this->load_xml($relationships_xml, 'EXCEL_TOPIC_ERROR_SHEET');
            $relationship_namespaces = $relationships->getNamespaces(true);
            $package_namespace = isset($relationship_namespaces[''])
                ? $relationship_namespaces['']
                : 'http://schemas.openxmlformats.org/package/2006/relationships';

            $table_paths = array();
            $relationship_children = $relationships->children($package_namespace);
            foreach ($relationship_children->Relationship as $relationship)
            {
                $attributes = $relationship->attributes();
                $id = (string) $attributes['Id'];
                $target = (string) $attributes['Target'];
                $type = (string) $attributes['Type'];

                if ($id !== '' && $target !== '' && substr($type, -6) === '/table')
                {
                    $table_paths[$id] = $this->resolve_zip_path($worksheet_path, $target);
                }
            }

            foreach ($relationship_ids as $relationship_id)
            {
                if (empty($table_paths[$relationship_id]) || $zip->locateName($table_paths[$relationship_id]) === false)
                {
                    continue;
                }

                try
                {
                    $table_xml = $this->get_zip_entry(
                        $zip,
                        $table_paths[$relationship_id],
                        'EXCEL_TOPIC_ERROR_SHEET'
                    );
                    $table = $this->parse_structured_table($table_xml);
                    if ($table !== null)
                    {
                        return $table;
                    }
                }
                catch (\Throwable $exception)
                {
                    // Try another structured table before falling back to the
                    // worksheet as a whole.
                    continue;
                }
            }
        }
        catch (xlsx_exception $exception)
        {
            // A malformed optional table definition must not make an otherwise
            // readable worksheet unavailable. Preserve the whole-sheet fallback.
            return null;
        }
        catch (\Throwable $exception)
        {
            return null;
        }

        return null;
    }

    /**
     * @param string $worksheet_path
     * @return string
     */
    protected function worksheet_relationships_path($worksheet_path)
    {
        $worksheet_path = str_replace('\\', '/', (string) $worksheet_path);
        return dirname($worksheet_path) . '/_rels/' . basename($worksheet_path) . '.rels';
    }

    /**
     * @param string $xml
     * @return array<string, mixed>|null
     */
    protected function parse_structured_table($xml)
    {
        $table = $this->load_xml($xml, 'EXCEL_TOPIC_ERROR_SHEET');
        $attributes = $table->attributes();
        $reference = (string) $attributes['ref'];
        $range = $this->parse_range_reference($reference);
        if ($range === null)
        {
            return null;
        }

        $header_row_count = 1;
        if (isset($attributes['headerRowCount']) && (string) $attributes['headerRowCount'] !== '')
        {
            $header_row_count = max(0, (int) $attributes['headerRowCount']);
        }

        $column_names = array();
        $column_nodes = $table->xpath('.//*[local-name()="tableColumns"]/*[local-name()="tableColumn"]');
        if ($column_nodes !== false)
        {
            foreach ($column_nodes as $column)
            {
                $column_attributes = $column->attributes();
                $column_names[] = (string) $column_attributes['name'];
            }
        }

        $display_name = (string) $attributes['displayName'];
        $table_name = $display_name !== '' ? $display_name : (string) $attributes['name'];

        return array(
            'name' => $table_name,
            'reference' => $reference,
            'range' => $range,
            'header_row_count' => $header_row_count,
            'column_names' => $column_names,
        );
    }

    /**
     * Parse a rectangular A1 range such as B7:E16.
     *
     * @param string $reference
     * @return array<string, int>|null
     */
    protected function parse_range_reference($reference)
    {
        $reference = trim((string) $reference);
        if (!preg_match('/^\\$?([A-Z]{1,3})\\$?([1-9][0-9]*)(?::\\$?([A-Z]{1,3})\\$?([1-9][0-9]*))?$/i', $reference, $matches))
        {
            return null;
        }

        $start_column = $this->column_from_reference($matches[1] . $matches[2]);
        $start_row = (int) $matches[2];
        $end_column = isset($matches[3]) && $matches[3] !== ''
            ? $this->column_from_reference($matches[3] . $matches[4])
            : $start_column;
        $end_row = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : $start_row;

        if ($end_column < $start_column || $end_row < $start_row
            || $start_column > 16384 || $end_column > 16384
            || $start_row > 1048576 || $end_row > 1048576)
        {
            return null;
        }

        return array(
            'start_column' => $start_column,
            'end_column' => $end_column,
            'start_row' => $start_row,
            'end_row' => $end_row,
            'width' => ($end_column - $start_column) + 1,
            'height' => ($end_row - $start_row) + 1,
        );
    }

    /**
     * @param string $xml
     * @return array<int, string>
     * @throws xlsx_exception
     */
    protected function parse_shared_strings($xml)
    {
        $this->assert_safe_xml($xml, 'EXCEL_TOPIC_ERROR_READ');

        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_READ', 'Unable to open shared strings XML');
        }

        $strings = array();
        $total_bytes = 0;
        try
        {
            while ($reader->read())
            {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'si')
                {
                    continue;
                }

                if (count($strings) >= self::MAX_SHARED_STRINGS)
                {
                    throw new xlsx_exception(
                        'EXCEL_TOPIC_ERROR_SHARED_STRINGS_LIMIT',
                        'Shared string count exceeds safe limit'
                    );
                }

                $value = $this->extract_text_nodes($reader->readOuterXML());
                $total_bytes += strlen($value);
                if ($total_bytes > self::MAX_SHARED_STRING_BYTES)
                {
                    throw new xlsx_exception(
                        'EXCEL_TOPIC_ERROR_SHARED_STRINGS_LIMIT',
                        'Shared string content exceeds safe limit'
                    );
                }

                $strings[] = $value;
            }
        }
        finally
        {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Build a style-index to number-format-code map.
     *
     * @param string $xml
     * @return array<int, string>
     * @throws xlsx_exception
     */
    protected function parse_styles($xml)
    {
        $styles_document = $this->load_xml($xml, 'EXCEL_TOPIC_ERROR_READ');
        $namespaces = $styles_document->getNamespaces(true);
        $main_namespace = isset($namespaces[''])
            ? $namespaces['']
            : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $main_children = $styles_document->children($main_namespace);

        $custom_formats = array();
        foreach ($main_children->numFmts as $formats_container)
        {
            $formats = $formats_container->children($main_namespace);
            foreach ($formats->numFmt as $format)
            {
                if (count($custom_formats) >= self::MAX_STYLES)
                {
                    throw new xlsx_exception(
                        'EXCEL_TOPIC_ERROR_STYLES_LIMIT',
                        'Custom number format count exceeds safe limit'
                    );
                }

                $attributes = $format->attributes();
                $custom_formats[(int) $attributes['numFmtId']] = (string) $attributes['formatCode'];
            }
        }

        $styles = array();
        foreach ($main_children->cellXfs as $cell_formats_container)
        {
            $cell_formats = $cell_formats_container->children($main_namespace);
            foreach ($cell_formats->xf as $cell_format)
            {
                if (count($styles) >= self::MAX_STYLES)
                {
                    throw new xlsx_exception(
                        'EXCEL_TOPIC_ERROR_STYLES_LIMIT',
                        'Cell style count exceeds safe limit'
                    );
                }

                $attributes = $cell_format->attributes();
                $number_format_id = isset($attributes['numFmtId']) ? (int) $attributes['numFmtId'] : 0;
                $styles[] = isset($custom_formats[$number_format_id])
                    ? $custom_formats[$number_format_id]
                    : $this->built_in_format($number_format_id);
            }
        }

        return $styles;
    }

    /**
     * @param string $xml
     * @param array<int, string> $shared_strings
     * @param array<int, string> $styles
     * @param bool $date_1904
     * @param array<string, int>|null $range
     * @return array<string, mixed>
     * @throws xlsx_exception
     */
    protected function parse_worksheet($xml, array $shared_strings, array $styles, $date_1904, ?array $range = null, $capture_context = false)
    {
        $this->assert_safe_xml($xml, 'EXCEL_TOPIC_ERROR_SHEET');

        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT))
        {
            throw new xlsx_exception('EXCEL_TOPIC_ERROR_SHEET', 'Unable to open worksheet XML');
        }

        $rows = array();
        $context_rows = array();
        $truncated_rows = false;
        $truncated_columns = $range !== null && $range['width'] > self::MAX_COLUMNS;
        $truncated_cells = 0;
        $truncated_content = false;
        $total_cell_bytes = 0;
        $hidden_rows_skipped = 0;
        $sequential_row = 1;
        $expected_table_row = $range !== null ? $range['start_row'] : null;
        $blank_table_row = $range !== null
            ? array_fill(0, min($range['width'], self::MAX_COLUMNS), '')
            : array();

        try
        {
            while ($reader->read())
            {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row')
                {
                    continue;
                }

                $row_reference = (string) $reader->getAttribute('r');
                $row_number = ($row_reference !== '' && ctype_digit($row_reference))
                    ? (int) $row_reference
                    : $sequential_row;
                $sequential_row = max($sequential_row + 1, $row_number + 1);

                $is_context_row = $range !== null && $row_number < $range['start_row'];
                if ($is_context_row && !$capture_context)
                {
                    continue;
                }

                if ($range !== null && $row_number > $range['end_row'])
                {
                    break;
                }

                $hidden = strtolower((string) $reader->getAttribute('hidden'));
                if ($hidden === '1' || $hidden === 'true')
                {
                    if (!$is_context_row)
                    {
                        $hidden_rows_skipped++;
                        if ($expected_table_row !== null)
                        {
                            $expected_table_row = max($expected_table_row, $row_number + 1);
                        }
                    }
                    continue;
                }

                $row_xml = $reader->readOuterXML();
                if ($row_xml === false || $row_xml === '')
                {
                    throw new xlsx_exception('EXCEL_TOPIC_ERROR_SHEET', 'Unable to read worksheet row XML');
                }

                if (strlen($row_xml) > self::MAX_ROW_XML_BYTES)
                {
                    throw new xlsx_exception(
                        'EXCEL_TOPIC_ERROR_SHEET_COMPLEX',
                        'A worksheet row exceeds the safe XML size limit'
                    );
                }

                $parsed_row = $this->parse_row(
                    $row_xml,
                    $shared_strings,
                    $styles,
                    $date_1904,
                    $range
                );

                if ($parsed_row['truncated_columns'])
                {
                    $truncated_columns = true;
                }

                $truncated_cells += (int) $parsed_row['truncated_cells'];

                if ($is_context_row)
                {
                    if ($parsed_row['has_value'])
                    {
                        $context_rows[] = $parsed_row['cells'];
                        if (count($context_rows) > self::MAX_CONTEXT_ROWS)
                        {
                            array_shift($context_rows);
                        }
                    }
                    continue;
                }

                if ($range === null && !$parsed_row['has_value'])
                {
                    continue;
                }

                if ($range !== null && $expected_table_row !== null && $row_number > $expected_table_row)
                {
                    while ($expected_table_row < $row_number && $expected_table_row <= $range['end_row'])
                    {
                        if (count($rows) >= self::MAX_ROWS)
                        {
                            $truncated_rows = true;
                            break 2;
                        }
                        $rows[] = $blank_table_row;
                        $expected_table_row++;
                    }
                }

                if (count($rows) >= self::MAX_ROWS)
                {
                    $truncated_rows = true;
                    break;
                }

                $row_bytes = 0;
                foreach ($parsed_row['cells'] as $value)
                {
                    $row_bytes += strlen((string) $value);
                }

                if ($total_cell_bytes + $row_bytes > self::MAX_TOTAL_CELL_BYTES)
                {
                    $truncated_content = true;
                    break;
                }

                $total_cell_bytes += $row_bytes;
                $rows[] = $parsed_row['cells'];
                if ($expected_table_row !== null)
                {
                    $expected_table_row = $row_number + 1;
                }
            }

            if ($range !== null && !$truncated_rows && !$truncated_content && $expected_table_row !== null)
            {
                while ($expected_table_row <= $range['end_row'])
                {
                    if (count($rows) >= self::MAX_ROWS)
                    {
                        $truncated_rows = true;
                        break;
                    }
                    $rows[] = $blank_table_row;
                    $expected_table_row++;
                }
            }
        }
        finally
        {
            $reader->close();
        }

        return array(
            'rows' => $rows,
            'context_rows' => $context_rows,
            'truncated_rows' => $truncated_rows,
            'truncated_columns' => $truncated_columns,
            'truncated_cells' => $truncated_cells,
            'truncated_content' => $truncated_content,
            'hidden_rows_skipped' => $hidden_rows_skipped,
        );
    }

    /**
     * @param string $row_xml
     * @param array<int, string> $shared_strings
     * @param array<int, string> $styles
     * @param bool $date_1904
     * @param array<string, int>|null $range
     * @return array<string, mixed>
     * @throws xlsx_exception
     */
    protected function parse_row($row_xml, array $shared_strings, array $styles, $date_1904, ?array $range = null)
    {
        $row = $this->load_xml($row_xml, 'EXCEL_TOPIC_ERROR_SHEET');
        // local-name() also works when readOuterXML() does not repeat an
        // inherited default namespace declaration on the row fragment.
        $cell_nodes = $row->xpath('./*[local-name()="c"]');
        if ($cell_nodes === false)
        {
            $cell_nodes = array();
        }

        $visible_width = $range !== null ? min($range['width'], self::MAX_COLUMNS) : 0;
        $cells = $range !== null ? array_fill(0, $visible_width, '') : array();
        $has_value = false;
        $truncated_columns = false;
        $truncated_cells = 0;
        $sequential_column = 1;

        foreach ($cell_nodes as $cell)
        {
            $attributes = $cell->attributes();
            $reference = (string) $attributes['r'];
            $column = $reference !== '' ? $this->column_from_reference($reference) : $sequential_column;
            $sequential_column = max($sequential_column + 1, $column + 1);

            if ($range !== null && ($column < $range['start_column'] || $column > $range['end_column']))
            {
                continue;
            }

            $value = $this->cell_value($cell, $shared_strings, $styles, $date_1904);
            $was_truncated = false;
            $value = $this->limit_cell_value($value, $was_truncated);
            if ($was_truncated)
            {
                $truncated_cells++;
            }

            $display_column = $range !== null
                ? ($column - $range['start_column']) + 1
                : $column;

            if ($display_column > self::MAX_COLUMNS)
            {
                if ($value !== '')
                {
                    $truncated_columns = true;
                }
                continue;
            }

            if ($range === null)
            {
                while (count($cells) < $display_column)
                {
                    $cells[] = '';
                }
            }

            $cells[$display_column - 1] = $value;
            if ($value !== '')
            {
                $has_value = true;
            }
        }

        if ($range === null)
        {
            while (!empty($cells) && end($cells) === '')
            {
                array_pop($cells);
            }
        }

        return array(
            'cells' => $cells,
            'has_value' => $has_value,
            'truncated_columns' => $truncated_columns,
            'truncated_cells' => $truncated_cells,
        );
    }

    /**
     * @param \SimpleXMLElement $cell
     * @param array<int, string> $shared_strings
     * @param array<int, string> $styles
     * @param bool $date_1904
     * @return string
     */
    protected function cell_value($cell, array $shared_strings, array $styles, $date_1904)
    {
        $attributes = $cell->attributes();
        $type = (string) $attributes['t'];
        $style_index = isset($attributes['s']) ? (int) $attributes['s'] : 0;
        if ($type === 'inlineStr')
        {
            return $this->extract_text_nodes($cell->asXML());
        }

        $value_nodes = $cell->xpath('./*[local-name()="v"]');
        $raw = ($value_nodes !== false && isset($value_nodes[0])) ? (string) $value_nodes[0] : '';

        if ($raw === '')
        {
            return '';
        }

        if ($type === 's')
        {
            $index = (int) $raw;
            return isset($shared_strings[$index]) ? $shared_strings[$index] : '';
        }

        if ($type === 'b')
        {
            return $raw === '1' ? 'TRUE' : 'FALSE';
        }

        if ($type === 'str' || $type === 'e' || $type === 'd')
        {
            return $raw;
        }

        $format_code = isset($styles[$style_index]) ? $styles[$style_index] : '';
        return $this->format_numeric_value($raw, $format_code, $date_1904);
    }

    /**
     * Limit a cell value without requiring the optional mbstring extension.
     * XLSX XML is UTF-8, so a Unicode-aware PCRE expression can count code points.
     *
     * @param string $value
     * @param bool $was_truncated
     * @return string
     */
    protected function limit_cell_value($value, &$was_truncated)
    {
        $value = (string) $value;
        $was_truncated = false;

        if ($value === '' || strlen($value) <= self::MAX_CELL_CHARACTERS)
        {
            return $value;
        }

        $maximum = self::MAX_CELL_CHARACTERS;
        if (preg_match('/^.{0,' . $maximum . '}$/us', $value) === 1)
        {
            return $value;
        }

        if (preg_match('/^(.{0,' . $maximum . '})/us', $value, $matches) === 1)
        {
            $was_truncated = true;
            return $matches[1];
        }

        // Defensive fallback for malformed input that should not pass XML parsing.
        $was_truncated = true;
        return substr($value, 0, $maximum);
    }

    /**
     * @param string $raw
     * @param string $format_code
     * @param bool $date_1904
     * @return string
     */
    protected function format_numeric_value($raw, $format_code, $date_1904)
    {
        if (!is_numeric($raw))
        {
            return $raw;
        }

        $number = (float) $raw;
        if ($format_code !== '' && $this->is_date_format($format_code))
        {
            return $this->format_excel_date($number, $format_code, $date_1904);
        }

        if ($format_code !== '' && strpos($format_code, '%') !== false)
        {
            $decimals = 0;
            if (preg_match('/\.([0#]+)[^%]*%/', $format_code, $matches))
            {
                $decimals = strlen($matches[1]);
            }
            return number_format($number * 100, $decimals, '.', '') . '%';
        }

        $primary_format = explode(';', $format_code);
        $primary_format = isset($primary_format[0]) ? $primary_format[0] : '';

        if (preg_match('/^0+$/', $primary_format) && floor($number) == $number)
        {
            return str_pad((string) (int) $number, strlen($primary_format), '0', STR_PAD_LEFT);
        }

        if (preg_match('/^[^\.]*\.([0#]+)/', $primary_format, $matches))
        {
            $decimals = strlen($matches[1]);
            $thousands = strpos($primary_format, ',') !== false ? ',' : '';
            return number_format($number, $decimals, '.', $thousands);
        }

        if (preg_match('/[0#],[0#]{3}/', $primary_format) && floor($number) == $number)
        {
            return number_format($number, 0, '.', ',');
        }

        return $raw;
    }

    /**
     * @param float $serial
     * @param string $format_code
     * @param bool $date_1904
     * @return string
     */
    protected function format_excel_date($serial, $format_code, $date_1904)
    {
        $days = (int) floor($serial);
        $fraction = $serial - $days;

        if (!$date_1904 && $days >= 60)
        {
            // Excel's 1900 date system includes the non-existent 1900-02-29.
            $days--;
        }

        $base = new \DateTimeImmutable($date_1904 ? '1904-01-01 00:00:00' : '1899-12-31 00:00:00', new \DateTimeZone('UTC'));
        if ($days !== 0)
        {
            $base = $base->modify(($days > 0 ? '+' : '') . $days . ' days');
        }

        $seconds = (int) round($fraction * 86400);
        if ($seconds !== 0)
        {
            $base = $base->modify(($seconds > 0 ? '+' : '') . $seconds . ' seconds');
        }

        $clean_format = $this->clean_number_format($format_code);
        $has_date = (bool) preg_match('/[yd]/i', $clean_format) || (bool) preg_match('/m{3,}/i', $clean_format);
        $has_time = (bool) preg_match('/[hs]/i', $clean_format);
        $has_seconds = (bool) preg_match('/s/i', $clean_format);

        if ($has_date && $has_time)
        {
            return $base->format($has_seconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
        }

        if ($has_time && !$has_date)
        {
            return $base->format($has_seconds ? 'H:i:s' : 'H:i');
        }

        return $base->format('Y-m-d');
    }

    /**
     * @param string $format_code
     * @return bool
     */
    protected function is_date_format($format_code)
    {
        $clean = $this->clean_number_format($format_code);
        return (bool) preg_match('/[ydhs]/i', $clean) || (bool) preg_match('/m{3,}/i', $clean);
    }

    /**
     * Remove quoted literals, escaped characters and most bracket directives.
     *
     * @param string $format_code
     * @return string
     */
    protected function clean_number_format($format_code)
    {
        $clean = preg_replace('/"[^"]*"/', '', $format_code);
        $clean = preg_replace('/\\\\./', '', $clean);
        $clean = preg_replace('/[_*]./', '', $clean);
        $clean = preg_replace('/\[(?!h+\]|m+\]|s+\])[^\]]+\]/i', '', $clean);
        return (string) $clean;
    }

    /**
     * @param int $number_format_id
     * @return string
     */
    protected function built_in_format($number_format_id)
    {
        $formats = array(
            0 => 'General',
            1 => '0',
            2 => '0.00',
            3 => '#,##0',
            4 => '#,##0.00',
            9 => '0%',
            10 => '0.00%',
            11 => '0.00E+00',
            14 => 'mm-dd-yy',
            15 => 'd-mmm-yy',
            16 => 'd-mmm',
            17 => 'mmm-yy',
            18 => 'h:mm AM/PM',
            19 => 'h:mm:ss AM/PM',
            20 => 'h:mm',
            21 => 'h:mm:ss',
            22 => 'm/d/yy h:mm',
            37 => '#,##0 ;(#,##0)',
            38 => '#,##0 ;[Red](#,##0)',
            39 => '#,##0.00;(#,##0.00)',
            40 => '#,##0.00;[Red](#,##0.00)',
            45 => 'mm:ss',
            46 => '[h]:mm:ss',
            47 => 'mmss.0',
            49 => '@',
        );

        if (isset($formats[$number_format_id]))
        {
            return $formats[$number_format_id];
        }

        // Locale-specific built-in date formats occupy these ranges.
        if (($number_format_id >= 27 && $number_format_id <= 36)
            || ($number_format_id >= 50 && $number_format_id <= 58))
        {
            return 'yyyy-mm-dd';
        }

        return '';
    }

    /**
     * @param string $xml
     * @return string
     */
    protected function extract_text_nodes($xml)
    {
        if ($xml === false || $xml === '')
        {
            return '';
        }

        $element = $this->load_xml($xml, 'EXCEL_TOPIC_ERROR_READ');
        $nodes = $element->xpath('.//*[local-name()="t"]');
        if ($nodes === false)
        {
            return '';
        }

        $text = '';
        foreach ($nodes as $node)
        {
            $text .= (string) $node;
        }
        return $text;
    }

    /**
     * @param string $reference
     * @return int
     */
    protected function column_from_reference($reference)
    {
        if (!preg_match('/^([A-Z]+)[0-9]+$/i', $reference, $matches))
        {
            return 1;
        }

        $letters = strtoupper($matches[1]);
        $column = 0;
        $length = strlen($letters);

        for ($index = 0; $index < $length; $index++)
        {
            $column = ($column * 26) + (ord($letters[$index]) - 64);
        }

        return max(1, $column);
    }

    /**
     * @param string $workbook_path
     * @param string $target
     * @return string
     * @throws xlsx_exception
     */
    protected function resolve_zip_path($workbook_path, $target)
    {
        $target = str_replace('\\', '/', $target);
        if (substr($target, 0, 1) === '/')
        {
            $combined = ltrim($target, '/');
        }
        else
        {
            $directory = dirname(str_replace('\\', '/', $workbook_path));
            $combined = $directory . '/' . $target;
        }

        $parts = array();
        foreach (explode('/', $combined) as $part)
        {
            if ($part === '' || $part === '.')
            {
                continue;
            }

            if ($part === '..')
            {
                if (empty($parts))
                {
                    throw new xlsx_exception('EXCEL_TOPIC_ERROR_WORKBOOK', 'Invalid worksheet relationship path');
                }
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * Reject DTD/entity declarations before passing XML to libxml.
     *
     * @param string $xml
     * @param string $error_key
     * @return void
     * @throws xlsx_exception
     */
    protected function assert_safe_xml($xml, $error_key)
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false)
        {
            throw new xlsx_exception($error_key, 'DTD and entity declarations are not allowed in XLSX XML');
        }
    }

    /**
     * @param string $xml
     * @param string $error_key
     * @return \SimpleXMLElement
     * @throws xlsx_exception
     */
    protected function load_xml($xml, $error_key)
    {
        $this->assert_safe_xml($xml, $error_key);

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false)
        {
            throw new xlsx_exception($error_key, 'Malformed XML inside XLSX file');
        }

        return $element;
    }

}
