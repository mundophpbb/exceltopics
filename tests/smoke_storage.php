<?php
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

if (!defined('ATTACHMENTS_TABLE'))
{
    define('ATTACHMENTS_TABLE', 'phpbb_attachments');
}

require_once __DIR__ . '/../service/excel_file_storage.php';

use mundophpbb\exceltopics\service\excel_file_storage;

function storage_expect($condition, $message)
{
    if (!$condition)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function storage_remove_tree($path)
{
    if (!is_dir($path))
    {
        return;
    }

    foreach (scandir($path) as $entry)
    {
        if ($entry === '.' || $entry === '..')
        {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full))
        {
            storage_remove_tree($full);
        }
        else
        {
            @unlink($full);
        }
    }
    @rmdir($path);
}

class storage_fake_result
{
    public $rows;
    public $index = 0;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }
}

class storage_fake_db
{
    public $attachments = array();
    public $last_result;
    public $last_affected = 0;

    public function sql_escape($value)
    {
        return str_replace("'", "''", (string) $value);
    }

    public function sql_query($sql)
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql));
        $this->last_affected = 0;

        if (preg_match('/^SELECT physical_filename FROM phpbb_attachments WHERE attach_id = (\d+)$/i', $flat, $matches))
        {
            $id = (int) $matches[1];
            $rows = isset($this->attachments[$id])
                ? array(array('physical_filename' => $this->attachments[$id]['physical_filename']))
                : array();
            return $this->remember(new storage_fake_result($rows));
        }

        if (preg_match("/^SELECT COUNT\(attach_id\) AS num_entries FROM phpbb_attachments WHERE physical_filename = '((?:''|[^'])*)'$/i", $flat, $matches))
        {
            $physical = str_replace("''", "'", $matches[1]);
            $count = 0;
            foreach ($this->attachments as $row)
            {
                if ($row['physical_filename'] === $physical)
                {
                    $count++;
                }
            }
            return $this->remember(new storage_fake_result(array(array('num_entries' => $count))));
        }

        if (preg_match("/^UPDATE phpbb_attachments SET physical_filename = '((?:''|[^'])*)' WHERE physical_filename = '((?:''|[^'])*)'$/i", $flat, $matches))
        {
            $new = str_replace("''", "'", $matches[1]);
            $old = str_replace("''", "'", $matches[2]);
            foreach ($this->attachments as &$row)
            {
                if ($row['physical_filename'] === $old)
                {
                    $row['physical_filename'] = $new;
                    $this->last_affected++;
                }
            }
            unset($row);
            return $this->remember(new storage_fake_result(array()));
        }

        throw new RuntimeException('Unexpected SQL: ' . $flat);
    }

    public function sql_query_limit($sql, $limit, $offset = 0)
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql));
        if (!preg_match("/^SELECT attach_id, physical_filename FROM phpbb_attachments WHERE physical_filename LIKE 'exceltopics\/%'(?: AND attach_id > (\d+))? ORDER BY attach_id ASC$/i", $flat, $matches))
        {
            throw new RuntimeException('Unexpected limited SQL: ' . $flat);
        }

        $after = !empty($matches[1]) ? (int) $matches[1] : 0;
        $rows = array();
        foreach ($this->attachments as $id => $row)
        {
            if ($id > $after && strpos($row['physical_filename'], 'exceltopics/') === 0)
            {
                $rows[] = array('attach_id' => $id, 'physical_filename' => $row['physical_filename']);
            }
        }
        usort($rows, function ($left, $right)
        {
            return $left['attach_id'] <=> $right['attach_id'];
        });

        return $this->remember(new storage_fake_result(array_slice($rows, (int) $offset, (int) $limit)));
    }

    public function sql_fetchrow($result)
    {
        if (!$result instanceof storage_fake_result || $result->index >= count($result->rows))
        {
            return false;
        }
        return $result->rows[$result->index++];
    }

    public function sql_fetchfield($field)
    {
        if (!$this->last_result instanceof storage_fake_result || empty($this->last_result->rows))
        {
            return false;
        }
        return $this->last_result->rows[0][$field] ?? false;
    }

    public function sql_freeresult($result)
    {
    }

    public function sql_affectedrows()
    {
        return $this->last_affected;
    }

    protected function remember($result)
    {
        $this->last_result = $result;
        return $result;
    }
}

