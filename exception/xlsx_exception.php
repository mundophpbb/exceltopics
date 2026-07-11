<?php
/**
 * Excel Topics extension for phpBB.
 *
 * @copyright (c) 2026 Mundo phpBB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\exceltopics\exception;

class xlsx_exception extends \RuntimeException
{
    /** @var string */
    protected $language_key;

    /**
     * @param string $language_key phpBB language key for the public error
     * @param string $detail Internal diagnostic detail
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct($language_key, $detail = '', ?\Throwable $previous = null)
    {
        $this->language_key = $language_key;
        parent::__construct($detail !== '' ? $detail : $language_key, 0, $previous);
    }

    /**
     * @return string
     */
    public function get_language_key()
    {
        return $this->language_key;
    }
}
