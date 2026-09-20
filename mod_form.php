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
 * Form definition for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_videoplayer\local\drive;

/**
 * Activity settings form.
 */
class mod_videoplayer_mod_form extends moodleform_mod {
    /**
     * Define form fields.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('resourcename', 'mod_videoplayer'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $sources = [
            drive::SOURCE_GOOGLEDRIVE => get_string('sourcegoogledrive', 'mod_videoplayer'),
            drive::SOURCE_LOCALPDF => get_string('sourcelocalpdf', 'mod_videoplayer'),
        ];
        $mform->addElement('select', 'source', get_string('resourcesource', 'mod_videoplayer'), $sources);
        $mform->setDefault('source', drive::SOURCE_GOOGLEDRIVE);

        $mform->addElement('text', 'videourl', get_string('driveurl', 'mod_videoplayer'), ['size' => 90]);
        $mform->setType('videourl', PARAM_URL);
        $mform->addHelpButton('videourl', 'driveurl', 'mod_videoplayer');
        $mform->hideIf('videourl', 'source', 'eq', drive::SOURCE_LOCALPDF);
        $mform->disabledIf('videourl', 'source', 'eq', drive::SOURCE_LOCALPDF);

        $filemanageroptions = $this->get_localpdf_filemanager_options();
        $mform->addElement('filemanager', 'localpdffile', get_string('localpdffile', 'mod_videoplayer'), null, $filemanageroptions);
        $mform->addHelpButton('localpdffile', 'localpdffile', 'mod_videoplayer');
        $mform->hideIf('localpdffile', 'source', 'eq', drive::SOURCE_GOOGLEDRIVE);

        $types = [
            drive::TYPE_AUTO => get_string('typeauto', 'mod_videoplayer'),
        ];
        foreach (drive::RESOURCE_TYPES as $resourcetype) {
            $types[$resourcetype] = get_string('type' . $resourcetype, 'mod_videoplayer');
        }
        $mform->addElement('select', 'type', get_string('resourcetype', 'mod_videoplayer'), $types);
        $mform->setDefault('type', drive::TYPE_AUTO);
        $mform->disabledIf('type', 'source', 'eq', drive::SOURCE_LOCALPDF);

        $mform->addElement('advcheckbox', 'disablecontextmenu', get_string('disablecontextmenu', 'mod_videoplayer'));
        $mform->setDefault('disablecontextmenu', 1);

        $mform->addElement('advcheckbox', 'enablewatermark', get_string('enablewatermark', 'mod_videoplayer'));
        $mform->setDefault('enablewatermark', 1);

        $mform->addElement('text', 'completionpercentage', get_string('completionpercentage', 'mod_videoplayer'), ['size' => 5]);
        $mform->setType('completionpercentage', PARAM_INT);
        $defaultcompletion = (int)get_config('mod_videoplayer', 'defaultcompletionpercentage');
        $defaultcompletion = $defaultcompletion > 0 ? max(1, min(100, $defaultcompletion)) : 80;
        $mform->setDefault('completionpercentage', $defaultcompletion);
        $mform->addRule('completionpercentage', null, 'numeric', null, 'client');
        $mform->addHelpButton('completionpercentage', 'completionpercentage', 'mod_videoplayer');

        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepare draft file area before editing the form.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues): void {
        if ($this->current && !empty($this->current->id)) {
            $draftitemid = file_get_submitted_draft_itemid('localpdffile');
            file_prepare_draft_area(
                $draftitemid,
                $this->context->id,
                'mod_videoplayer',
                drive::SOURCE_LOCALPDF,
                0,
                $this->get_localpdf_filemanager_options()
            );
            $defaultvalues['localpdffile'] = $draftitemid;
        }
    }

    /**
     * Validate form data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        $source = $data['source'] ?? drive::SOURCE_GOOGLEDRIVE;

        if ($source === drive::SOURCE_GOOGLEDRIVE && (empty($data['videourl']) || !drive::is_supported_url($data['videourl']))) {
            $errors['videourl'] = get_string('invaliddriveurl', 'mod_videoplayer');
        }

        if ($source === drive::SOURCE_LOCALPDF) {
            $draftitemid = (int)($data['localpdffile'] ?? 0);
            $fs = get_file_storage();
            $context = context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($context->id, 'user', 'draft', $draftitemid, 'id', false);
            if (empty($draftfiles)) {
                $errors['localpdffile'] = get_string('requiredlocalpdf', 'mod_videoplayer');
            }
            foreach ($draftfiles as $file) {
                if ($file->get_mimetype() !== 'application/pdf') {
                    $errors['localpdffile'] = get_string('invalidlocalpdf', 'mod_videoplayer');
                    break;
                }
            }
        }

        if (isset($data['completionpercentage']) && ($data['completionpercentage'] < 1 || $data['completionpercentage'] > 100)) {
            $errors['completionpercentage'] = get_string('invalidcompletionpercentage', 'mod_videoplayer');
        }

        return $errors;
    }

    /**
     * Return filemanager options for the local protected PDF area.
     *
     * @return array
     */
    private function get_localpdf_filemanager_options(): array {
        global $CFG;

        return [
            'subdirs' => 0,
            'maxbytes' => get_max_upload_file_size($CFG->maxbytes, $this->course->maxbytes ?? 0),
            'maxfiles' => 1,
            'accepted_types' => ['.pdf'],
        ];
    }
}