class storage_fake_log
{
    public $calls = array();

    public function add($mode, $user_id, $log_ip, $operation, $log_time = false, $additional_data = array())
    {
        $this->calls[] = compact('mode', 'user_id', 'log_ip', 'operation', 'additional_data');
        return count($this->calls);
    }
}

class storage_fake_user
{
    public $data = array('user_id' => 1);
    public $ip = '127.0.0.1';
}

$root = sys_get_temp_dir() . '/excel-topics-storage-' . uniqid('', true);
mkdir($root . '/files', 0777, true);
$config = new ArrayObject(array('upload_path' => 'files'));
$db = new storage_fake_db();
$log = new storage_fake_log();
$user = new storage_fake_user();
$storage = new excel_file_storage($config, $db, $log, $user, $root);

// A short restore lease prevents active topic views from moving files back into
// managed storage between multi-request disable batches.
file_put_contents($root . '/files/lease_xlsx', 'lease workbook');
$db->attachments = array(
    1 => array('physical_filename' => 'lease_xlsx'),
);
$lease_attachment = array(
    'attach_id' => 1,
    'extension' => 'xlsx',
    'real_filename' => 'lease.xlsx',
    'physical_filename' => 'lease_xlsx',
);
$storage->begin_restore_window();
storage_expect($storage->is_restore_window_active(), 'restore lease became active');
$lease_result = $storage->ensure_attachment_storage($lease_attachment);
storage_expect($lease_result['physical_filename'] === 'lease_xlsx', 'active restore lease prevented a reverse move');
storage_expect(file_exists($root . '/files/lease_xlsx'), 'root file remained available during restore lease');

// A concurrent view must not remove a root copy created by an in-flight restore
// while the database row still points to the managed path.
mkdir($root . '/files/exceltopics', 0777, true);
file_put_contents($root . '/files/exceltopics/et_2_inflight', 'inflight workbook');
file_put_contents($root . '/files/et_2_inflight', 'inflight workbook');
$db->attachments[2] = array('physical_filename' => 'exceltopics/et_2_inflight');
$inflight = $storage->ensure_attachment_storage(array(
    'attach_id' => 2,
    'extension' => 'xlsx',
    'real_filename' => 'inflight.xlsx',
    'physical_filename' => 'exceltopics/et_2_inflight',
));
storage_expect($inflight['physical_filename'] === 'exceltopics/et_2_inflight', 'managed path stayed authoritative during restore lease');
storage_expect(file_exists($root . '/files/et_2_inflight'), 'in-flight root copy survived concurrent rendering');
unset($db->attachments[2]);
@unlink($root . '/files/exceltopics/et_2_inflight');
@unlink($root . '/files/et_2_inflight');

$storage->end_restore_window();
storage_expect(!$storage->is_restore_window_active(), 'restore lease was cleared');
$lease_result = $storage->ensure_attachment_storage($lease_attachment);
storage_expect($lease_result['physical_filename'] === 'exceltopics/et_1_lease_xlsx', 'storage move resumed after lease ended');
unset($db->attachments[1]);
@unlink($root . '/files/exceltopics/et_1_lease_xlsx');

// One physical file can be shared by copied topics. Moving one row must update
// all references before removing the root file.
file_put_contents($root . '/files/shared_xlsx', 'shared workbook');
$db->attachments = array(
    9 => array('physical_filename' => 'shared_xlsx'),
    10 => array('physical_filename' => 'shared_xlsx'),
    11 => array('physical_filename' => 'regular_file'),
);
$attachment = array(
    'attach_id' => 9,
    'extension' => 'xlsx',
    'real_filename' => 'catalogo.xlsx',
    'physical_filename' => 'shared_xlsx',
);
$moved = $storage->ensure_attachment_storage($attachment);
storage_expect($moved['physical_filename'] === 'exceltopics/et_9_shared_xlsx', 'XLSX was moved into managed storage');
storage_expect($db->attachments[9]['physical_filename'] === 'exceltopics/et_9_shared_xlsx', 'current database row was updated');
storage_expect($db->attachments[10]['physical_filename'] === 'exceltopics/et_9_shared_xlsx', 'shared database reference was updated atomically');
storage_expect(!file_exists($root . '/files/shared_xlsx'), 'root file was removed only after all references changed');
storage_expect(file_get_contents($root . '/files/exceltopics/et_9_shared_xlsx') === 'shared workbook', 'managed file content was preserved');
storage_expect(file_exists($root . '/files/exceltopics/.htaccess'), 'managed directory access guard exists');
storage_expect(file_exists($root . '/files/exceltopics/index.htm'), 'managed directory index guard exists');

