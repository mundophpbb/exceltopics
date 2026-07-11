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
    'ACP_EXCELTOPICS_SUPPORT' => 'Suporte XLSX',
    'ACP_EXCELTOPICS_SUPPORT_EXPLAIN' => 'Verifica se o phpBB aceita anexos XLSX e oferece um atalho seguro para habilitar o formato. As permissões de anexos dos fóruns e usuários não são alteradas.',
    'ACP_EXCELTOPICS_READY' => 'O Excel Topics está pronto para receber anexos XLSX.',
    'ACP_EXCELTOPICS_NOT_READY' => 'O suporte XLSX ainda precisa de atenção.',
    'ACP_EXCELTOPICS_STATUS_ATTACHMENTS' => 'Anexos ativados globalmente',
    'ACP_EXCELTOPICS_STATUS_EXTENSION' => 'XLSX cadastrado',
    'ACP_EXCELTOPICS_STATUS_GROUP' => 'Grupo de extensões ativo',
    'ACP_EXCELTOPICS_STATUS_GROUP_NAME' => 'Grupo atual',
    'ACP_EXCELTOPICS_STATUS_GROUP_LIMIT' => 'Limite de tamanho do grupo',
    'ACP_EXCELTOPICS_STATUS_GLOBAL_LIMIT' => 'Limite global de anexos',
    'ACP_EXCELTOPICS_YES' => 'Sim',
    'ACP_EXCELTOPICS_NO' => 'Não',
    'ACP_EXCELTOPICS_NOT_ASSIGNED' => 'Não atribuído',
    'ACP_EXCELTOPICS_ACTIVATE' => 'Ativar suporte XLSX',
    'ACP_EXCELTOPICS_ACTIVATE_EXPLAIN' => 'Cria ou ativa o grupo de anexos “Excel Topics”, atribui xlsx a ele, usa download interno seguro, permite postagens em todos os fóruns e configura limite de 5 MiB no grupo. Não ativa anexos em mensagens privadas nem altera permissões de usuários ou fóruns.',
    'ACP_EXCELTOPICS_OPEN_ATTACHMENTS' => 'Configurações de anexos',
    'ACP_EXCELTOPICS_OPEN_EXTENSIONS' => 'Gerenciar extensões',
    'ACP_EXCELTOPICS_OPEN_GROUPS' => 'Gerenciar grupos de extensões',
    'ACP_EXCELTOPICS_PERMISSIONS_NOTE' => 'Os usuários também precisam ter permissão para anexar arquivos no fórum de destino. O limite global de anexos pode impor um limite efetivo menor.',
    'ACP_EXCELTOPICS_GLOBAL_DISABLED' => 'Os anexos estão desativados globalmente. Ative-os em Configurações de anexos depois de configurar o XLSX.',
    'ACP_EXCELTOPICS_XLSX_ENABLED_SUCCESS' => 'O suporte XLSX foi ativado com sucesso.',
    'ACP_EXCELTOPICS_XLSX_ENABLE_ERROR' => 'Não foi possível ativar o suporte XLSX. Detalhe: %s',
));
