<?php
error_reporting(E_ALL);

define('EXTENSION_GROUPS_TABLE', 'phpbb_extension_groups');
define('EXTENSIONS_TABLE', 'phpbb_extensions');
define('ATTACHMENT_CATEGORY_NONE', 0);
define('INLINE_LINK', 1);
define('ANONYMOUS', 1);

require_once __DIR__ . '/../service/xlsx_support_manager.php';

class fake_support_db
{
    public $groups = array();
    public $extensions = array();
    public $last_build = array();
    private $result = array();
    private $result_index = 0;
    private $next_group_id = 1;
    private $next_extension_id = 1;

    public function sql_transaction($mode) {}

    public function sql_escape($value)
    {
        return addslashes($value);
    }

    public function sql_build_array($mode, $data)
    {
        $this->last_build = $data;
        return 'FAKE_BUILD_ARRAY';
    }

    public function sql_query($sql)
    {
        $this->result = array();
        $this->result_index = 0;

        if (strpos($sql, 'SELECT e.extension_id') !== false)
        {
            foreach ($this->extensions as $extension)
            {
                if ($extension['extension'] !== 'xlsx')
                {
                    continue;
                }
                $group = isset($this->groups[$extension['group_id']])
                    ? $this->groups[$extension['group_id']]
                    : array('group_name' => null, 'allow_group' => null, 'max_filesize' => null, 'allow_in_pm' => null);
                $this->result[] = array_merge($extension, array(
                    'group_name' => $group['group_name'],
                    'allow_group' => $group['allow_group'],
                    'max_filesize' => $group['max_filesize'],
                    'allow_in_pm' => $group['allow_in_pm'],
                ));
                break;
            }
        }
        else if (strpos($sql, 'SELECT group_id') !== false && strpos($sql, EXTENSION_GROUPS_TABLE) !== false)
        {
            foreach ($this->groups as $group)
            {
                if ($group['group_name'] === 'Excel Topics')
                {
                    $this->result[] = array('group_id' => $group['group_id']);
                    break;
                }
            }
        }
        else if (strpos($sql, 'INSERT INTO ' . EXTENSION_GROUPS_TABLE) !== false)
        {
            $id = $this->next_group_id++;
            $this->groups[$id] = array_merge($this->last_build, array('group_id' => $id));
        }
        else if (strpos($sql, 'UPDATE ' . EXTENSION_GROUPS_TABLE) !== false)
        {
            if (preg_match('/WHERE group_id = (\d+)/', $sql, $match))
            {
                $id = (int) $match[1];
                $this->groups[$id] = array_merge($this->groups[$id], $this->last_build);
            }
        }
        else if (strpos($sql, 'INSERT INTO ' . EXTENSIONS_TABLE) !== false)
        {
            $id = $this->next_extension_id++;
            $this->extensions[$id] = array_merge($this->last_build, array('extension_id' => $id));
        }
        else if (strpos($sql, 'UPDATE ' . EXTENSIONS_TABLE) !== false)
        {
            if (preg_match('/SET group_id = (\d+)/', $sql, $match))
            {
                foreach ($this->extensions as &$extension)
                {
                    if ($extension['extension'] === 'xlsx')
                    {
                        $extension['group_id'] = (int) $match[1];
                    }
                }
                unset($extension);
            }
        }

        return true;
    }

    public function sql_fetchrow($result)
    {
        if (!isset($this->result[$this->result_index]))
        {
            return false;
        }
        return $this->result[$this->result_index++];
    }

    public function sql_fetchfield($field)
    {
        return isset($this->result[0][$field]) ? $this->result[0][$field] : false;
    }

    public function sql_freeresult($result) {}

    public function sql_nextid()
    {
        if (!empty($this->last_build['extension']))
        {
            return $this->next_extension_id - 1;
        }
        return $this->next_group_id - 1;
    }

    public function seed_group($name, $enabled)
    {
        $id = $this->next_group_id++;
        $this->groups[$id] = array(
            'group_id' => $id,
            'group_name' => $name,
            'cat_id' => 0,
            'allow_group' => $enabled ? 1 : 0,
            'download_mode' => 1,
            'upload_icon' => '',
            'max_filesize' => 0,
            'allowed_forums' => '',
            'allow_in_pm' => 0,
        );
        return $id;
    }

    public function seed_extension($extension, $group_id)
    {
        $id = $this->next_extension_id++;
        $this->extensions[$id] = array(
            'extension_id' => $id,
            'extension' => $extension,
            'group_id' => $group_id,
        );
        return $id;
    }
}

class fake_support_cache
{
    public $destroyed = array();
    public function destroy($key) { $this->destroyed[] = $key; }
}

class fake_support_log
{
    public $entries = array();
    public function add(...$args) { $this->entries[] = $args; }
}

class fake_support_user
{
    public $data = array('user_id' => 2);
    public $ip = '127.0.0.1';
}

function assert_true($condition, $message)
{
    if (!$condition)
    {
        throw new RuntimeException($message);
    }
}

function make_manager($db, $allow_attachments = true)
{
    return new \mundophpbb\exceltopics\service\xlsx_support_manager(
        array('allow_attachments' => $allow_attachments ? 1 : 0, 'max_filesize' => 2097152),
        $db,
        new fake_support_cache(),
        new fake_support_log(),
        new fake_support_user()
    );
}

// Fresh install: create group and extension.
$db = new fake_support_db();
$manager = make_manager($db);
assert_true(!$manager->get_status()['xlsx_enabled'], 'XLSX should start disabled.');
$status = $manager->enable_xlsx_support();
assert_true($status['ready'], 'Fresh activation should become ready.');
assert_true(count($db->groups) === 1, 'One dedicated group should be created.');
assert_true(count($db->extensions) === 1, 'The xlsx extension should be created.');
$manager->enable_xlsx_support();
assert_true(count($db->groups) === 1 && count($db->extensions) === 1, 'Activation should be idempotent.');

// Existing xlsx in an inactive group: move only xlsx to the dedicated group.
$db = new fake_support_db();
$old_group = $db->seed_group('Old Office Files', false);
$db->seed_extension('xlsx', $old_group);
$manager = make_manager($db);
$status = $manager->enable_xlsx_support();
assert_true($status['xlsx_enabled'], 'Inactive xlsx should be enabled.');
assert_true($status['group_name'] === 'Excel Topics', 'Inactive xlsx should move to the dedicated group.');
assert_true($db->groups[$old_group]['allow_group'] === 0, 'The unrelated old group must not be enabled.');

// Existing xlsx in an active custom group: leave the administrator's setup alone.
$db = new fake_support_db();
$custom_group = $db->seed_group('Office documents', true);
$db->seed_extension('xlsx', $custom_group);
$manager = make_manager($db);
$status = $manager->enable_xlsx_support();
assert_true($status['xlsx_enabled'], 'Active custom xlsx should remain enabled.');
assert_true($status['group_name'] === 'Office documents', 'Active custom group should be preserved.');
assert_true(count($db->groups) === 1, 'No unnecessary dedicated group should be created.');

// Global attachments remain an explicit administrator decision.
$db = new fake_support_db();
$manager = make_manager($db, false);
$status = $manager->enable_xlsx_support();
assert_true($status['xlsx_enabled'], 'XLSX can be configured while global attachments are off.');
assert_true(!$status['ready'], 'The diagnostic must remain not ready while global attachments are off.');

echo "XLSX support manager smoke test: OK\n";
