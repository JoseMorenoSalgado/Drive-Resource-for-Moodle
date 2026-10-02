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
use mod_videoplayer\local\plugin_config;
use mod_videoplayer\local\provider\bunny_stream;
use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Activity settings form.
 */
class mod_videoplayer_mod_form extends moodleform_mod {
    /**
     * Define form fields.
     */
    public function definition() {
        global $OUTPUT, $PAGE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('resourcename', 'mod_videoplayer'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Production UI is Elearning Stream-first. Moodle-local PDFs remain
        // available only for backward compatibility. Historical Google-backed
        // records must be migrated to Elearning Stream before they can be saved.
        $currentsource = bunny_stream::SOURCE;
        $legacygooglesource = false;
        if (!empty($this->current) && !empty($this->current->source)) {
            $candidate = clean_param((string)$this->current->source, PARAM_ALPHANUMEXT);
            $legacygooglesource = $candidate === drive::SOURCE_GOOGLEDRIVE;
            if ($candidate === drive::SOURCE_LOCALPDF) {
                $currentsource = drive::SOURCE_LOCALPDF;
            }
        }

        $mform->addElement('hidden', 'source', $currentsource);
        $mform->setType('source', PARAM_ALPHANUMEXT);

        if ($legacygooglesource) {
            $mform->addElement(
                'static',
                'legacysourcemigration',
                '',
                get_string('legacygooglesourcenotsupported', 'mod_videoplayer')
            );
        }

        if ($currentsource === bunny_stream::SOURCE) {
            $streammodes = [
                'upload' => get_string('streammodeupload', 'mod_videoplayer'),
                'url' => get_string('streammodeurl', 'mod_videoplayer'),
            ];
            $mform->addElement(
                'select',
                'streaminputmode',
                get_string('streaminputmode', 'mod_videoplayer'),
                $streammodes
            );
            $mform->setType('streaminputmode', PARAM_ALPHA);
            $mform->setDefault('streaminputmode', 'upload');

            $mform->addElement('text', 'streamurl', get_string('streamurl', 'mod_videoplayer'), ['size' => 90]);
            $mform->setType('streamurl', PARAM_URL);
            $mform->addHelpButton('streamurl', 'streamurl', 'mod_videoplayer');
            $mform->hideIf('streamurl', 'streaminputmode', 'neq', 'url');

            $mform->addElement('hidden', 'providerassetid', '');
            $mform->setType('providerassetid', PARAM_ALPHANUMEXT);
            $mform->addElement('hidden', 'provideruploadid', '');
            $mform->setType('provideruploadid', PARAM_ALPHANUMEXT);
            $mform->addElement('hidden', 'providerfilesize', 0);
            $mform->setType('providerfilesize', PARAM_INT);
            $mform->addElement('hidden', 'providerstatus', '');
            $mform->setType('providerstatus', PARAM_ALPHANUMEXT);
            $mform->addElement('hidden', 'type', drive::TYPE_VIDEO);
            $mform->setType('type', PARAM_ALPHANUMEXT);

            $uploadhtml = html_writer::start_div('mod-videoplayer-bunny-upload', [
                'id' => 'mod-videoplayer-bunny-upload',
                'data-state' => 'idle',
            ]);

            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-header');
            $uploadhtml .= html_writer::div(
                $OUTPUT->pix_icon('i/upload', '', 'core', ['class' => 'mod-videoplayer-upload-header-icon']),
                'mod-videoplayer-upload-icon'
            );
            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-heading');
            $uploadhtml .= html_writer::tag(
                'strong',
                get_string('bunnyuploadlabel', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-title']
            );
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploadintro', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-intro']
            );
            $uploadhtml .= html_writer::end_div();
            $uploadhtml .= html_writer::end_div();

            $uploadhtml .= html_writer::empty_tag('input', [
                'type' => 'file',
                'id' => 'mod-videoplayer-bunny-file',
                'class' => 'mod-videoplayer-upload-input sr-only',
                'accept' => 'video/*,.mp4,.mov,.m4v,.webm,.mkv,.avi,.mpeg,.mpg',
            ]);

            $uploadhtml .= html_writer::start_tag('label', [
                'for' => 'mod-videoplayer-bunny-file',
                'id' => 'mod-videoplayer-bunny-dropzone',
                'class' => 'mod-videoplayer-upload-dropzone',
            ]);
            $uploadhtml .= html_writer::div(
                $OUTPUT->pix_icon('i/upload', '', 'core'),
                'mod-videoplayer-upload-drop-icon'
            );
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploaddroptitle', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-drop-title']
            );
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploaddrophelp', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-drop-help']
            );
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploadformats', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-formats']
            );
            $uploadhtml .= html_writer::end_tag('label');

            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-file', [
                'id' => 'mod-videoplayer-bunny-file-summary',
                'hidden' => 'hidden',
            ]);
            $uploadhtml .= html_writer::div(
                $OUTPUT->pix_icon('i/file', '', 'core'),
                'mod-videoplayer-upload-file-icon'
            );
            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-file-copy');
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploadselected', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-file-label']
            );
            $uploadhtml .= html_writer::tag('strong', '', [
                'id' => 'mod-videoplayer-bunny-file-name',
                'class' => 'mod-videoplayer-upload-file-name',
            ]);
            $uploadhtml .= html_writer::tag('span', '', [
                'id' => 'mod-videoplayer-bunny-file-size',
                'class' => 'mod-videoplayer-upload-file-size',
            ]);
            $uploadhtml .= html_writer::end_div();
            $uploadhtml .= html_writer::tag(
                'label',
                get_string('bunnyuploadchange', 'mod_videoplayer'),
                [
                    'for' => 'mod-videoplayer-bunny-file',
                    'class' => 'btn btn-outline-secondary btn-sm mod-videoplayer-upload-change',
                ]
            );
            $uploadhtml .= html_writer::end_div();

            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-progress', [
                'id' => 'mod-videoplayer-bunny-progress-section',
                'hidden' => 'hidden',
            ]);
            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-progress-meta');
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploadprogress', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-progress-label']
            );
            $uploadhtml .= html_writer::tag('strong', '0%', [
                'id' => 'mod-videoplayer-bunny-progress-percent',
            ]);
            $uploadhtml .= html_writer::end_div();
            $uploadhtml .= html_writer::div('', 'progress mod-videoplayer-upload-progress-track', [
                'id' => 'mod-videoplayer-bunny-progress-wrap',
                'hidden' => 'hidden',
            ]);
            $uploadhtml .= html_writer::tag('span', '', [
                'id' => 'mod-videoplayer-bunny-progress-bytes',
                'class' => 'mod-videoplayer-upload-progress-bytes',
            ]);
            $uploadhtml .= html_writer::end_div();

            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-feedback');
            $uploadhtml .= html_writer::div('', 'mod-videoplayer-upload-status', [
                'id' => 'mod-videoplayer-bunny-status',
                'role' => 'status',
                'aria-live' => 'polite',
            ]);
            $uploadhtml .= html_writer::div('', 'mod-videoplayer-upload-quota', [
                'id' => 'mod-videoplayer-bunny-quota',
                'aria-live' => 'polite',
                'hidden' => 'hidden',
            ]);
            $uploadhtml .= html_writer::end_div();

            $uploadhtml .= html_writer::start_div('mod-videoplayer-upload-actions');
            $uploadhtml .= html_writer::tag(
                'button',
                get_string('bunnyuploadbutton', 'mod_videoplayer'),
                [
                    'type' => 'button',
                    'id' => 'mod-videoplayer-bunny-start',
                    'class' => 'btn btn-primary mod-videoplayer-upload-start',
                    'disabled' => 'disabled',
                ]
            );
            $uploadhtml .= html_writer::tag(
                'span',
                get_string('bunnyuploadsecure', 'mod_videoplayer'),
                ['class' => 'mod-videoplayer-upload-secure']
            );
            $uploadhtml .= html_writer::end_div();

            $uploadhtml .= html_writer::end_div();

            $mform->addElement(
                'static',
                'bunnyuploadpanel',
                '',
                $uploadhtml
            );
            $mform->hideIf('bunnyuploadpanel', 'streaminputmode', 'neq', 'upload');
        } else {
            // Backward compatibility for existing Moodle-local protected PDFs.
            $filemanageroptions = $this->get_localpdf_filemanager_options();
            $mform->addElement(
                'filemanager',
                'localpdffile',
                get_string('localpdffile', 'mod_videoplayer'),
                null,
                $filemanageroptions
            );
            $mform->addHelpButton('localpdffile', 'localpdffile', 'mod_videoplayer');
            $mform->addElement('hidden', 'type', 'pdf');
            $mform->setType('type', PARAM_ALPHANUMEXT);
        }

        $mform->addElement('advcheckbox', 'disablecontextmenu', get_string('disablecontextmenu', 'mod_videoplayer'));
        $mform->setDefault('disablecontextmenu', 1);

        $mform->addElement('advcheckbox', 'enablewatermark', get_string('enablewatermark', 'mod_videoplayer'));
        $mform->setDefault('enablewatermark', 1);

        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();

        if ($currentsource === bunny_stream::SOURCE) {
            $courseid = (int)$this->get_course();
            $coursemodule = $this->get_coursemodule();

            $PAGE->requires->js_call_amd('mod_videoplayer/bunnyupload', 'init', [[
                'courseid' => $courseid,
                'cmid' => $coursemodule ? (int)$coursemodule->id : 0,
                'strings' => [
                    'ready' => get_string('bunnyuploadready', 'mod_videoplayer'),
                    'authorizing' => get_string('bunnyuploadauthorizing', 'mod_videoplayer'),
                    'uploading' => get_string('bunnyuploading', 'mod_videoplayer'),
                    'processing' => get_string('bunnyuploadprocessing', 'mod_videoplayer'),
                    'retrying' => get_string('bunnyuploadretrying', 'mod_videoplayer'),
                    'reauthorizing' => get_string('bunnyuploadreauthorizing', 'mod_videoplayer'),
                    'failed' => get_string('bunnyuploadfailed', 'mod_videoplayer'),
                    'invalidtype' => get_string('bunnyuploadinvalidtype', 'mod_videoplayer'),
                    'existing' => get_string('bunnyuploadexisting', 'mod_videoplayer'),
                    'quota' => get_string('bunnyuploadquota', 'mod_videoplayer'),
                    'overage' => get_string('bunnyuploadoverage', 'mod_videoplayer'),
                ],
            ]]);
        }
    }

    /**
     * Prepare draft file area before editing the form.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues): void {
        if (
            $this->current
            && !empty($this->current->id)
            && ($this->current->source ?? '') === drive::SOURCE_LOCALPDF
        ) {
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

        $enabledname = 'completionprogressenabled' . $this->get_suffix();
        $percentname = 'completionpercentage' . $this->get_suffix();
        $defaultvalues[$enabledname] = !empty(
            $defaultvalues[$enabledname] ?? $defaultvalues['completionprogressenabled'] ?? 1
        ) ? 1 : 0;

        $percentage = (int)(
            $defaultvalues[$percentname]
            ?? $defaultvalues['completionpercentage']
            ?? plugin_config::default_completion_percentage()
        );
        $defaultvalues[$percentname] = $percentage > 0
            ? max(1, min(100, $percentage))
            : plugin_config::default_completion_percentage();
    }

    /**
     * Normalise custom completion controls after Moodle has processed the form.
     *
     * @param stdClass $data Submitted form data.
     * @return void
     */
    public function data_postprocessing($data): void {
        parent::data_postprocessing($data);

        if (empty($data->completionunlocked)) {
            return;
        }

        $completionname = 'completion' . $this->get_suffix();
        $enabledname = 'completionprogressenabled' . $this->get_suffix();
        $autocompletion = !empty($data->{$completionname})
            && (int)$data->{$completionname} === COMPLETION_TRACKING_AUTOMATIC;

        if (!$autocompletion || empty($data->{$enabledname})) {
            $data->{$enabledname} = 0;
        }
    }

    /**
     * Add Drive Resource custom completion controls.
     *
     * @category completion
     * @return array List of top-level form element names.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $enabledname = 'completionprogressenabled' . $this->get_suffix();
        $percentname = 'completionpercentage' . $this->get_suffix();
        $groupname = 'completionprogressgroup' . $this->get_suffix();

        $group = [
            $mform->createElement(
                'checkbox',
                $enabledname,
                '',
                get_string('completionprogressenabled', 'mod_videoplayer')
            ),
            $mform->createElement(
                'text',
                $percentname,
                '',
                ['size' => 4]
            ),
            $mform->createElement(
                'static',
                'completionprogresssuffix' . $this->get_suffix(),
                '',
                '%'
            ),
        ];

        $mform->addGroup(
            $group,
            $groupname,
            get_string('completionprogressgroup', 'mod_videoplayer'),
            [' '],
            false
        );
        $mform->addHelpButton(
            $groupname,
            'completionprogressgroup',
            'mod_videoplayer'
        );
        $mform->setType($percentname, PARAM_INT);
        $mform->setDefault($enabledname, 1);
        $mform->setDefault($percentname, plugin_config::default_completion_percentage());
        $mform->disabledIf($percentname, $enabledname, 'notchecked');

        return [$groupname];
    }

    /**
     * Whether the custom progress completion rule is enabled.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        $enabledname = 'completionprogressenabled' . $this->get_suffix();
        $percentname = 'completionpercentage' . $this->get_suffix();

        return !empty($data[$enabledname])
            && !empty($data[$percentname])
            && (int)$data[$percentname] >= 1
            && (int)$data[$percentname] <= 100;
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
        $source = $data['source'] ?? bunny_stream::SOURCE;

        if ($source === bunny_stream::SOURCE) {
            $missingconfig = whmcs_gateway_client::missing_configuration();
            if ($missingconfig !== []) {
                $errors['streaminputmode'] = get_string(
                    'streamgatewayconfigurationrequired',
                    'mod_videoplayer',
                    whmcs_gateway_client::missing_configuration_labels()
                );
            } else {
                $streammode = clean_param((string)($data['streaminputmode'] ?? 'upload'), PARAM_ALPHA);

                if ($streammode === 'url') {
                    $streamurl = trim((string)($data['streamurl'] ?? ''));
                    if (bunny_stream::extract_candidate_asset_id_from_url($streamurl) === null) {
                        $errors['streamurl'] = get_string('invalidstreamurl', 'mod_videoplayer');
                    }
                } else {
                    $assetid = trim((string)($data['providerassetid'] ?? ''));
                    $uploadid = trim((string)($data['provideruploadid'] ?? ''));
                    $status = bunny_stream::normalise_status((string)($data['providerstatus'] ?? ''));
                    $existingassetid = !empty($this->current)
                        ? trim((string)($this->current->providerassetid ?? ''))
                        : '';
                    $isexistingboundasset = bunny_stream::is_valid_asset_id($existingassetid)
                        && hash_equals($existingassetid, $assetid);

                    if (
                        !bunny_stream::is_valid_asset_id($assetid)
                        || (!$isexistingboundasset && !bunny_stream::is_valid_upload_id($uploadid))
                        || $status === ''
                    ) {
                        $errors['bunnyuploadpanel'] = get_string('bunnyuploadrequired', 'mod_videoplayer');
                    }
                }
            }
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

        $percentname = 'completionpercentage' . $this->get_suffix();
        if (
            isset($data[$percentname])
            && ((int)$data[$percentname] < 1 || (int)$data[$percentname] > 100)
        ) {
            $errors[$percentname] = get_string('invalidcompletionpercentage', 'mod_videoplayer');
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

        $course = get_course($this->get_course());

        return [
            'subdirs' => 0,
            'maxbytes' => get_max_upload_file_size($CFG->maxbytes, (int)($course->maxbytes ?? 0)),
            'maxfiles' => 1,
            'accepted_types' => ['.pdf'],
        ];
    }
}
