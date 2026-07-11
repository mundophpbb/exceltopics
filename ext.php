<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics;

class ext extends \phpbb\extension\base
{
    /**
     * Keep the supported platform deliberately narrow for this release.
     *
     * @return bool
     */
    public function is_enableable()
    {
        return phpbb_version_compare(PHPBB_VERSION, '3.3.17', '>=')
            && phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '<')
            && version_compare(PHP_VERSION, '8.2.0', '>=')
            && class_exists('ZipArchive')
            && class_exists('XMLReader')
            && function_exists('simplexml_load_string');
    }

    /**
     * Verify managed paths and perform conservative orphan cleanup in bounded
     * batches. This extension has no database migrations.
     *
     * @param mixed $old_state
     * @return array<string, mixed>|false
     */
    public function enable_step($old_state)
    {
        $state = is_array($old_state) ? $old_state : array();
        $phase = isset($state['phase']) ? (string) $state['phase'] : 'verify';
        $storage = $this->create_storage_service();

        // Any interrupted restore lease is obsolete once an explicit enable
        // operation starts.
        $storage->end_restore_window();

        if (!$storage->ensure_storage_ready())
        {
            throw new \RuntimeException('Excel Topics could not create or write the files/exceltopics directory.');
        }

        if ($phase === 'verify')
        {
            $cursor = isset($state['attach_id']) ? (int) $state['attach_id'] : 0;
            $result = $storage->verify_managed_attachments_batch($cursor);

            if (!empty($result['remaining']))
            {
                return array(
                    'phase' => 'verify',
                    'attach_id' => (int) $result['last_attach_id'],
                );
            }

            return array(
                'phase' => 'orphans',
                'filename' => '',
            );
        }

        if ($phase === 'orphans')
        {
            $cursor = isset($state['filename']) ? (string) $state['filename'] : '';
            $result = $storage->cleanup_orphan_files_batch($cursor);

            if (!empty($result['remaining']))
            {
                return array(
                    'phase' => 'orphans',
                    'filename' => (string) $result['last_name'],
                );
            }

            return array('phase' => 'migrations');
        }

        if ($phase === 'migrations')
        {
            return parent::enable_step(false)
                ? array('phase' => 'migrations')
                : false;
        }

        return false;
    }

    /**
     * Before phpBB marks the extension inactive, restore every managed file to
     * the regular upload directory. If restoration fails, an exception keeps
     * the extension enabled so existing downloads are not silently broken.
     *
     * @param mixed $old_state
     * @return array<string, string>|false
     */
    public function disable_step($old_state)
    {
        $storage = $this->create_storage_service();

        try
        {
            $storage->begin_restore_window();
            $result = $storage->restore_managed_attachments_batch();
        }
        catch (\Throwable $exception)
        {
            throw new \RuntimeException(
                'Excel Topics could not restore all XLSX attachments to the phpBB files directory. '
                . 'The extension remains enabled. Detail: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!empty($result['remaining']))
        {
            return array('phase' => 'restore');
        }

        $storage->end_restore_window();
        return false;
    }

    /**
     * Restore managed files before removing extension data even when the
     * extension was already disabled by an older release that had no custom
     * disable lifecycle.
     *
     * @param mixed $old_state
     * @return array<string, string>|false
     */
    public function purge_step($old_state)
    {
        $storage = $this->create_storage_service();

        try
        {
            $storage->begin_restore_window();
            $result = $storage->restore_managed_attachments_batch();
        }
        catch (\Throwable $exception)
        {
            throw new \RuntimeException(
                'Excel Topics could not restore all XLSX attachments before deleting extension data. '
                . 'The purge was stopped. Detail: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!empty($result['remaining']))
        {
            return array('phase' => 'purge_restore');
        }

        $storage->end_restore_window();
        return parent::purge_step(false);
    }

    /**
     * Extension services are not guaranteed to be present in the compiled
     * container while an extension is being enabled. Build the small storage
     * service directly from stable phpBB core services for lifecycle steps.
     *
     * @return \mundophpbb\exceltopics\service\excel_file_storage
     */
    protected function create_storage_service()
    {
        return new \mundophpbb\exceltopics\service\excel_file_storage(
            $this->container->get('config'),
            $this->container->get('dbal.conn'),
            $this->container->get('log'),
            $this->container->get('user'),
            $this->container->getParameter('core.root_path')
        );
    }
}