$stale_event_attachment = array_merge($attachment, array('attach_id' => 10));
$refreshed = $storage->ensure_attachment_storage($stale_event_attachment);
storage_expect($refreshed['physical_filename'] === 'exceltopics/et_9_shared_xlsx', 'authoritative database path replaced stale event data');
storage_expect($storage->resolve_attachment_path($refreshed) === realpath($root . '/files/exceltopics/et_9_shared_xlsx'), 'managed path resolved inside upload directory');

$download = $storage->restore_download_attachment_path(array(
    'attach_id' => 9,
    'extension' => 'xlsx',
    'real_filename' => 'catalogo.xlsx',
    'physical_filename' => '9_shared_xlsx',
));
storage_expect($download['physical_filename'] === 'exceltopics/et_9_shared_xlsx', 'download path was restored from the database');
storage_expect($storage->is_managed_xlsx_attachment($download), 'managed XLSX was recognized for secure streaming');

// Deleting one of two shared references must preserve the physical file and not
// change counters. Deleting the final reference removes it exactly once.
$physical_info = array(
    array('filename' => 'exceltopics/et_9_shared_xlsx', 'filesize' => 15, 'is_orphan' => 0, 'thumbnail' => 0),
    array('filename' => 'regular_file', 'filesize' => 4, 'is_orphan' => 0, 'thumbnail' => 0),
);
$filtered = $storage->prepare_delete_physical($physical_info);
storage_expect(count($filtered) === 1 && $filtered[0]['filename'] === 'regular_file', 'managed path was removed from core basename deletion list');
unset($db->attachments[9]);
$space_removed = 0;
$files_removed = 0;
$storage->remove_prepared_deleted_files($space_removed, $files_removed);
storage_expect(file_exists($root . '/files/exceltopics/et_9_shared_xlsx'), 'shared managed file survived deletion of one reference');
storage_expect($space_removed === 0 && $files_removed === 0, 'counters were unchanged while another reference remained');

$storage->prepare_delete_physical(array($physical_info[0]));
unset($db->attachments[10]);
$storage->remove_prepared_deleted_files($space_removed, $files_removed);
storage_expect(!file_exists($root . '/files/exceltopics/et_9_shared_xlsx'), 'final reference deletion removed the managed file');
storage_expect($space_removed === 15 && $files_removed === 1, 'phpBB counters received exactly one physical deletion');

// Repeated physical rows are grouped. If any deleted row was attached rather
// than orphaned, the physical file must decrement the counters exactly once.
file_put_contents($root . '/files/exceltopics/et_12_mixed', 'mixed workbook');
$db->attachments[12] = array('physical_filename' => 'exceltopics/et_12_mixed');
$db->attachments[13] = array('physical_filename' => 'exceltopics/et_12_mixed');
$storage->prepare_delete_physical(array(
    array('filename' => 'exceltopics/et_12_mixed', 'filesize' => 14, 'is_orphan' => 1, 'thumbnail' => 0),
    array('filename' => 'exceltopics/et_12_mixed', 'filesize' => 14, 'is_orphan' => 0, 'thumbnail' => 0),
));
unset($db->attachments[12], $db->attachments[13]);
$mixed_space_removed = 0;
$mixed_files_removed = 0;
$storage->remove_prepared_deleted_files($mixed_space_removed, $mixed_files_removed);
storage_expect(!file_exists($root . '/files/exceltopics/et_12_mixed'), 'duplicate mixed-state physical file was removed once');
storage_expect($mixed_space_removed === 14 && $mixed_files_removed === 1, 'mixed orphan state decremented counters exactly once');

// A malformed managed path must never be handed to phpBB's basename-based
// deletion path, which could otherwise target an unrelated root file.
$unsafe_filtered = $storage->prepare_delete_physical(array(
    array('filename' => 'exceltopics/nested/unrelated_root_name', 'filesize' => 1, 'is_orphan' => 0, 'thumbnail' => 0),
));
storage_expect($unsafe_filtered === array(), 'malformed managed path was excluded from core deletion');
storage_expect(count($log->calls) === 1, 'malformed managed path produced one administrative log');
storage_expect($log->calls[0]['user_id'] === (defined('ANONYMOUS') ? ANONYMOUS : 0), 'storage log used the system identity');
storage_expect($log->calls[0]['log_ip'] === '', 'storage log was not attributed to the visitor IP');

