<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\acp;

class main_module
{
    /** @var string */
    public $u_action;

    /** @var string */
    public $tpl_name;

    /** @var string */
    public $page_title;

    public function main($id, $mode)
    {
        global $config, $request, $template, $user, $phpbb_admin_path, $phpEx, $phpbb_container;

        $user->add_lang_ext('mundophpbb/exceltopics', 'acp_exceltopics');

        $this->tpl_name = 'acp_exceltopics_support';
        $this->page_title = 'ACP_EXCELTOPICS_SUPPORT';

        $form_key = 'mundophpbb_exceltopics_support';
        add_form_key($form_key);

        /** @var \mundophpbb\exceltopics\service\xlsx_support_manager $manager */
        $manager = $phpbb_container->get('mundophpbb.exceltopics.xlsx_support_manager');

        $success = '';
        $error = '';

        if ($request->is_set_post('activate_xlsx'))
        {
            if (!check_form_key($form_key))
            {
                $error = $user->lang('FORM_INVALID');
            }
            else
            {
                try
                {
                    $manager->enable_xlsx_support();
                    $success = $user->lang('ACP_EXCELTOPICS_XLSX_ENABLED_SUCCESS');
                }
                catch (\Throwable $exception)
                {
                    $error = $user->lang('ACP_EXCELTOPICS_XLSX_ENABLE_ERROR', $exception->getMessage());
                }
            }
        }

        $status = $manager->get_status();
        $configured_limit = !empty($status['group_max_filesize'])
            ? (int) $status['group_max_filesize']
            : (int) $status['global_max_filesize'];

        $template->assign_vars(array(
            'U_ACTION' => $this->u_action,
            'U_ATTACH_SETTINGS' => append_sid("{$phpbb_admin_path}index.$phpEx", 'i=acp_attachments&mode=attach'),
            'U_MANAGE_EXTENSIONS' => append_sid("{$phpbb_admin_path}index.$phpEx", 'i=acp_attachments&mode=extensions'),
            'U_EXTENSION_GROUPS' => append_sid("{$phpbb_admin_path}index.$phpEx", 'i=acp_attachments&mode=ext_groups'),

            'S_SUCCESS' => $success !== '',
            'SUCCESS_MSG' => $success,
            'S_ERROR' => $error !== '',
            'ERROR_MSG' => $error,

            'S_READY' => !empty($status['ready']),
            'S_XLSX_ENABLED' => !empty($status['xlsx_enabled']),
            'S_ATTACHMENTS_ENABLED' => !empty($status['attachments_enabled']),
            'S_EXTENSION_EXISTS' => !empty($status['extension_exists']),
            'S_GROUP_ASSIGNED' => !empty($status['group_assigned']),
            'S_GROUP_ENABLED' => !empty($status['group_enabled']),

            'GROUP_NAME' => (string) $status['group_name'],
            'GROUP_LIMIT' => $this->format_filesize($configured_limit),
            'GLOBAL_LIMIT' => $this->format_filesize((int) $status['global_max_filesize']),
        ));
    }

    protected function format_filesize($bytes)
    {
        $bytes = max(0, (int) $bytes);
        if ($bytes >= 1048576)
        {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MiB';
        }
        if ($bytes >= 1024)
        {
            return number_format($bytes / 1024, 2, ',', '.') . ' KiB';
        }

        return $bytes . ' B';
    }
}
