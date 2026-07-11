<?php
namespace phpbb\extension
{
    class base
    {
        public $migration_enable_calls = 0;

        public function enable_step($old_state)
        {
            $this->migration_enable_calls++;
            return $this->migration_enable_calls === 1;
        }

        public function purge_step($old_state)
        {
            return false;
        }
    }
}

namespace
{
    if (PHP_SAPI !== 'cli')
    {
        http_response_code(404);
        exit;
    }

    require_once __DIR__ . '/../ext.php';

    use mundophpbb\exceltopics\ext;

    function ext_expect($condition, $message)
    {
        if (!$condition)
        {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    class ext_fake_storage
    {
        public $verify_calls = 0;
        public $cleanup_calls = 0;
        public $restore_calls = 0;
        public $throw_on_restore = false;
        public $begin_restore_calls = 0;
        public $end_restore_calls = 0;

        public function ensure_storage_ready()
        {
            return true;
        }

        public function verify_managed_attachments_batch($cursor)
        {
            $this->verify_calls++;
            if ($this->verify_calls === 1)
            {
                return array('last_attach_id' => 50, 'remaining' => true);
            }
            return array('last_attach_id' => 75, 'remaining' => false);
        }

        public function cleanup_orphan_files_batch($cursor)
        {
            $this->cleanup_calls++;
            if ($this->cleanup_calls === 1)
            {
                return array('last_name' => '50_file', 'remaining' => true, 'deleted' => 0);
            }
            return array('last_name' => '99_file', 'remaining' => false, 'deleted' => 1);
        }

        public function begin_restore_window()
        {
            $this->begin_restore_calls++;
        }

        public function end_restore_window()
        {
            $this->end_restore_calls++;
        }

        public function restore_managed_attachments_batch()
        {
            $this->restore_calls++;
            if ($this->throw_on_restore)
            {
                throw new RuntimeException('source missing');
            }
            return array('processed' => 25, 'remaining' => $this->restore_calls === 1);
        }
    }

    class test_excel_topics_ext extends ext
    {
        private $storage;

        public function __construct($storage)
        {
            $this->storage = $storage;
        }

        protected function create_storage_service()
        {
            return $this->storage;
        }
    }

    $storage = new ext_fake_storage();
    $extension = new test_excel_topics_ext($storage);

    $state = $extension->enable_step(false);
    ext_expect($state['phase'] === 'verify' && $state['attach_id'] === 50, 'enable verification was split into batches');
    $state = $extension->enable_step($state);
    ext_expect($state['phase'] === 'orphans' && $state['filename'] === '', 'enable advanced from verification to orphan cleanup');
    $state = $extension->enable_step($state);
    ext_expect($state['phase'] === 'orphans' && $state['filename'] === '50_file', 'orphan cleanup was split into batches');
    $state = $extension->enable_step($state);
    ext_expect($state['phase'] === 'migrations', 'enable advanced from integrity work to migrations');
    $state = $extension->enable_step($state);
    ext_expect($state['phase'] === 'migrations', 'enable kept running while migrations remained');
    $state = $extension->enable_step($state);
    ext_expect($state === false, 'enable lifecycle completed after migrations');

    $state = $extension->disable_step(false);
    ext_expect($state['phase'] === 'restore', 'disable remained active while managed files remained');
    $state = $extension->disable_step($state);
    ext_expect($state === false, 'disable completed only after all managed files were restored');
    ext_expect($storage->begin_restore_calls === 2, 'disable refreshed the restore lease for every batch');
    ext_expect($storage->end_restore_calls >= 5, 'enable cleared stale leases and disable cleared the completed lease');

    $purge_storage = new ext_fake_storage();
    $purge_extension = new test_excel_topics_ext($purge_storage);
    $state = $purge_extension->purge_step(false);
    ext_expect($state['phase'] === 'purge_restore', 'purge restored managed files in bounded batches');
    $state = $purge_extension->purge_step($state);
    ext_expect($state === false, 'purge completed only after file restoration');
    ext_expect($purge_storage->begin_restore_calls === 2, 'purge refreshed the restore lease for every batch');
    ext_expect($purge_storage->end_restore_calls === 1, 'purge cleared the restore lease after completion');

    $failing_storage = new ext_fake_storage();
    $failing_storage->throw_on_restore = true;
    $failing_extension = new test_excel_topics_ext($failing_storage);
    $blocked = false;
    try
    {
        $failing_extension->disable_step(false);
    }
    catch (RuntimeException $exception)
    {
        $blocked = strpos($exception->getMessage(), 'remains enabled') !== false;
    }
    ext_expect($blocked, 'restore failure blocked extension disable with a clear error');
    ext_expect($failing_storage->begin_restore_calls === 1 && $failing_storage->end_restore_calls === 0, 'failed disable kept only a short expiring restore lease');

    echo "extension lifecycle smoke test: OK\n";
}
