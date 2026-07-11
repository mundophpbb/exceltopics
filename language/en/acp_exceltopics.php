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
    'ACP_EXCELTOPICS_SUPPORT' => 'XLSX support',
    'ACP_EXCELTOPICS_SUPPORT_EXPLAIN' => 'Checks whether phpBB accepts XLSX attachments and provides a safe shortcut to enable the file type. Forum and user attachment permissions are not changed.',
    'ACP_EXCELTOPICS_READY' => 'Excel Topics is ready to receive XLSX attachments.',
    'ACP_EXCELTOPICS_NOT_READY' => 'XLSX support still needs attention.',
    'ACP_EXCELTOPICS_STATUS_ATTACHMENTS' => 'Attachments enabled globally',
    'ACP_EXCELTOPICS_STATUS_EXTENSION' => 'XLSX registered',
    'ACP_EXCELTOPICS_STATUS_GROUP' => 'Extension group enabled',
    'ACP_EXCELTOPICS_STATUS_GROUP_NAME' => 'Current group',
    'ACP_EXCELTOPICS_STATUS_GROUP_LIMIT' => 'Group file-size limit',
    'ACP_EXCELTOPICS_STATUS_GLOBAL_LIMIT' => 'Global attachment limit',
    'ACP_EXCELTOPICS_YES' => 'Yes',
    'ACP_EXCELTOPICS_NO' => 'No',
    'ACP_EXCELTOPICS_NOT_ASSIGNED' => 'Not assigned',
    'ACP_EXCELTOPICS_ACTIVATE' => 'Enable XLSX support',
    'ACP_EXCELTOPICS_ACTIVATE_EXPLAIN' => 'Creates or enables the “Excel Topics” attachment group, assigns xlsx to it, uses secure inline download, allows posts in all forums and sets a 5 MiB group limit. It does not enable private-message attachments or alter user/forum permissions.',
    'ACP_EXCELTOPICS_OPEN_ATTACHMENTS' => 'Attachment settings',
    'ACP_EXCELTOPICS_OPEN_EXTENSIONS' => 'Manage extensions',
    'ACP_EXCELTOPICS_OPEN_GROUPS' => 'Manage extension groups',
    'ACP_EXCELTOPICS_PERMISSIONS_NOTE' => 'Users also need permission to attach files in the destination forum. The global attachment limit may still impose a lower effective limit.',
    'ACP_EXCELTOPICS_GLOBAL_DISABLED' => 'Attachments are globally disabled. Enable them in Attachment settings after configuring XLSX.',
    'ACP_EXCELTOPICS_XLSX_ENABLED_SUCCESS' => 'XLSX support was enabled successfully.',
    'ACP_EXCELTOPICS_XLSX_ENABLE_ERROR' => 'XLSX support could not be enabled. Detail: %s',
));
