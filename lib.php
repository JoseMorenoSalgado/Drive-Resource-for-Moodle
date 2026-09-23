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

/**
 * Core callbacks for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


use mod_videoplayer\local\drive;
use mod_videoplayer\local\protected_stream;
use mod_videoplayer\local\provider\bunny_stream;

/**
 * File area used for protected local PDF resources.
 */
const VIDEOPLAYER_LOCALPDF_FILEAREA = 'localpdf';

/**
 * Returns the features supported by this plugin.
 *
 * @param string $feature Moodle feature constant.
 * @return bool|null
 */
function videoplayer_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
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
 * Populate cached course-module data, including custom completion rules.
 *
 * @param stdClass $coursemodule Course module record.
 * @return cached_cm_info|false
 */
function videoplayer_get_coursemodule_info($coursemodule) {
    global $DB;

    // Pre-release installations may have an advanced plugin version with a
    // partially applied schema. Build the cached-module query from columns
    // that actually exist so course cache generation cannot take Moodle down
    // before the repair migration has a chance to run.
    static $coursemodulefields = null;
    if ($coursemodulefields === null) {
        $columns = $DB->get_columns('videoplayer');
        $coursemodulefields = ['id', 'name', 'intro', 'introformat'];
        foreach (['completionprogressenabled', 'completionpercentage'] as $optionalfield) {
            if (isset($columns[$optionalfield])) {
                $coursemodulefields[] = $optionalfield;
            }
        }
    }

    $instance = $DB->get_record(
        'videoplayer',
        ['id' => (int)$coursemodule->instance],
        implode(', ', $coursemodulefields),
        IGNORE_MISSING
    );
    if (!$instance) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $instance->name;

    if (!empty($coursemodule->showdescription)) {
        $result->content = format_module_intro(
            'videoplayer',
            $instance,
            (int)$coursemodule->id,
            false
        );
    }

    if ((int)$coursemodule->completion === COMPLETION_TRACKING_AUTOMATIC) {
        $progressenabled = property_exists($instance, 'completionprogressenabled')
            ? !empty($instance->completionprogressenabled)
            : true;
        $threshold = $progressenabled
            ? max(1, min(100, (int)($instance->completionpercentage ?? 80)))
            : 0;
        $result->customdata['customcompletionrules']['completionprogress'] = $threshold;
    }

    return $result;
}

/**
 * Queue PDF precache only for Google Drive resources that can become PDF.
 *
 * @param int $instanceid Activity instance id.
 * @return void
 */
