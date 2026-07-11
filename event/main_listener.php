<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
    /** @var \mundophpbb\exceltopics\service\table_renderer */
    protected $renderer;

    /** @var \mundophpbb\exceltopics\service\excel_file_storage */
    protected $storage;

    /** @var array<int, string> */
    protected $rendered_by_post = array();

    /**
     * @param \mundophpbb\exceltopics\service\table_renderer $renderer
     * @param \mundophpbb\exceltopics\service\excel_file_storage $storage
     */
    public function __construct($renderer, $storage)
    {
        $this->renderer = $renderer;
        $this->storage = $storage;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents()
    {
        return array(
            'core.user_setup' => 'load_language_on_setup',
            'core.viewtopic_modify_post_data' => 'prepare_topic_table',
            'core.viewtopic_modify_post_row' => 'add_table_to_post_row',
            'core.download_file_send_to_browser_before' => 'restore_stored_attachment_path',
            'core.delete_attachments_before' => 'prepare_excel_attachment_deletion',
            'core.delete_attachments_from_filesystem_after' => 'remove_excel_attachment_files',
        );
    }

    /**
     * @param \phpbb\event\data $event
     * @return void
     */
    public function load_language_on_setup($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = array(
            'ext_name' => 'mundophpbb/exceltopics',
            'lang_set' => 'common',
        );
        $event['lang_set_ext'] = $lang_set_ext;
    }

    /**
     * Render only the newest XLSX attachment belonging to the topic's first post.
     * Persisted XLSX files are organized under files/exceltopics on first view.
     *
     * @param \phpbb\event\data $event
     * @return void
     */
    public function prepare_topic_table($event)
    {
        $this->rendered_by_post = array();

        $topic_data = $event['topic_data'];
        $attachments = $event['attachments'];
        $first_post_id = isset($topic_data['topic_first_post_id'])
            ? (int) $topic_data['topic_first_post_id']
            : 0;

        if ($first_post_id <= 0 || empty($attachments[$first_post_id]) || !is_array($attachments[$first_post_id]))
        {
            return;
        }

        $selected_attachment = null;
        $selected_attach_id = -1;

        foreach ($attachments[$first_post_id] as $index => $attachment)
        {
            if (!is_array($attachment) || !$this->is_xlsx_attachment($attachment))
            {
                continue;
            }

            $attachment = $this->storage->ensure_attachment_storage($attachment);
            $attachments[$first_post_id][$index] = $attachment;

            $attach_id = isset($attachment['attach_id']) ? (int) $attachment['attach_id'] : 0;
            if ($selected_attachment === null || $attach_id > $selected_attach_id)
            {
                $selected_attachment = $attachment;
                $selected_attach_id = $attach_id;
            }
        }

        $event['attachments'] = $attachments;

        if ($selected_attachment === null)
        {
            return;
        }

        $path = $this->storage->resolve_attachment_path($selected_attachment);
        $context = array(
            'topic_id' => isset($topic_data['topic_id']) ? (int) $topic_data['topic_id'] : 0,
            'post_id' => $first_post_id,
            'attach_id' => isset($selected_attachment['attach_id']) ? (int) $selected_attachment['attach_id'] : 0,
            'attachment_poster_id' => isset($selected_attachment['poster_id']) ? (int) $selected_attachment['poster_id'] : 0,
        );

        $this->rendered_by_post[$first_post_id] = $this->renderer->render($selected_attachment, $path, $context);
    }

    /**
     * Restore the controlled subdirectory after phpBB has applied basename() to
     * the physical filename in download/file.php.
     *
     * @param \phpbb\event\data $event
     * @return void
     */
    public function restore_stored_attachment_path($event)
    {
        $attachment = $event['attachment'];
        if (is_array($attachment))
        {
            $attachment = $this->storage->restore_download_attachment_path($attachment);
            $event['attachment'] = $attachment;

            // Managed XLSX files must always pass through phpBB's authenticated
            // download handler. PHYSICAL_LINK would redirect directly into the
            // protected files/exceltopics directory and can fail depending on
            // the web server configuration.
            if ($this->storage->is_managed_xlsx_attachment($attachment))
            {
                $event['download_mode'] = INLINE_LINK;
            }
        }
    }

    /**
     * Remove managed paths from phpBB's basename-based deletion list. The full
     * paths are retained by the storage service until the filesystem-after event.
     *
     * @param \phpbb\event\data $event
     * @return void
     */
    public function prepare_excel_attachment_deletion($event)
    {
        $physical = $event['physical'];
        if (is_array($physical))
        {
            $event['physical'] = $this->storage->prepare_delete_physical($physical);
        }
    }

    /**
     * Delete the managed files and add their sizes/counts to phpBB's native
     * counter update.
     *
     * @param \phpbb\event\data $event
     * @return void
     */
    public function remove_excel_attachment_files($event)
    {
        $space_removed = isset($event['space_removed']) ? (int) $event['space_removed'] : 0;
        $files_removed = isset($event['files_removed']) ? (int) $event['files_removed'] : 0;

        $this->storage->remove_prepared_deleted_files($space_removed, $files_removed);

        $event['space_removed'] = $space_removed;
        $event['files_removed'] = $files_removed;
    }

    /**
     * @param \phpbb\event\data $event
     * @return void
     */
    public function add_table_to_post_row($event)
    {
        $row = $event['row'];
        $post_row = $event['post_row'];
        $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;

        $post_row['EXCEL_TOPIC_TABLE_HTML'] = isset($this->rendered_by_post[$post_id])
            ? $this->rendered_by_post[$post_id]
            : '';

        $event['post_row'] = $post_row;
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
}
