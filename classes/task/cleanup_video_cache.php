<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_videoplayer\task;

use mod_videoplayer\local\video_normalizer;

/**
 * Scheduled cleanup for normalized protected video cache.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_video_cache extends \core\task\scheduled_task {
    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_video_cache', 'mod_videoplayer');
    }

    /**
     * Execute cleanup.
     *
     * @return void
     */
    public function execute(): void {
        video_normalizer::cleanup();
    }
}
