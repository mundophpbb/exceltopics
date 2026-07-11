<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = array();
}

$lang = array_merge($lang, array(
    'EXCEL_TOPIC_FILE_AND_SHEET'          => '%1$s — sheet %2$s',
    'EXCEL_TOPIC_META_SHEET'              => 'Sheet: %s',
    'EXCEL_TOPIC_META_UPDATED'            => 'Updated: %s',
    'EXCEL_TOPIC_META_DIMENSIONS'         => '%1$d rows · %2$d columns',
    'EXCEL_TOPIC_SCROLL_HINT'             => 'Swipe to see more',
    'EXCEL_TOPIC_EMPTY'                   => 'This worksheet has no visible data.',
    'EXCEL_TOPIC_TRUNCATED_ROWS'          => 'Display limited to the first %d non-empty rows.',
    'EXCEL_TOPIC_TRUNCATED_COLUMNS'       => 'Display limited to the first %d columns.',
    'EXCEL_TOPIC_TRUNCATED_CELLS'         => 'Very long cell text was limited to %d characters.',
    'EXCEL_TOPIC_TRUNCATED_CONTENT'       => 'Reading stopped after reaching %d MiB of cell content.',
    'EXCEL_TOPIC_TRUNCATED_OUTPUT'        => 'The displayed table was reduced to keep page HTML within %d MiB.',
    'EXCEL_TOPIC_HIDDEN_ROWS_SKIPPED'     => '%d hidden Excel rows were not displayed.',

    'EXCEL_TOPIC_ERROR_FILE_MISSING'      => 'The attached spreadsheet file is unavailable.',
    'EXCEL_TOPIC_ERROR_FILE_TOO_LARGE'    => 'This spreadsheet exceeds the 5 MiB limit for table rendering.',
    'EXCEL_TOPIC_ERROR_INVALID_XLSX'      => 'The attachment is not a valid XLSX workbook.',
    'EXCEL_TOPIC_ERROR_WORKBOOK'          => 'The XLSX workbook structure could not be read.',
    'EXCEL_TOPIC_ERROR_SHEET'             => 'The selected worksheet could not be read.',
    'EXCEL_TOPIC_ERROR_READ'              => 'The spreadsheet could not be rendered.',
    'EXCEL_TOPIC_ERROR_ZIP_EXTENSION'     => 'The server requires the PHP Zip extension to read XLSX files.',
    'EXCEL_TOPIC_ERROR_XML_EXTENSION'     => 'The server requires PHP XMLReader and SimpleXML to read XLSX files.',
    'EXCEL_TOPIC_ERROR_SHARED_STRINGS_LIMIT' => 'The spreadsheet contains too many shared strings for safe display.',
    'EXCEL_TOPIC_ERROR_STYLES_LIMIT'       => 'The spreadsheet contains too many styles for safe display.',
    'EXCEL_TOPIC_ERROR_SHEET_COMPLEX'      => 'The selected worksheet is too complex for safe display.',
    'EXCEL_TOPIC_ERROR_OUTPUT_TOO_LARGE'   => 'The table produces too much HTML to be displayed safely.',

    'LOG_MUNDOPHPBB_EXCELTOPICS_XLSX_ENABLED' => '<strong>Excel Topics: XLSX support enabled</strong><br />Group: %1$s<br />Maximum size: %2$d bytes',

    'LOG_MUNDOPHPBB_EXCELTOPICS_STORAGE_ERROR' => '<strong>Excel Topics: storage failure</strong><br />Operation: %1$s<br />Attachment: %2$d<br />File: %3$s<br />Detail: %4$s',

    'LOG_MUNDOPHPBB_EXCELTOPICS_RENDER_ERROR' => '<strong>Excel Topics: spreadsheet rendering failed</strong><br />File: %1$s<br />Topic: %2$d<br />Post: %3$d<br />Attachment: %4$d<br />Attachment author: %5$d<br />Code: %6$s<br />Detail: %7$s',
));
