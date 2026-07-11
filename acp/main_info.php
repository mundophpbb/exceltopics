<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\acp;

class main_info
{
    public function module()
    {
        return array(
            'filename' => '\\mundophpbb\\exceltopics\\acp\\main_module',
            'title' => 'ACP_EXCELTOPICS_TITLE',
            'modes' => array(
                'support' => array(
                    'title' => 'ACP_EXCELTOPICS_SUPPORT',
                    'auth' => 'ext_mundophpbb/exceltopics && acl_a_attach',
                    'cat' => array('ACP_EXCELTOPICS_TITLE'),
                ),
            ),
        );
    }
}
