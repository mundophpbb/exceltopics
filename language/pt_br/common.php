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
    'EXCEL_TOPIC_FILE_AND_SHEET'          => '%1$s — aba %2$s',
    'EXCEL_TOPIC_META_SHEET'              => 'Aba: %s',
    'EXCEL_TOPIC_META_UPDATED'            => 'Atualizada em %s',
    'EXCEL_TOPIC_META_DIMENSIONS'         => '%1$d linhas · %2$d colunas',
    'EXCEL_TOPIC_SCROLL_HINT'             => 'Deslize para ver mais',
    'EXCEL_TOPIC_EMPTY'                   => 'Esta aba não contém dados visíveis.',
    'EXCEL_TOPIC_TRUNCATED_ROWS'          => 'A exibição foi limitada às primeiras %d linhas não vazias.',
    'EXCEL_TOPIC_TRUNCATED_COLUMNS'       => 'A exibição foi limitada às primeiras %d colunas.',
    'EXCEL_TOPIC_TRUNCATED_CELLS'         => 'Textos de células muito longos foram limitados a %d caracteres.',
    'EXCEL_TOPIC_TRUNCATED_CONTENT'       => 'A leitura foi interrompida ao atingir %d MiB de conteúdo de células.',
    'EXCEL_TOPIC_TRUNCATED_OUTPUT'        => 'A tabela exibida foi reduzida para manter o HTML da página dentro de %d MiB.',
    'EXCEL_TOPIC_HIDDEN_ROWS_SKIPPED'     => '%d linhas ocultas do Excel não foram exibidas.',

    'EXCEL_TOPIC_ERROR_FILE_MISSING'      => 'O arquivo da planilha anexada não está disponível.',
    'EXCEL_TOPIC_ERROR_FILE_TOO_LARGE'    => 'Esta planilha ultrapassa o limite de 5 MiB para exibição como tabela.',
    'EXCEL_TOPIC_ERROR_INVALID_XLSX'      => 'O anexo não é uma pasta de trabalho XLSX válida.',
    'EXCEL_TOPIC_ERROR_WORKBOOK'          => 'Não foi possível ler a estrutura da pasta de trabalho XLSX.',
    'EXCEL_TOPIC_ERROR_SHEET'             => 'Não foi possível ler a aba selecionada.',
    'EXCEL_TOPIC_ERROR_READ'              => 'Não foi possível exibir a planilha.',
    'EXCEL_TOPIC_ERROR_ZIP_EXTENSION'     => 'O servidor precisa da extensão PHP Zip para ler arquivos XLSX.',
    'EXCEL_TOPIC_ERROR_XML_EXTENSION'     => 'O servidor precisa de XMLReader e SimpleXML do PHP para ler arquivos XLSX.',
    'EXCEL_TOPIC_ERROR_SHARED_STRINGS_LIMIT' => 'A planilha contém textos compartilhados demais para exibição segura.',
    'EXCEL_TOPIC_ERROR_STYLES_LIMIT'       => 'A planilha contém estilos demais para exibição segura.',
    'EXCEL_TOPIC_ERROR_SHEET_COMPLEX'      => 'A aba selecionada é complexa demais para exibição segura.',
    'EXCEL_TOPIC_ERROR_OUTPUT_TOO_LARGE'   => 'A tabela gera conteúdo HTML demais para ser exibida com segurança.',

    'LOG_MUNDOPHPBB_EXCELTOPICS_XLSX_ENABLED' => '<strong>Excel Topics: suporte XLSX ativado</strong><br />Grupo: %1$s<br />Tamanho máximo: %2$d bytes',

    'LOG_MUNDOPHPBB_EXCELTOPICS_STORAGE_ERROR' => '<strong>Excel Topics: falha no armazenamento</strong><br />Operação: %1$s<br />Anexo: %2$d<br />Arquivo: %3$s<br />Detalhe: %4$s',

    'LOG_MUNDOPHPBB_EXCELTOPICS_RENDER_ERROR' => '<strong>Excel Topics: falha ao exibir planilha</strong><br />Arquivo: %1$s<br />Tópico: %2$d<br />Post: %3$d<br />Anexo: %4$d<br />Autor do anexo: %5$d<br />Código: %6$s<br />Detalhe: %7$s',
));
