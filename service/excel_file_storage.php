<?php
/**
 * Excel Topics extension for phpBB.
 *
 * Keeps XLSX attachments in a dedicated subdirectory of phpBB's configured
 * upload directory while preserving phpBB's native attachment permissions,
 * counters and download flow.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\service;

class excel_file_storage
{
    const STORAGE_DIRECTORY = 'exceltopics';
    const RESTORE_BATCH_SIZE = 25;
    const INTEGRITY_BATCH_SIZE = 50;
    const ORPHAN_BATCH_SIZE = 100;
    const ORPHAN_GRACE_SECONDS = 604800; // Seven days
    const RESTORE_LEASE_CONFIG = 'mundophpbb_exceltopics_restore_until';
    const RESTORE_LEASE_SECONDS = 900;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\log\log_interface */
    protected $log;

    /** @var \phpbb\user */
    protected $user;

    /** @var string */
    protected $phpbb_root_path;

    /** @var array<int, array<string, mixed>> */
    protected $pending_deletions = array();

    /**
     * @param \phpbb\config\config $config
     * @param \phpbb\db\driver\driver_interface $db
     * @param \phpbb\log\log_interface $log
     * @param \phpbb\user $user
     * @param string $phpbb_root_path
     */
    public function __construct($config, $db, $log, $user, $phpbb_root_path)
    {
        $this->config = $config;
        $this->db = $db;
        $this->log = $log;
        $this->user = $user;
        $this->phpbb_root_path = $phpbb_root_path;
    }

    /**
     * Move a persisted XLSX attachment into files/exceltopics on first view.
     *
     * The database update covers every row that references the same physical
     * file. phpBB may share one physical file between copied topics, so moving
     * only the currently viewed attachment could otherwise break the copies.
     *
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public function ensure_attachment_storage(array $attachment)
    {
        if (!$this->is_xlsx_attachment($attachment))
        {
            return $attachment;
        }

        $attach_id = isset($attachment['attach_id']) ? (int) $attachment['attach_id'] : 0;
        if ($attach_id > 0)
        {
            $current = $this->fetch_physical_filename($attach_id);
            if ($current !== '')
            {
                $attachment['physical_filename'] = $current;
            }
        }

        $physical = isset($attachment['physical_filename'])
            ? $this->normalize_physical_filename((string) $attachment['physical_filename'])
            : '';

        if ($physical === '')
        {
            return $attachment;
        }

        try
        {
            // disable_step() works in bounded batches while phpBB still treats
            // the extension as active. A short database-backed lease prevents
            // concurrent views from moving root files back or removing a root
            // copy that an in-flight restore has not linked in the database yet.
            $restore_active = $this->is_restore_window_active();

            if ($this->is_managed_physical_filename($physical))
            {
                if (!$restore_active)
                {
                    $this->repair_managed_file($physical);
                }
                $attachment['physical_filename'] = $physical;
                return $attachment;
            }

            if (!$this->is_root_physical_filename($physical) || $restore_active)
            {
                return $attachment;
            }

            $managed = $this->move_root_file_to_managed_directory($physical, $attach_id);
            if ($managed !== '')
            {
                $attachment['physical_filename'] = $managed;
            }
        }
        catch (\Throwable $exception)
        {
            $this->log_storage_failure('move', $physical, $exception, $attach_id);
        }

        return $attachment;
    }

    /**
     * Resolve an attachment only inside phpBB's configured upload directory.
     *
     * @param array<string, mixed> $attachment
     * @return string Empty string when the path is invalid or unavailable
     */
    public function resolve_attachment_path(array $attachment)
    {
        if (empty($attachment['physical_filename']))
        {
            return '';
        }

        $physical = $this->normalize_physical_filename((string) $attachment['physical_filename']);
        if (!$this->is_root_physical_filename($physical) && !$this->is_managed_physical_filename($physical))
        {
            return '';
        }

        return $this->resolve_physical_path($physical, true);
    }

    /**
     * phpBB strips directory components before the download event runs. Read
     * the authoritative physical filename from the attachment table and restore
     * the managed relative path for the native download handler.
     *
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    /**
     * Determine whether an attachment is an XLSX currently managed inside the
     * extension subdirectory. Used to force phpBB's authenticated streaming
     * mode instead of a direct physical redirect.
     *
     * @param array<string, mixed> $attachment
     * @return bool
     */
    public function is_managed_xlsx_attachment(array $attachment)
    {
        if (!$this->is_xlsx_attachment($attachment))
        {
            return false;
        }

        $physical = isset($attachment['physical_filename'])
            ? $this->normalize_physical_filename((string) $attachment['physical_filename'])
            : '';

        return $this->is_managed_physical_filename($physical);
    }

    public function restore_download_attachment_path(array $attachment)
    {
        if (!$this->is_xlsx_attachment($attachment))
        {
            return $attachment;
        }

        $attach_id = isset($attachment['attach_id']) ? (int) $attachment['attach_id'] : 0;
        if ($attach_id <= 0)
        {
            return $attachment;
        }

        $physical = $this->fetch_physical_filename($attach_id);
        if ($this->is_root_physical_filename($physical) || $this->is_managed_physical_filename($physical))
        {
            // Refresh root names as well. During a collision-safe restore, the
            // authoritative root basename can differ from the stale value that
            // download/file.php loaded before this event.
            $attachment['physical_filename'] = $physical;
        }

        return $attachment;
    }

    /**
     * Remove managed paths from phpBB's basename-based physical deletion list.
     * They are retained in memory and deleted with their full relative paths by
     * remove_prepared_deleted_files() after the database rows are removed.
     *
     * @param array<int, array<string, mixed>> $physical
     * @return array<int, array<string, mixed>>
     */
    public function prepare_delete_physical(array $physical)
    {
        $filtered = array();

        foreach ($physical as $file_info)
        {
            if (!is_array($file_info))
            {
                continue;
            }

            $raw_filename = isset($file_info['filename'])
                ? ltrim(str_replace('\\', '/', (string) $file_info['filename']), '/')
                : '';
            $filename = $this->normalize_physical_filename($raw_filename);
            $uses_managed_prefix = strpos($raw_filename, self::STORAGE_DIRECTORY . '/') === 0;

            if ($uses_managed_prefix)
            {
                // Never let phpBB apply basename() to a malformed managed path,
                // because that could target an unrelated file in the upload root.
                if (!$this->is_managed_physical_filename($filename))
                {
                    $this->log_storage_failure(
                        'delete_prepare',
                        $raw_filename,
                        new \RuntimeException('Invalid managed attachment path was excluded from basename deletion')
                    );
                    continue;
                }

                $file_info['filename'] = $filename;
                $this->pending_deletions[] = $file_info;
                continue;
            }

            $filtered[] = $file_info;
        }

        return $filtered;
    }

    /**
     * Delete managed files saved by prepare_delete_physical(). The counters are
     * passed back through phpBB's official event and the core performs the
     * actual config increments after the listener returns.
     *
     * @param int $space_removed
     * @param int $files_removed
     * @return void
     */
    public function remove_prepared_deleted_files(&$space_removed, &$files_removed)
    {
        $grouped = array();

        foreach ($this->pending_deletions as $file_info)
        {
            $filename = isset($file_info['filename'])
                ? $this->normalize_physical_filename((string) $file_info['filename'])
                : '';

            if (!$this->is_managed_physical_filename($filename))
            {
                continue;
            }

            if (!isset($grouped[$filename]))
            {
                $file_info['filename'] = $filename;
                $grouped[$filename] = $file_info;
                continue;
            }

            // A copied topic can produce repeated rows for one physical file.
            // Count that physical file once, but treat it as attached whenever
            // at least one of the deleted rows was not an orphan.
            $grouped[$filename]['is_orphan'] = !empty($grouped[$filename]['is_orphan'])
                && !empty($file_info['is_orphan']);
            $grouped[$filename]['thumbnail'] = !empty($grouped[$filename]['thumbnail'])
                || !empty($file_info['thumbnail']);
            $grouped[$filename]['filesize'] = max(
                (int) ($grouped[$filename]['filesize'] ?? 0),
                (int) ($file_info['filesize'] ?? 0)
            );
        }

        foreach ($grouped as $filename => $file_info)
        {
            // A physical file can be referenced by more than one attachment.
            if ($this->count_attachment_references($filename) > 0)
            {
                continue;
            }

            $path = $this->resolve_physical_path($filename, false);
            if ($path !== '' && is_file($path))
            {
                if (@unlink($path))
                {
                    if (empty($file_info['is_orphan']))
                    {
                        // Match phpBB: decrement the database-accounted size
                        // rather than a potentially changed size on disk.
                        $space_removed += max(0, (int) ($file_info['filesize'] ?? 0));
                        $files_removed++;
                    }
                }
                else
                {
                    $this->log_storage_failure(
                        'delete_file',
                        $filename,
                        new \RuntimeException('Managed attachment could not be removed after its database row was deleted')
                    );
                }
            }
            else
            {
                $this->log_storage_failure(
                    'delete_file',
                    $filename,
                    new \RuntimeException('Managed attachment was already missing when deletion reached the filesystem')
                );
            }

            if (!empty($file_info['thumbnail']))
            {
                $thumbnail = $this->managed_thumbnail_path($filename);
                if ($thumbnail !== '' && is_file($thumbnail))
                {
                    @unlink($thumbnail);
                }
            }
        }

        $this->pending_deletions = array();
    }

    /**
     * Prevent first-view storage moves while a multi-request disable or purge
     * operation is returning attachments to phpBB's native upload root.
     *
     * @return void
     */
    public function begin_restore_window()
    {
        $until = time() + self::RESTORE_LEASE_SECONDS;

        if (method_exists($this->config, 'set'))
        {
            $this->config->set(self::RESTORE_LEASE_CONFIG, $until, false);
            return;
        }

        $this->config[self::RESTORE_LEASE_CONFIG] = $until;
    }

    /**
     * Clear the restore lease after every managed database reference has been
     * returned to phpBB's upload root.
     *
     * @return void
     */
    public function end_restore_window()
    {
        if (method_exists($this->config, 'delete'))
        {
            $this->config->delete(self::RESTORE_LEASE_CONFIG, false);
            return;
        }

        unset($this->config[self::RESTORE_LEASE_CONFIG]);
    }

    /**
     * @return bool
     */
    public function is_restore_window_active()
    {
        $until = isset($this->config[self::RESTORE_LEASE_CONFIG])
            ? (int) $this->config[self::RESTORE_LEASE_CONFIG]
            : 0;

        return $until >= time();
    }

    /**
     * Restore a bounded number of managed attachments to phpBB's upload root.
     * Used by ext::disable_step() so downloads remain native after disabling.
     *
     * @param int $limit
     * @return array{processed:int, remaining:bool}
     */
    public function restore_managed_attachments_batch($limit = self::RESTORE_BATCH_SIZE)
    {
        $limit = max(1, min(250, (int) $limit));
        $rows = $this->fetch_managed_attachment_rows(0, $limit);
        $processed_paths = array();

        foreach ($rows as $row)
        {
            $physical = isset($row['physical_filename'])
                ? $this->normalize_physical_filename((string) $row['physical_filename'])
                : '';

            if (!$this->is_managed_physical_filename($physical))
            {
                throw new \RuntimeException(
                    'A database row contains an invalid Excel Topics attachment path; disable was stopped to preserve files'
                );
            }

            if (isset($processed_paths[$physical]))
            {
                continue;
            }

            $this->restore_managed_physical_filename($physical);
            $processed_paths[$physical] = true;
        }

        return array(
            'processed' => count($processed_paths),
            'remaining' => $this->has_managed_attachment_rows(),
        );
    }

    /**
     * Verify managed rows in bounded batches during activation. Missing managed
     * files are repaired from an identical root copy when one is available.
     *
     * @param int $after_attach_id
     * @param int $limit
     * @return array{last_attach_id:int, remaining:bool}
     */
    public function verify_managed_attachments_batch($after_attach_id = 0, $limit = self::INTEGRITY_BATCH_SIZE)
    {
        $after_attach_id = max(0, (int) $after_attach_id);
        $limit = max(1, min(250, (int) $limit));
        $rows = $this->fetch_managed_attachment_rows($after_attach_id, $limit);
        $last_attach_id = $after_attach_id;
        $seen = array();

        foreach ($rows as $row)
        {
            $attach_id = isset($row['attach_id']) ? (int) $row['attach_id'] : 0;
            $last_attach_id = max($last_attach_id, $attach_id);
            $physical = isset($row['physical_filename'])
                ? $this->normalize_physical_filename((string) $row['physical_filename'])
                : '';

            if (!$this->is_managed_physical_filename($physical) || isset($seen[$physical]))
            {
                continue;
            }
            $seen[$physical] = true;

            try
            {
                if (!$this->repair_managed_file($physical))
                {
                    throw new \RuntimeException('Managed attachment file is missing and no root copy is available');
                }
            }
            catch (\Throwable $exception)
            {
                $this->log_storage_failure('verify', $physical, $exception, $attach_id);
            }
        }

        return array(
            'last_attach_id' => $last_attach_id,
            'remaining' => count($rows) === $limit,
        );
    }

    /**
     * Delete only old, unreferenced files whose names match the pattern created
     * by this extension. Unknown files and recent files are preserved.
     *
     * @param string $after_name
     * @param int $limit
     * @return array{last_name:string, remaining:bool, deleted:int}
     */
    public function cleanup_orphan_files_batch($after_name = '', $limit = self::ORPHAN_BATCH_SIZE)
    {
        $limit = max(1, min(500, (int) $limit));
        $directory = $this->managed_directory(false);
        if ($directory === '')
        {
            return array('last_name' => '', 'remaining' => false, 'deleted' => 0);
        }

        $entries = @scandir($directory);
        if (!is_array($entries))
        {
            return array('last_name' => '', 'remaining' => false, 'deleted' => 0);
        }

        $candidates = array();
        foreach ($entries as $entry)
        {
            if ($entry === '.' || $entry === '..' || $entry <= $after_name)
            {
                continue;
            }
            $candidates[] = $entry;
        }

        $slice = array_slice($candidates, 0, $limit);
        $deleted = 0;
        $last_name = $after_name;
        $cutoff = time() - self::ORPHAN_GRACE_SECONDS;

        foreach ($slice as $entry)
        {
            $last_name = $entry;

            if (!$this->is_extension_managed_basename($entry))
            {
                continue;
            }

            $relative = self::STORAGE_DIRECTORY . '/' . $entry;
            if ($this->count_attachment_references($relative) > 0)
            {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path))
            {
                continue;
            }

            $modified = @filemtime($path);
            if ($modified === false || $modified > $cutoff)
            {
                continue;
            }

            if (@unlink($path))
            {
                $deleted++;
                $thumbnail = $directory . DIRECTORY_SEPARATOR . 'thumb_' . $entry;
                if (is_file($thumbnail))
                {
                    @unlink($thumbnail);
                }
            }
        }

        return array(
            'last_name' => $last_name,
            'remaining' => count($candidates) > count($slice),
            'deleted' => $deleted,
        );
    }

    /**
     * Ensure files/exceltopics and its direct-access guard files exist.
     *
     * @return bool
     */
    public function ensure_storage_ready()
    {
        return $this->managed_directory(true) !== '';
    }

    /**
     * @param array<string, mixed> $attachment
     * @return bool
     */
    protected function is_xlsx_attachment(array $attachment)
    {
        $extension = isset($attachment['extension']) ? strtolower((string) $attachment['extension']) : '';
        $real_filename = isset($attachment['real_filename']) ? (string) $attachment['real_filename'] : '';
        $filename_extension = strtolower((string) pathinfo($real_filename, PATHINFO_EXTENSION));

        return $extension === 'xlsx' && $filename_extension === 'xlsx';
    }

    /**
     * @param string $root_physical
     * @param int $attach_id
     * @return string
     */
    protected function move_root_file_to_managed_directory($root_physical, $attach_id)
    {
        $source = $this->resolve_physical_path($root_physical, true);
        if ($source === '')
        {
            // A prior interrupted operation may already have copied the file.
            $candidate = self::STORAGE_DIRECTORY . '/' . $this->build_managed_basename($attach_id, $root_physical);
            if ($this->resolve_physical_path($candidate, true) !== '')
            {
                $this->update_physical_filename_references($root_physical, $candidate);
                return $candidate;
            }

            throw new \RuntimeException('Root attachment file is unavailable before storage move');
        }

        $directory = $this->managed_directory(true);
        if ($directory === '')
        {
            throw new \RuntimeException('Unable to create the managed Excel storage directory');
        }

        $preferred = $this->build_managed_basename($attach_id, $root_physical);
        $target_basename = $this->choose_destination_basename($directory, $preferred, $source, 'managed');
        $target_relative = self::STORAGE_DIRECTORY . '/' . $target_basename;
        $target = $directory . DIRECTORY_SEPARATOR . $target_basename;
        $target_created = false;

        if (!is_file($target))
        {
            $this->copy_file_atomically($source, $target);
            $target_created = true;
        }
        else if (!$this->files_are_identical($source, $target))
        {
            throw new \RuntimeException('Managed destination collision could not be resolved safely');
        }

        try
        {
            $this->update_physical_filename_references($root_physical, $target_relative);
        }
        catch (\Throwable $exception)
        {
            if ($target_created)
            {
                @unlink($target);
            }
            throw $exception;
        }

        if ($this->count_attachment_references($root_physical) === 0)
        {
            @unlink($source);
        }

        return $target_relative;
    }

    /**
     * @param string $managed_physical
     * @return void
     */
    protected function restore_managed_physical_filename($managed_physical)
    {
        if (!$this->is_managed_physical_filename($managed_physical))
        {
            throw new \RuntimeException('Invalid managed attachment path');
        }

        $source = $this->resolve_physical_path($managed_physical, true);
        $upload_directory = $this->upload_directory();
        if ($upload_directory === '')
        {
            throw new \RuntimeException('phpBB upload directory is unavailable');
        }

        $preferred = basename($managed_physical);

        if ($source === '')
        {
            foreach ($this->root_repair_candidates($managed_physical) as $candidate)
            {
                $root_path = $upload_directory . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($root_path) && is_readable($root_path))
                {
                    $this->update_physical_filename_references($managed_physical, $candidate);
                    return;
                }
            }

            throw new \RuntimeException('Managed attachment file is missing; disable was stopped to preserve downloads');
        }

        $target_basename = $this->choose_destination_basename($upload_directory, $preferred, $source, 'root');
        $target = $upload_directory . DIRECTORY_SEPARATOR . $target_basename;
        $target_created = false;

        if (!is_file($target))
        {
            $this->copy_file_atomically($source, $target);
            $target_created = true;
        }
        else if (!$this->files_are_identical($source, $target))
        {
            throw new \RuntimeException('Root destination collision could not be resolved safely');
        }

        try
        {
            $this->update_physical_filename_references($managed_physical, $target_basename);
        }
        catch (\Throwable $exception)
        {
            if ($target_created)
            {
                @unlink($target);
            }
            throw $exception;
        }

        if ($this->count_attachment_references($managed_physical) === 0)
        {
            @unlink($source);
        }
    }

    /**
     * @param string $managed_physical
     * @return bool True when the managed file is available after the check
     */
    protected function repair_managed_file($managed_physical)
    {
        if (!$this->is_managed_physical_filename($managed_physical))
        {
            return false;
        }

        $directory = $this->managed_directory(true);
        if ($directory === '')
        {
            return false;
        }

        $target = $directory . DIRECTORY_SEPARATOR . basename($managed_physical);
        if (is_file($target) && is_readable($target))
        {
            $this->remove_identical_unreferenced_root_duplicate($managed_physical, $target);
            return true;
        }

        $upload_directory = $this->upload_directory();
        if ($upload_directory === '')
        {
            return false;
        }

        foreach ($this->root_repair_candidates($managed_physical) as $candidate)
        {
            $source = $upload_directory . DIRECTORY_SEPARATOR . $candidate;
            if (!is_file($source) || !is_readable($source))
            {
                continue;
            }

            $this->copy_file_atomically($source, $target);
            if ($this->count_attachment_references($candidate) === 0)
            {
                @unlink($source);
            }
            return true;
        }

        return false;
    }

    /**
     * @param string $managed_physical
     * @param string $managed_path
     * @return void
     */
    protected function remove_identical_unreferenced_root_duplicate($managed_physical, $managed_path)
    {
        $upload_directory = $this->upload_directory();
        if ($upload_directory === '')
        {
            return;
        }

        foreach ($this->root_repair_candidates($managed_physical) as $candidate)
        {
            if ($this->count_attachment_references($candidate) > 0)
            {
                continue;
            }

            $root_path = $upload_directory . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($root_path) && $this->files_are_identical($managed_path, $root_path))
            {
                @unlink($root_path);
            }
        }
    }

    /**
     * @param string $managed_physical
     * @return array<int, string>
     */
    protected function root_repair_candidates($managed_physical)
    {
        $basename = basename($managed_physical);
        $candidates = array($basename);

        if (preg_match('/^et_\d+_(.+)$/', $basename, $matches) && $this->is_safe_physical_basename($matches[1]))
        {
            $candidates[] = $matches[1];
        }
        else if (preg_match('/^\d+_(.+)$/', $basename, $matches) && $this->is_safe_physical_basename($matches[1]))
        {
            // Backward compatibility with 0.2.0-beta1 managed filenames.
            $candidates[] = $matches[1];
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param string $directory
     * @param string $preferred
     * @param string $source
     * @param string $scope
     * @return string
     */
    protected function choose_destination_basename($directory, $preferred, $source, $scope)
    {
        $preferred = $this->sanitize_generated_basename($preferred);
        $path = $directory . DIRECTORY_SEPARATOR . $preferred;
        $relative_for_db = ($scope === 'managed')
            ? self::STORAGE_DIRECTORY . '/' . $preferred
            : $preferred;

        if (!file_exists($path) && $this->count_attachment_references($relative_for_db) === 0)
        {
            return $preferred;
        }

        if (is_file($path) && $this->files_are_identical($source, $path))
        {
            return $preferred;
        }

        $hash = @hash_file('sha256', $source);
        $suffix = '_et_' . substr(($hash === false ? sha1($source) : $hash), 0, 10);
        $stem = substr($preferred, 0, max(1, 240 - strlen($suffix)));

        for ($counter = 0; $counter < 100; $counter++)
        {
            $counter_suffix = ($counter === 0) ? '' : '_' . $counter;
            $candidate = substr($stem, 0, max(1, 240 - strlen($suffix) - strlen($counter_suffix)))
                . $suffix . $counter_suffix;
            $candidate_path = $directory . DIRECTORY_SEPARATOR . $candidate;
            $candidate_db = ($scope === 'managed')
                ? self::STORAGE_DIRECTORY . '/' . $candidate
                : $candidate;

            if (!file_exists($candidate_path) && $this->count_attachment_references($candidate_db) === 0)
            {
                return $candidate;
            }

            if (is_file($candidate_path) && $this->files_are_identical($source, $candidate_path))
            {
                return $candidate;
            }
        }

        throw new \RuntimeException('No collision-free attachment filename is available');
    }

    /**
     * @param int $attach_id
     * @param string $root_physical
     * @return string
     */
    protected function build_managed_basename($attach_id, $root_physical)
    {
        $basename = basename($root_physical);

        // beta2 uses an explicit internal prefix. It makes conservative orphan
        // cleanup distinguish extension-created files from manually placed files
        // and avoids adding another prefix after disable/enable cycles.
        if (preg_match('/^et_\d+_/', $basename))
        {
            return $this->sanitize_generated_basename($basename);
        }

        $prefix = 'et_' . max(0, (int) $attach_id) . '_';
        return $this->sanitize_generated_basename($prefix . $basename);
    }

    /**
     * @param string $basename
     * @return string
     */
    protected function sanitize_generated_basename($basename)
    {
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $basename);
        $basename = trim((string) $basename, '.-_');

        if ($basename === '')
        {
            $basename = 'excel_' . substr(sha1((string) microtime(true)), 0, 16);
        }

        if (strlen($basename) > 240)
        {
            $basename = substr($basename, 0, 200) . '_' . substr(sha1($basename), 0, 20);
        }

        return $basename;
    }

    /**
     * @param string $source
     * @param string $target
     * @return void
     */
    protected function copy_file_atomically($source, $target)
    {
        $directory = dirname($target);
        $temporary = $directory . DIRECTORY_SEPARATOR . '.exceltopics-' . bin2hex(random_bytes(8)) . '.tmp';

        try
        {
            if (!@copy($source, $temporary))
            {
                throw new \RuntimeException('Unable to copy attachment into temporary storage');
            }

            @chmod($temporary, 0644);

            if (!@rename($temporary, $target))
            {
                if (is_file($target) && $this->files_are_identical($source, $target))
                {
                    @unlink($temporary);
                    return;
                }

                throw new \RuntimeException('Unable to finalize attachment storage move');
            }
        }
        catch (\Throwable $exception)
        {
            @unlink($temporary);
            throw $exception;
        }
    }

    /**
     * @param string $first
     * @param string $second
     * @return bool
     */
    protected function files_are_identical($first, $second)
    {
        if (!is_file($first) || !is_file($second))
        {
            return false;
        }

        $first_size = @filesize($first);
        $second_size = @filesize($second);
        if ($first_size === false || $second_size === false || $first_size !== $second_size)
        {
            return false;
        }

        $first_hash = @hash_file('sha256', $first);
        $second_hash = @hash_file('sha256', $second);

        return $first_hash !== false && $second_hash !== false && hash_equals($first_hash, $second_hash);
    }

    /**
     * @param string $old_physical
     * @param string $new_physical
     * @return void
     */
    protected function update_physical_filename_references($old_physical, $new_physical)
    {
        if ($old_physical === $new_physical)
        {
            return;
        }

        $sql = 'UPDATE ' . ATTACHMENTS_TABLE . "\n"
            . "SET physical_filename = '" . $this->db->sql_escape($new_physical) . "'\n"
            . "WHERE physical_filename = '" . $this->db->sql_escape($old_physical) . "'";
        $this->db->sql_query($sql);
    }

    /**
     * @param int $attach_id
     * @return string
     */
    protected function fetch_physical_filename($attach_id)
    {
        if ($attach_id <= 0 || !defined('ATTACHMENTS_TABLE'))
        {
            return '';
        }

        try
        {
            $sql = 'SELECT physical_filename FROM ' . ATTACHMENTS_TABLE
                . ' WHERE attach_id = ' . (int) $attach_id;
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if (!empty($row['physical_filename']))
            {
                return $this->normalize_physical_filename((string) $row['physical_filename']);
            }
        }
        catch (\Throwable $ignored)
        {
            // Rendering can continue with the event-provided attachment data.
        }

        return '';
    }

    /**
     * @param int $after_attach_id
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    protected function fetch_managed_attachment_rows($after_attach_id, $limit)
    {
        if (!defined('ATTACHMENTS_TABLE'))
        {
            return array();
        }

        $sql = 'SELECT attach_id, physical_filename FROM ' . ATTACHMENTS_TABLE
            . " WHERE physical_filename LIKE '" . $this->db->sql_escape(self::STORAGE_DIRECTORY . '/%') . "'";

        if ($after_attach_id > 0)
        {
            $sql .= ' AND attach_id > ' . (int) $after_attach_id;
        }

        $sql .= ' ORDER BY attach_id ASC';
        $result = $this->db->sql_query_limit($sql, (int) $limit);
        $rows = array();

        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    /**
     * @return bool
     */
    protected function has_managed_attachment_rows()
    {
        return !empty($this->fetch_managed_attachment_rows(0, 1));
    }

    /**
     * @param string $physical
     * @return int
     */
    protected function count_attachment_references($physical)
    {
        if ($physical === '' || !defined('ATTACHMENTS_TABLE'))
        {
            return 0;
        }

        try
        {
            $sql = 'SELECT COUNT(attach_id) AS num_entries FROM ' . ATTACHMENTS_TABLE
                . " WHERE physical_filename = '" . $this->db->sql_escape($physical) . "'";
            $result = $this->db->sql_query($sql);
            $count = (int) $this->db->sql_fetchfield('num_entries');
            $this->db->sql_freeresult($result);
            return $count;
        }
        catch (\Throwable $ignored)
        {
            // A non-zero conservative value prevents accidental file deletion.
            return 1;
        }
    }

    /**
     * @param string $physical
     * @return string
     */
    protected function normalize_physical_filename($physical)
    {
        $physical = str_replace('\\', '/', (string) $physical);
        $physical = ltrim($physical, '/');

        if ($physical === '' || strpos($physical, "\0") !== false)
        {
            return '';
        }

        $parts = explode('/', $physical);
        foreach ($parts as $part)
        {
            if ($part === '' || $part === '.' || $part === '..' || !$this->is_safe_physical_basename($part))
            {
                return '';
            }
        }

        if (count($parts) === 1)
        {
            return $parts[0];
        }

        if (count($parts) === 2 && $parts[0] === self::STORAGE_DIRECTORY)
        {
            return self::STORAGE_DIRECTORY . '/' . $parts[1];
        }

        return '';
    }

    /**
     * @param string $basename
     * @return bool
     */
    protected function is_safe_physical_basename($basename)
    {
        return $basename !== ''
            && $basename === basename($basename)
            && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $basename);
    }

    /**
     * @param string $physical
     * @return bool
     */
    protected function is_root_physical_filename($physical)
    {
        return $physical !== '' && strpos($physical, '/') === false && $this->is_safe_physical_basename($physical);
    }

    /**
     * @param string $physical
     * @return bool
     */
    protected function is_managed_physical_filename($physical)
    {
        $prefix = self::STORAGE_DIRECTORY . '/';
        if (strpos($physical, $prefix) !== 0)
        {
            return false;
        }

        $basename = substr($physical, strlen($prefix));
        return $this->is_safe_physical_basename($basename);
    }

    /**
     * @param string $basename
     * @return bool
     */
    protected function is_extension_managed_basename($basename)
    {
        return $this->is_safe_physical_basename($basename)
            && (bool) preg_match('/^et_\d+_[A-Za-z0-9._-]+$/', $basename);
    }

    /**
     * @return string Absolute phpBB upload directory or empty string
     */
    protected function upload_directory()
    {
        $root = realpath($this->phpbb_root_path);
        if ($root === false)
        {
            return '';
        }

        $upload_path = isset($this->config['upload_path']) ? (string) $this->config['upload_path'] : 'files';
        $upload_path = trim(str_replace('\\', '/', $upload_path), '/');
        if ($upload_path === '' || strpos($upload_path, '..') !== false || strpos($upload_path, "\0") !== false)
        {
            return '';
        }

        $directory = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $upload_path));
        if ($directory === false || !is_dir($directory))
        {
            return '';
        }

        $root_prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $root_prefix, strlen($root_prefix)) !== 0)
        {
            return '';
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * @param bool $create
     * @return string Absolute files/exceltopics path or empty string
     */
    protected function managed_directory($create)
    {
        $upload_directory = $this->upload_directory();
        if ($upload_directory === '')
        {
            return '';
        }

        $managed = $upload_directory . DIRECTORY_SEPARATOR . self::STORAGE_DIRECTORY;
        if (!is_dir($managed))
        {
            if (!$create || !@mkdir($managed, 0755, true))
            {
                return '';
            }
        }

        $resolved = realpath($managed);
        if ($resolved === false || !is_dir($resolved))
        {
            return '';
        }

        $prefix = rtrim($upload_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp(rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) !== 0)
        {
            return '';
        }

        if ($create && !is_writable($resolved))
        {
            return '';
        }

        if ($create)
        {
            $this->ensure_directory_guards($resolved);
        }

        return $resolved;
    }

    /**
     * @param string $directory
     * @return void
     */
    protected function ensure_directory_guards($directory)
    {
        $index = $directory . DIRECTORY_SEPARATOR . 'index.htm';
        if (!file_exists($index))
        {
            @file_put_contents($index, '');
            @chmod($index, 0644);
        }

        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess))
        {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n"
            );
            @chmod($htaccess, 0644);
        }
    }

    /**
     * @param string $physical
     * @param bool $must_be_readable
     * @return string
     */
    protected function resolve_physical_path($physical, $must_be_readable)
    {
        $physical = $this->normalize_physical_filename($physical);
        if (!$this->is_root_physical_filename($physical) && !$this->is_managed_physical_filename($physical))
        {
            return '';
        }

        $upload_directory = $this->upload_directory();
        if ($upload_directory === '')
        {
            return '';
        }

        $candidate = $upload_directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $physical);
        $resolved = realpath($candidate);
        if ($resolved === false)
        {
            return '';
        }

        $prefix = rtrim($upload_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($resolved, $prefix, strlen($prefix)) !== 0)
        {
            return '';
        }

        if (!is_file($resolved) || ($must_be_readable && !is_readable($resolved)))
        {
            return '';
        }

        return $resolved;
    }

    /**
     * @param string $physical
     * @return string
     */
    protected function managed_thumbnail_path($physical)
    {
        if (!$this->is_managed_physical_filename($physical))
        {
            return '';
        }

        $directory = $this->managed_directory(false);
        if ($directory === '')
        {
            return '';
        }

        return $directory . DIRECTORY_SEPARATOR . 'thumb_' . basename($physical);
    }

    /**
     * @param string $operation
     * @param string $filename
     * @param \Throwable $exception
     * @param int $attach_id
     * @return void
     */
    protected function log_storage_failure($operation, $filename, \Throwable $exception, $attach_id = 0)
    {
        try
        {
            // Storage failures are system events. Do not attribute them to the
            // visitor whose request happened to trigger a move, verification or
            // cleanup operation.
            $system_user_id = defined('ANONYMOUS') ? (int) ANONYMOUS : 0;
            $detail = get_class($exception) . ': ' . $exception->getMessage();

            $this->log->add(
                'critical',
                $system_user_id,
                '',
                'LOG_MUNDOPHPBB_EXCELTOPICS_STORAGE_ERROR',
                false,
                array(
                    $this->sanitize_log_value($operation, 50),
                    (int) $attach_id,
                    $this->sanitize_log_value($filename, 255),
                    $this->sanitize_log_value($detail, 1000),
                )
            );
        }
        catch (\Throwable $ignored)
        {
            // Storage errors must not become page rendering errors.
        }
    }

    /**
     * @param string $value
     * @param int $maximum_bytes
     * @return string
     */
    protected function sanitize_log_value($value, $maximum_bytes)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', (string) $value);
        $value = trim((string) $value);

        if (preg_match('//u', $value) !== 1)
        {
            $value = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        if (strlen($value) > $maximum_bytes)
        {
            $value = substr($value, 0, $maximum_bytes);
            while ($value !== '' && preg_match('//u', $value) !== 1)
            {
                $value = substr($value, 0, -1);
            }
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
