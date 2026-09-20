<?php
// This file is part of Moodle - https://moodle.org/
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

/**
 * Core callbacks for the Drive Resource activity module.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoplayer\local\drive;

/** Moodle File API area for locally protected PDF files. */
const VIDEOPLAYER_LOCALPDF_FILEAREA = 'localpdf';

/**
 * Returns the features supported by this plugin.
 *
 * @param string $feature
 * @return bool|null
 */
function videoplayer_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
            return false;
        default:
            return null;
    }
}

/**
 * Queue PDF precache only for Google Drive PDF resources.
 *
 * @param int $instanceid
 */
function videoplayer_queue_pdf_precache(int $instanceid): void {
    global $DB;

    $instance = $DB->get_record(
        'videoplayer',
        ['id' => $instanceid],
        'id, source, type, videourl',
        IGNORE_MISSING
    );
    if (
        !$instance ||
        ($instance->source ?? drive::SOURCE_GOOGLEDRIVE) !== drive::SOURCE_GOOGLEDRIVE ||
        !drive::is_pdf_type(drive::resolve_record_type($instance))
    ) {
        return;
    }

    $task = new \mod_videoplayer\task\precache_pdf();
    $task->set_component('mod_videoplayer');
    $task->set_custom_data(['instanceid' => $instanceid]);
    \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Normalise form data before insert or update.
 *
 * @param stdClass $data
 * @return stdClass
 */
function videoplayer_normalise_instance_data(stdClass $data): stdClass {
    $source = clean_param((string) ($data->source ?? drive::SOURCE_GOOGLEDRIVE), PARAM_ALPHANUMEXT);
    $data->source = in_array($source, [drive::SOURCE_GOOGLEDRIVE, 'localpdf'], true)
        ? $source
        : drive::SOURCE_GOOGLEDRIVE;

    // PDF.js is the only production PDF renderer.
    $data->displaymode = 'pdfjs';

    if ($data->source === 'localpdf') {
        $data->type = 'pdf';
        $data->videourl = '';
        $data->disabledownload = 1;
    } else {
        $data->videourl = trim((string) ($data->videourl ?? ''));
        $type = clean_param((string) ($data->type ?? drive::TYPE_VIDEO), PARAM_ALPHANUMEXT);
        $data->type = drive::is_supported_configured_type($type) ? $type : drive::TYPE_VIDEO;
    }

    $data->disabledownload = empty($data->disabledownload) ? 0 : 1;
    $data->disablecontextmenu = empty($data->disablecontextmenu) ? 0 : 1;
    $data->enablewatermark = empty($data->enablewatermark) ? 0 : 1;
    $data->enablegamification = empty($data->enablegamification) ? 0 : 1;
    $data->pointsperpage = max(0, min(100, (int)($data->pointsperpage ?? 1)));

    return $data;
}

/**
 * Persist the protected local PDF file for this module instance.
 *
 * @param stdClass $data
 * @param int $instanceid
 * @return void
 */
function videoplayer_save_localpdf_file(stdClass $data, int $instanceid): void {
    if (($data->source ?? 'googledrive') !== 'localpdf' || empty($data->localpdffile) || empty($data->coursemodule)) {
        return;
    }

    $context = context_module::instance((int)$data->coursemodule);
    file_save_draft_area_files(
        (int)$data->localpdffile,
        $context->id,
        'mod_videoplayer',
        VIDEOPLAYER_LOCALPDF_FILEAREA,
        0,
        [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.pdf'],
        ]
    );
}

/**
 * Add a module instance.
 *
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return int
 */
function videoplayer_add_instance($data, $mform = null) {
    global $DB;

    $data = videoplayer_normalise_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    $id = $DB->insert_record('videoplayer', $data);
    videoplayer_save_localpdf_file($data, (int)$id);
    videoplayer_queue_pdf_precache((int)$id);

    return $id;
}

/**
 * Update a module instance.
 *
 * @param stdClass $data
 * @param moodleform|null $mform
 * @return bool
 */
function videoplayer_update_instance($data, $mform = null) {
    global $DB;

    $data = videoplayer_normalise_instance_data($data);
    $data->timemodified = time();
    $data->id = $data->instance;

    $result = $DB->update_record('videoplayer', $data);
    if ($result) {
        videoplayer_save_localpdf_file($data, (int)$data->id);
        videoplayer_queue_pdf_precache((int)$data->id);
    }

    return $result;
}

/**
 * Delete a module instance and its protected local files.
 *
 * @param int $id
 * @return bool
 */
function videoplayer_delete_instance($id) {
    global $DB;

    if (!$videoplayer = $DB->get_record('videoplayer', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('videoplayer', $videoplayer->id, $videoplayer->course, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_videoplayer', VIDEOPLAYER_LOCALPDF_FILEAREA);
    }

    $DB->delete_records('videoplayer_rewards', ['videoplayerid' => $videoplayer->id]);
    $DB->delete_records('videoplayer_views', ['videoplayerid' => $videoplayer->id]);

    return $DB->delete_records('videoplayer', ['id' => $videoplayer->id]);
}

/**
 * Fetch the single protected local PDF file for a module context.
 *
 * @param context_module $context
 * @return stored_file|null
 */
function videoplayer_get_localpdf_file(context_module $context): ?stored_file {
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'mod_videoplayer',
        VIDEOPLAYER_LOCALPDF_FILEAREA,
        0,
        'itemid, filepath, filename',
        false
    );

    foreach ($files as $file) {
        if (!$file->is_directory() && $file->get_mimetype() === 'application/pdf') {
            return $file;
        }
    }

    return null;
}
