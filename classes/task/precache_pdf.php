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
 * Compatibility no-op for queued remote-PDF cache tasks from older releases.
 *
 * Elearning Stream no longer has a remote Google document data plane. Keeping
 * the class allows Moodle cron to consume historical ad-hoc task rows safely
 * after an upgrade instead of failing because the task class disappeared.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class precache_pdf extends \core\task\adhoc_task {
    /**
     * Consume the obsolete task without contacting any upstream provider.
     *
     * @return void
     */
    public function execute(): void {
        debugging(
            'Elearning Stream skipped an obsolete remote-PDF precache task.',
            DEBUG_DEVELOPER
        );
    }
}