function videoplayer_queue_pdf_precache(int $instanceid): void {
    global $DB;

    $instance = $DB->get_record('videoplayer', ['id' => $instanceid], 'id, source, type, videourl', IGNORE_MISSING);
    if (!$instance || ($instance->source ?? drive::SOURCE_GOOGLEDRIVE) !== drive::SOURCE_GOOGLEDRIVE) {
        return;
    }

    $type = drive::resolve_record_type($instance);
    if (!drive::is_pdf_type($type)) {
        return;
    }

    $task = new \mod_videoplayer\task\precache_pdf();
    $task->set_component('mod_videoplayer');
    $task->set_custom_data(['instanceid' => $instanceid]);
    \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Normalise form data before persistence.
 *
 * @param stdClass $data Submitted instance data.
 * @return stdClass
 */
function videoplayer_normalise_instance_data(stdClass $data): stdClass {
    $allowedsources = [drive::SOURCE_GOOGLEDRIVE, bunny_stream::SOURCE, drive::SOURCE_LOCALPDF];
    $source = clean_param($data->source ?? drive::SOURCE_GOOGLEDRIVE, PARAM_ALPHANUMEXT);
    $data->source = in_array($source, $allowedsources, true) ? $source : drive::SOURCE_GOOGLEDRIVE;

    $allowedtypes = array_merge([drive::TYPE_AUTO], drive::RESOURCE_TYPES);
    $type = clean_param($data->type ?? drive::TYPE_AUTO, PARAM_ALPHANUMEXT);
    $data->type = in_array($type, $allowedtypes, true) ? $type : drive::TYPE_AUTO;

    if ($data->source === drive::SOURCE_LOCALPDF) {
        $data->type = 'pdf';
        $data->videourl = '';
        $data->providerassetid = null;
        $data->provideruploadid = null;
        $data->providerfilesize = 0;
        $data->providerstatus = null;
        $data->displaymode = 'standard';
        $data->disabledownload = 1;
    } else if ($data->source === bunny_stream::SOURCE) {
        $data->type = 'video';
        $data->videourl = '';
        $data->providerassetid = trim((string)($data->providerassetid ?? ''));
        $data->provideruploadid = trim((string)($data->provideruploadid ?? ''));
        $data->providerfilesize = max(0, (int)($data->providerfilesize ?? 0));
        $data->providerstatus = bunny_stream::normalise_status((string)($data->providerstatus ?? ''));
        $data->displaymode = 'standard';
        $data->disabledownload = 1;
    } else {
        $data->videourl = trim((string)($data->videourl ?? ''));
        $data->providerassetid = null;
        $data->provideruploadid = null;
        $data->providerfilesize = 0;
        $data->providerstatus = null;
        $data->displaymode = 'standard';
    }

    // Direct-download UI is not supported by the protected-only architecture.
    // Keep the legacy database field pinned for backup/restore compatibility.
    $data->disabledownload = 1;
    $data->disablecontextmenu = empty($data->disablecontextmenu) ? 0 : 1;
    $data->enablewatermark = empty($data->enablewatermark) ? 0 : 1;
    $data->enablegamification = empty($data->enablegamification) ? 0 : 1;
    $data->pointsperpage = max(0, min(100, (int)($data->pointsperpage ?? 1)));
    $data->completionpercentage = max(1, min(100, (int)($data->completionpercentage ?? 80)));

    return $data;
}

/**
 * Persist the protected local PDF file for this module instance.
 *
 * @param stdClass $data Submitted instance data.
 * @return void
 */
function videoplayer_save_localpdf_file(stdClass $data): void {
    if (
        ($data->source ?? drive::SOURCE_GOOGLEDRIVE) !== drive::SOURCE_LOCALPDF
            || empty($data->localpdffile)
            || empty($data->coursemodule)
    ) {
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
 * Queue server-to-server binding of a Bunny asset to this Moodle activity.
 *
 * @param stdClass $instance Persisted activity instance.
 * @return void
 */
function videoplayer_queue_bunny_bind(stdClass $instance): void {
    if (
        ($instance->source ?? '') !== bunny_stream::SOURCE
        || !bunny_stream::is_valid_asset_id((string)($instance->providerassetid ?? ''))
        || !bunny_stream::is_valid_upload_id((string)($instance->provideruploadid ?? ''))
    ) {
        return;
    }

    $task = new \mod_videoplayer\task\bind_bunny_asset();
    $task->set_component('mod_videoplayer');
    $task->set_custom_data([
        'instanceid' => (int)$instance->id,
        'courseid' => (int)$instance->course,
        'videoid' => (string)$instance->providerassetid,
        'uploadid' => (string)$instance->provideruploadid,
    ]);
    \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Queue release of a Bunny asset through WHMCS.
 *
 * No destructive Bunny API operation runs from Moodle. WHMCS applies reference
 * counting and the configured retention policy.
 *
 * @param stdClass $instance Persisted activity instance.
 * @return void
 */
function videoplayer_queue_bunny_release(stdClass $instance): void {
    if (
        ($instance->source ?? '') !== bunny_stream::SOURCE
        || !bunny_stream::is_valid_asset_id((string)($instance->providerassetid ?? ''))
    ) {
        return;
    }

    $task = new \mod_videoplayer\task\release_bunny_asset();
    $task->set_component('mod_videoplayer');
    $task->set_custom_data([
        'instanceid' => (int)$instance->id,
        'videoid' => (string)$instance->providerassetid,
    ]);
    \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Add a module instance.
 *
 * @param stdClass $data Submitted instance data.
 * @param moodleform|null $mform Moodle form.
 * @return int New instance id.
 */
function videoplayer_add_instance($data, $mform = null) {
    global $DB;

    $data = videoplayer_normalise_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    $id = (int)$DB->insert_record('videoplayer', $data);
    $data->id = $id;
    videoplayer_save_localpdf_file($data);
    videoplayer_queue_pdf_precache($id);
    videoplayer_queue_bunny_bind($data);

    return $id;
}

/**
 * Invalidate the cached PDF representation for one persisted Drive resource.
 *
 * @param stdClass $instance Persisted activity instance.
 * @return void
 */
function videoplayer_invalidate_instance_pdf_cache(stdClass $instance): void {
    if (($instance->source ?? drive::SOURCE_GOOGLEDRIVE) !== drive::SOURCE_GOOGLEDRIVE) {
        return;
    }

    $url = trim((string)($instance->videourl ?? ''));
    $fileid = drive::extract_file_id($url);
    $type = drive::resolve_record_type($instance);
    if ($fileid && drive::is_pdf_type($type)) {
        protected_stream::invalidate_pdf_cache($fileid, $type);
    }
}

/**
 * Update a module instance.
 *
 * @param stdClass $data Submitted instance data.
 * @param moodleform|null $mform Moodle form.
 * @return bool
 */
function videoplayer_update_instance($data, $mform = null) {
    global $DB;

    $oldinstance = $DB->get_record('videoplayer', ['id' => (int)$data->instance], '*', MUST_EXIST);
    $data = videoplayer_normalise_instance_data($data);
    $data->timemodified = time();
    $data->id = (int)$data->instance;

    $result = $DB->update_record('videoplayer', $data);
    if ($result) {
        videoplayer_invalidate_instance_pdf_cache($oldinstance);
        videoplayer_invalidate_instance_pdf_cache($data);
        videoplayer_save_localpdf_file($data);
        videoplayer_queue_pdf_precache($data->id);

        $oldasset = (string)($oldinstance->providerassetid ?? '');
        $newasset = (string)($data->providerassetid ?? '');
        $oldisbunny = ($oldinstance->source ?? '') === bunny_stream::SOURCE;
        $newisbunny = ($data->source ?? '') === bunny_stream::SOURCE;

        if ($oldisbunny && (!$newisbunny || $oldasset !== $newasset)) {
            videoplayer_queue_bunny_release($oldinstance);
        }
        if (
            $newisbunny && (
                !$oldisbunny
                || $oldasset !== $newasset
                || (string)($oldinstance->provideruploadid ?? '') !== (string)($data->provideruploadid ?? '')
            )
        ) {
            videoplayer_queue_bunny_bind($data);
        }
    }

    return $result;
}

/**
 * Delete a module instance and its user data/local protected files.
 *
 * @param int $id Instance id.
 * @return bool
 */
function videoplayer_delete_instance($id) {
    global $DB;

    $instance = $DB->get_record('videoplayer', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    $cm = get_coursemodule_from_instance('videoplayer', $instance->id, $instance->course, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        get_file_storage()->delete_area_files($context->id, 'mod_videoplayer', VIDEOPLAYER_LOCALPDF_FILEAREA);
    }

    videoplayer_invalidate_instance_pdf_cache($instance);
    videoplayer_queue_bunny_release($instance);

    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('videoplayer_rewards', ['videoplayerid' => $instance->id]);
    $DB->delete_records('videoplayer_views', ['videoplayerid' => $instance->id]);
    $DB->delete_records('videoplayer', ['id' => $instance->id]);
    $transaction->allow_commit();

    return true;
}

/**
 * Fetch the single protected local PDF file for a module context.
 *
 * @param context_module $context Module context.
 * @return stored_file|null
 */
function videoplayer_get_localpdf_file(context_module $context): ?stored_file {
    $files = get_file_storage()->get_area_files(
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
