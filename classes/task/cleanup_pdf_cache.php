<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_videoplayer\task;

/**
 * Compatibility no-op for obsolete scheduled PDF-cache rows.
 *
 * This class remains for one compatibility cycle so an upgraded site can
 * safely consume stale task metadata. It is no longer registered in tasks.php.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated since Elearning Stream 1.3.0-rc4
 */
final class cleanup_pdf_cache extends \core\task\scheduled_task {
    /**
     * Return the compatibility task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_pdf_cache', 'mod_videoplayer');
    }

    /**
     * Consume obsolete invocations without performing work.
     *
     * @return void
     */
    public function execute(): void {
        debugging(
            'Elearning Stream skipped an obsolete PDF-cache cleanup task.',
            DEBUG_DEVELOPER
        );
    }
}
