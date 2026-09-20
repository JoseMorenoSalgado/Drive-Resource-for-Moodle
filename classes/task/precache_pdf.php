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


use mod_videoplayer\local\drive;
use mod_videoplayer\local\protected_stream;

/**
 * Ad-hoc task that pre-warms the protected Google Drive PDF cache.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class precache_pdf extends \core\task\adhoc_task {
    /**
     * Execute the cache warming task.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->instanceid)) {
            return;
        }

        $record = $DB->get_record('videoplayer', ['id' => (int)$data->instanceid]);
        if (!$record || ($record->source ?? 'googledrive') !== 'googledrive') {
            return;
        }

        $type = empty($record->type) || $record->type === 'auto'
            ? drive::detect_type($record->videourl)
            : clean_param($record->type, PARAM_ALPHANUMEXT);

        if (!drive::is_pdf_type($type) || (string)get_config('mod_videoplayer', 'pdfcacheenabled') === '0') {
            return;
        }

        $fileid = drive::extract_file_id($record->videourl);
        if (!$fileid) {
            return;
        }

        $url = drive::protected_content_url($fileid, $type);
        if (!$url) {
            return;
        }

        $cachefile = protected_stream::cache_file_for($fileid, $type);
        if (protected_stream::is_fresh_pdf_cache($cachefile)) {
            return;
        }

        protected_stream::warm_drive_pdf_cache($url, $cachefile);
    }
}
