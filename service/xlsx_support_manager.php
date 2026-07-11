<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\service;

class xlsx_support_manager
{
    private const GROUP_NAME = 'Excel Topics';
    private const MAX_FILESIZE = 5242880;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\cache\driver\driver_interface */
    protected $cache;

    /** @var \phpbb\log\log_interface */
    protected $log;

    /** @var \phpbb\user */
    protected $user;

    public function __construct($config, $db, $cache, $log, $user)
    {
        $this->config = $config;
        $this->db = $db;
        $this->cache = $cache;
        $this->log = $log;
        $this->user = $user;
    }

    /**
     * Return the current attachment support status without changing anything.
     *
     * @return array<string, mixed>
     */
    public function get_status()
    {
        $row = $this->find_xlsx_row();
        $extension_exists = !empty($row);
        $group_assigned = $extension_exists && (int) $row['group_id'] > 0;
        $group_enabled = $group_assigned && !empty($row['allow_group']);
        $attachments_enabled = !empty($this->config['allow_attachments']);

        return array(
            'attachments_enabled' => $attachments_enabled,
            'extension_exists' => $extension_exists,
            'group_assigned' => $group_assigned,
            'group_enabled' => $group_enabled,
            'xlsx_enabled' => $extension_exists && $group_enabled,
            'ready' => $attachments_enabled && $extension_exists && $group_enabled,
            'extension_id' => $extension_exists ? (int) $row['extension_id'] : 0,
            'group_id' => $group_assigned ? (int) $row['group_id'] : 0,
            'group_name' => $group_assigned ? (string) $row['group_name'] : '',
            'group_max_filesize' => $group_assigned ? (int) $row['max_filesize'] : 0,
            'global_max_filesize' => isset($this->config['max_filesize']) ? (int) $this->config['max_filesize'] : 0,
            'allow_in_pm' => $group_assigned && !empty($row['allow_in_pm']),
        );
    }

    /**
     * Create/enable a dedicated attachment group and assign xlsx to it.
     * This method deliberately does not enable attachments globally or change
     * user/forum permissions.
     *
     * @return array<string, mixed>
     */
    public function enable_xlsx_support()
    {
        $status = $this->get_status();
        if (!empty($status['xlsx_enabled']))
        {
            return $status;
        }

        $this->db->sql_transaction('begin');

        try
        {
            $group_id = $this->find_dedicated_group_id();
            $group_data = array(
                'group_name' => self::GROUP_NAME,
                'cat_id' => defined('ATTACHMENT_CATEGORY_NONE') ? ATTACHMENT_CATEGORY_NONE : 0,
                'allow_group' => 1,
                'download_mode' => defined('INLINE_LINK') ? INLINE_LINK : 1,
                'upload_icon' => '',
                'max_filesize' => self::MAX_FILESIZE,
                'allowed_forums' => '',
                'allow_in_pm' => 0,
            );

            if ($group_id > 0)
            {
                $sql = 'UPDATE ' . EXTENSION_GROUPS_TABLE . '
                    SET ' . $this->db->sql_build_array('UPDATE', $group_data) . '
                    WHERE group_id = ' . $group_id;
                $this->db->sql_query($sql);
            }
            else
            {
                $sql = 'INSERT INTO ' . EXTENSION_GROUPS_TABLE . ' '
                    . $this->db->sql_build_array('INSERT', $group_data);
                $this->db->sql_query($sql);
                $group_id = (int) $this->db->sql_nextid();
            }

            $existing = $this->find_xlsx_row();
            if (!empty($existing))
            {
                $sql = 'UPDATE ' . EXTENSIONS_TABLE . '
                    SET group_id = ' . $group_id . "
                    WHERE extension = 'xlsx'";
                $this->db->sql_query($sql);
            }
            else
            {
                $sql_ary = array(
                    'group_id' => $group_id,
                    'extension' => 'xlsx',
                );
                $sql = 'INSERT INTO ' . EXTENSIONS_TABLE . ' '
                    . $this->db->sql_build_array('INSERT', $sql_ary);
                $this->db->sql_query($sql);
            }

            $this->db->sql_transaction('commit');
        }
        catch (\Throwable $exception)
        {
            $this->db->sql_transaction('rollback');
            throw $exception;
        }

        $this->cache->destroy('_extensions');

        $this->log->add(
            'admin',
            isset($this->user->data['user_id']) ? (int) $this->user->data['user_id'] : ANONYMOUS,
            isset($this->user->ip) ? (string) $this->user->ip : '',
            'LOG_MUNDOPHPBB_EXCELTOPICS_XLSX_ENABLED',
            false,
            array(self::GROUP_NAME, self::MAX_FILESIZE)
        );

        return $this->get_status();
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function find_xlsx_row()
    {
        $sql = 'SELECT e.extension_id, e.extension, e.group_id,
                g.group_name, g.allow_group, g.max_filesize, g.allow_in_pm
            FROM ' . EXTENSIONS_TABLE . ' e
            LEFT JOIN ' . EXTENSION_GROUPS_TABLE . ' g
                ON g.group_id = e.group_id
            WHERE e.extension = \'xlsx\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: false;
    }

    protected function find_dedicated_group_id()
    {
        $sql = 'SELECT group_id
            FROM ' . EXTENSION_GROUPS_TABLE . "
            WHERE group_name = '" . $this->db->sql_escape(self::GROUP_NAME) . "'";
        $result = $this->db->sql_query($sql);
        $group_id = (int) $this->db->sql_fetchfield('group_id');
        $this->db->sql_freeresult($result);

        return $group_id;
    }
}