// Disabling restores paths and files to the upload root, including shared rows.
file_put_contents($root . '/files/exceltopics/20_restore_file', 'restore workbook');
$db->attachments[20] = array('physical_filename' => 'exceltopics/20_restore_file');
$db->attachments[21] = array('physical_filename' => 'exceltopics/20_restore_file');
$restore = $storage->restore_managed_attachments_batch(1);
storage_expect($restore['remaining'] === false, 'one managed physical path restored all shared rows');
storage_expect($db->attachments[20]['physical_filename'] === '20_restore_file', 'first shared row was restored to root path');
storage_expect($db->attachments[21]['physical_filename'] === '20_restore_file', 'second shared row was restored to root path');
storage_expect(file_get_contents($root . '/files/20_restore_file') === 'restore workbook', 'restored root file preserved content');
storage_expect(!file_exists($root . '/files/exceltopics/20_restore_file'), 'managed source was removed after database update');

// A root collision with different content must never be overwritten.
file_put_contents($root . '/files/30_collision', 'existing unrelated file');
file_put_contents($root . '/files/exceltopics/30_collision', 'managed workbook');
$db->attachments[30] = array('physical_filename' => 'exceltopics/30_collision');
$storage->restore_managed_attachments_batch(25);
$collision_restored_name = $db->attachments[30]['physical_filename'];
storage_expect($collision_restored_name !== '30_collision', 'different root collision received a new physical filename');
storage_expect(file_get_contents($root . '/files/30_collision') === 'existing unrelated file', 'existing root collision was preserved');
storage_expect(file_get_contents($root . '/files/' . $collision_restored_name) === 'managed workbook', 'managed workbook was restored under collision-safe name');

// Integrity verification repairs a missing managed path from an unreferenced
// root copy and removes the duplicate root copy afterward.
file_put_contents($root . '/files/missing_copy', 'repair workbook');
$db->attachments[40] = array('physical_filename' => 'exceltopics/40_missing_copy');
$verify = $storage->verify_managed_attachments_batch(0, 100);
storage_expect($verify['remaining'] === false, 'integrity verification completed in one batch');
storage_expect(file_get_contents($root . '/files/exceltopics/40_missing_copy') === 'repair workbook', 'missing managed file was repaired from root copy');
storage_expect(!file_exists($root . '/files/missing_copy'), 'unreferenced identical root copy was removed after repair');

// Conservative cleanup deletes only old, unreferenced extension-pattern files.
file_put_contents($root . '/files/exceltopics/et_999_old_orphan', 'orphan');
file_put_contents($root . '/files/exceltopics/manual.xlsx', 'manual file');
touch($root . '/files/exceltopics/et_999_old_orphan', time() - excel_file_storage::ORPHAN_GRACE_SECONDS - 60);
touch($root . '/files/exceltopics/manual.xlsx', time() - excel_file_storage::ORPHAN_GRACE_SECONDS - 60);
$cleanup = $storage->cleanup_orphan_files_batch('', 100);
storage_expect($cleanup['remaining'] === false, 'orphan cleanup completed in one batch');
storage_expect(!file_exists($root . '/files/exceltopics/et_999_old_orphan'), 'old unreferenced extension-pattern orphan was deleted');
storage_expect(file_exists($root . '/files/exceltopics/manual.xlsx'), 'unknown manually named file was preserved');

// Missing managed data blocks disable instead of silently leaving broken links.
$db->attachments[50] = array('physical_filename' => 'exceltopics/50_missing_everywhere');
$disable_blocked = false;
try
{
    $storage->restore_managed_attachments_batch(25);
}
catch (RuntimeException $exception)
{
    $disable_blocked = strpos($exception->getMessage(), 'disable was stopped') !== false;
}
storage_expect($disable_blocked, 'missing file stopped restoration safely');

storage_expect(count($log->calls) === 1, 'only the deliberately malformed path produced an error log');
storage_remove_tree($root);
echo "storage smoke test: OK\n";
