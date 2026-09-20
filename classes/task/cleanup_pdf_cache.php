<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\task;

defined('MOODLE_INTERNAL') || die();

use mod_videoplayer\local\protected_stream;

/**
 * Scheduled task that removes expired protected PDF cache files.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_pdf_cache extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_pdf_cache', 'mod_videoplayer');
    }

    /**
     * Execute the cache cleanup task.
     *
     * @return void
     */
    public function execute(): void {
        protected_stream::cleanup_pdf_cache();
    }
}
