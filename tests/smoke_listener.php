<?php
namespace Symfony\Component\EventDispatcher
{
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents();
    }
}

namespace
{
    if (PHP_SAPI !== 'cli')
    {
        http_response_code(404);
        exit;
    }

    if (!defined('INLINE_LINK'))
    {
        define('INLINE_LINK', 1);
    }

    require_once __DIR__ . '/../event/main_listener.php';

    use mundophpbb\exceltopics\event\main_listener;

    function listener_expect($condition, $message)
    {
        if (!$condition)
        {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    class listener_fake_storage
    {
        public $ensured = array();
        public $download_calls = array();
        public $delete_prepared = array();
        public $delete_after_calls = 0;

        public function ensure_attachment_storage(array $attachment)
        {
            $this->ensured[] = $attachment['attach_id'];
            $attachment['physical_filename'] = 'exceltopics/' . $attachment['attach_id'] . '_' . $attachment['physical_filename'];
            return $attachment;
        }

        public function resolve_attachment_path(array $attachment)
        {
            return '/safe/' . $attachment['physical_filename'];
        }

        public function restore_download_attachment_path(array $attachment)
        {
            $this->download_calls[] = $attachment['attach_id'];
            $attachment['physical_filename'] = 'exceltopics/' . $attachment['physical_filename'];
            return $attachment;
        }

        public function is_managed_xlsx_attachment(array $attachment)
        {
            return isset($attachment['physical_filename'])
                && strpos($attachment['physical_filename'], 'exceltopics/') === 0
                && isset($attachment['extension'])
                && strtolower($attachment['extension']) === 'xlsx';
        }

        public function prepare_delete_physical(array $physical)
        {
            $this->delete_prepared = $physical;
            return array_values(array_filter($physical, function ($row)
            {
                return strpos($row['filename'], 'exceltopics/') !== 0;
            }));
        }

        public function remove_prepared_deleted_files(&$space_removed, &$files_removed)
        {
            $this->delete_after_calls++;
            $space_removed += 123;
            $files_removed++;
        }
    }

    class listener_fake_renderer
    {
        public $calls = array();

        public function render(array $attachment, $path, array $context = array())
        {
            $this->calls[] = array('attachment' => $attachment, 'path' => $path, 'context' => $context);
            return '<section>' . htmlspecialchars($attachment['real_filename'], ENT_QUOTES, 'UTF-8') . '</section>';
        }
    }

    $storage = new listener_fake_storage();
    $renderer = new listener_fake_renderer();
    $listener = new main_listener($renderer, $storage);

    $subscriptions = main_listener::getSubscribedEvents();
    listener_expect(isset($subscriptions['core.delete_attachments_before']), 'attachment delete-before event is subscribed');
    listener_expect(isset($subscriptions['core.delete_attachments_from_filesystem_after']), 'attachment filesystem-after event is subscribed');
    listener_expect(isset($subscriptions['core.download_file_send_to_browser_before']), 'download event is subscribed');

    $event = new \ArrayObject(array(
        'topic_data' => array('topic_id' => 100, 'topic_first_post_id' => 42),
        'attachments' => array(
            42 => array(
                array(
                    'attach_id' => 10,
                    'extension' => 'xlsx',
                    'real_filename' => 'arquivo.xlsx.exe',
                    'physical_filename' => 'invalid',
                    'poster_id' => 5,
                ),
                array(
                    'attach_id' => 9,
                    'extension' => 'xlsx',
                    'real_filename' => 'catalogo-novo.xlsx',
                    'physical_filename' => 'new_xlsx',
                    'poster_id' => 5,
                ),
                array(
                    'attach_id' => 8,
                    'extension' => 'xlsx',
                    'real_filename' => 'catalogo-antigo.xlsx',
                    'physical_filename' => 'old_xlsx',
                    'poster_id' => 6,
                ),
                array(
                    'attach_id' => 7,
                    'extension' => 'pdf',
                    'real_filename' => 'manual.pdf',
                    'physical_filename' => 'physical_pdf',
                    'poster_id' => 5,
                ),
            ),
        ),
    ));

    $listener->prepare_topic_table($event);
    listener_expect($storage->ensured === array(9, 8), 'only genuine XLSX attachments were sent to storage');
    listener_expect(count($renderer->calls) === 1, 'only one XLSX attachment was rendered');
    listener_expect($renderer->calls[0]['attachment']['attach_id'] === 9, 'newest XLSX was selected');
    listener_expect($renderer->calls[0]['path'] === '/safe/exceltopics/9_new_xlsx', 'managed path was resolved through storage');
    listener_expect($renderer->calls[0]['context']['topic_id'] === 100, 'topic id was passed to renderer context');
    listener_expect($renderer->calls[0]['context']['post_id'] === 42, 'post id was passed to renderer context');
    listener_expect($renderer->calls[0]['context']['attachment_poster_id'] === 5, 'attachment author was passed to renderer context');
    listener_expect($event['attachments'][42][1]['physical_filename'] === 'exceltopics/9_new_xlsx', 'event attachment data received the managed path');

    $download_event = new \ArrayObject(array(
        'download_mode' => 2,
        'attachment' => array(
            'attach_id' => 9,
            'extension' => 'xlsx',
            'real_filename' => 'catalogo-novo.xlsx',
            'physical_filename' => '9_new_xlsx',
        ),
    ));
    $listener->restore_stored_attachment_path($download_event);
    listener_expect($download_event['attachment']['physical_filename'] === 'exceltopics/9_new_xlsx', 'download path was delegated to storage');
    listener_expect($download_event['download_mode'] === INLINE_LINK, 'managed XLSX download was forced through phpBB streaming');

    $delete_event = new \ArrayObject(array(
        'physical' => array(
            array('filename' => 'exceltopics/9_new_xlsx', 'filesize' => 123, 'is_orphan' => 0, 'thumbnail' => 0),
            array('filename' => 'regular_file', 'filesize' => 10, 'is_orphan' => 0, 'thumbnail' => 0),
        ),
    ));
    $listener->prepare_excel_attachment_deletion($delete_event);
    listener_expect(count($delete_event['physical']) === 1, 'managed file was removed from phpBB core deletion list');
    listener_expect($delete_event['physical'][0]['filename'] === 'regular_file', 'regular attachment remained in core deletion list');

    $after_delete_event = new \ArrayObject(array('space_removed' => 10, 'files_removed' => 1));
    $listener->remove_excel_attachment_files($after_delete_event);
    listener_expect($after_delete_event['space_removed'] === 133, 'managed file size was added to phpBB counter update');
    listener_expect($after_delete_event['files_removed'] === 2, 'managed file count was added to phpBB counter update');
    listener_expect($storage->delete_after_calls === 1, 'managed filesystem deletion was delegated once');

    $row_event = new \ArrayObject(array(
        'row' => array('post_id' => 42),
        'post_row' => array('MESSAGE' => 'Post'),
    ));
    $listener->add_table_to_post_row($row_event);
    listener_expect(strpos($row_event['post_row']['EXCEL_TOPIC_TABLE_HTML'], 'catalogo-novo.xlsx') !== false, 'rendered table was attached to first post template row');

    $empty_event = new \ArrayObject(array(
        'topic_data' => array('topic_first_post_id' => 42),
        'attachments' => array(),
    ));
    $listener->prepare_topic_table($empty_event);
    $cleared_row_event = new \ArrayObject(array(
        'row' => array('post_id' => 42),
        'post_row' => array(),
    ));
    $listener->add_table_to_post_row($cleared_row_event);
    listener_expect($cleared_row_event['post_row']['EXCEL_TOPIC_TABLE_HTML'] === '', 'a new topic event cleared previous rendered state');

    $language_event = new \ArrayObject(array('lang_set_ext' => array()));
    $listener->load_language_on_setup($language_event);
    listener_expect($language_event['lang_set_ext'][0]['ext_name'] === 'mundophpbb/exceltopics', 'language registration was added');

    echo "listener smoke test: OK\n";
}
