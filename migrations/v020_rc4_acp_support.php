<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\migrations;

class v020_rc4_acp_support extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['exceltopics_acp_support_module']);
    }

    public function update_data()
    {
        return array(
            array('config.add', array('exceltopics_acp_support_module', 1)),
            array('module.add', array(
                'acp',
                'ACP_CAT_POSTING',
                'ACP_EXCELTOPICS_TITLE',
            )),
            array('module.add', array(
                'acp',
                'ACP_EXCELTOPICS_TITLE',
                array(
                    'module_basename' => '\\mundophpbb\\exceltopics\\acp\\main_module',
                    'modes' => array('support'),
                ),
            )),
        );
    }
}
