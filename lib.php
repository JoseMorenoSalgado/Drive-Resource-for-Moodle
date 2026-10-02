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
 * Core callbacks for Elearning Stream.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


use mod_videoplayer\local\resource_compatibility;
use mod_videoplayer\local\provider\bunny_asset_lifecycle;
use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\whmcs_gateway_client;

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
 * Normalise form data before persistence.
 *
 * @param stdClass $data Submitted instance data.
 * @return stdClass
 */
function videoplayer_normalise_instance_data(stdClass $data): stdClass {
    $allowedsources = [bunny_stream::SOURCE, resource_compatibility::SOURCE_LOCALPDF];
    $source = clean_param((string)($data->source ?? bunny_stream::SOURCE), PARAM_ALPHANUMEXT);
    $data->source = in_array($source, $allowedsources, true)
        ? $source
        : bunny_stream::SOURCE;

    if ($data->source === resource_compatibility::SOURCE_LOCALPDF) {
        $data->providerassetid = null;
        $data->provideruploadid = null;
        $data->providerfilesize = 0;
        $data->providerstatus = null;
    } else {
        $streammode = clean_param((string)($data->streaminputmode ?? 'upload'), PARAM_ALPHA);
        if ($streammode === 'url') {
            $streamurl = trim((string)($data->streamurl ?? ''));
            if (bunny_stream::extract_candidate_asset_id_from_url($streamurl) === null) {
                throw new moodle_exception('invalidstreamurl', 'mod_videoplayer');
            }

            $import = (new whmcs_gateway_client())->import_asset_url(
                $streamurl,
                (int)($data->course ?? 0)
            );
            $data->providerassetid = (string)$import['videoid'];
            $data->provideruploadid = (string)$import['uploadid'];
            $data->providerfilesize = max(0, (int)$import['filesize']);
            $data->providerstatus = bunny_stream::normalise_status((string)$import['status']);
        } else {
            $data->providerassetid = trim((string)($data->providerassetid ?? ''));
            $data->provideruploadid = trim((string)($data->provideruploadid ?? ''));
            $data->providerfilesize = max(0, (int)($data->providerfilesize ?? 0));
            $data->providerstatus = bunny_stream::normalise_status(
                (string)($data->providerstatus ?? '')
            );
        }
    }

    unset($data->streaminputmode, $data->streamurl);

    // Ignore fields from historical forms/backups which are no longer part of
    // the production activity schema.
    foreach ([
        'videourl',
        'type',
        'displaymode',
        'disabledownload',
        'enablegamification',
        'pointsperpage',
        'video',
        'endscreentext',
        'displayasstartscreen',
        'starttime',
        'endtime',
        'grade',
        'displayoptions',
        'posterimage',
        'extendedcompletion',
    ] as $obsoletefield) {
        unset($data->{$obsoletefield});
    }

    $data->disablecontextmenu = empty($data->disablecontextmenu) ? 0 : 1;
    $data->enablewatermark = empty($data->enablewatermark) ? 0 : 1;
    $data->completionpercentage = max(
        1,
        min(100, (int)($data->completionpercentage ?? 80))
    );

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
        ($data->source ?? bunny_stream::SOURCE) !== resource_compatibility::SOURCE_LOCALPDF
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
    (new bunny_asset_lifecycle())->after_create($data);

    return $id;
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
        videoplayer_save_localpdf_file($data);
        (new bunny_asset_lifecycle())->after_update($oldinstance, $data);
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
    $contextid = $cm ? context_module::instance($cm->id)->id : 0;

    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('videoplayer_views', ['videoplayerid' => $instance->id]);
    $DB->delete_records('videoplayer', ['id' => $instance->id]);
    $transaction->allow_commit();

    if ($contextid > 0) {
        get_file_storage()->delete_area_files($contextid, 'mod_videoplayer', VIDEOPLAYER_LOCALPDF_FILEAREA);
    }

    (new bunny_asset_lifecycle())->after_delete($instance);

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
