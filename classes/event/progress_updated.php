<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\event;

/**
 * Event triggered when Drive Resource progress is updated.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_updated extends \core\event\base {

    /**
     * Initialise event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'videoplayer_views';
    }

    /**
     * Return event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventprogressupdated', 'mod_videoplayer');
    }

    /**
     * Return event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' updated progress for Drive Resource with id '{$this->other['videoplayerid']}'.";
    }

    /**
     * Return related activity URL.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/videoplayer/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Return object mapping information.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'videoplayer_views', 'restore' => 'videoplayer_view'];
    }

    /**
     * Return other mapping information.
     *
     * @return array
     */
    public static function get_other_mapping(): array {
        return [
            'videoplayerid' => ['db' => 'videoplayer', 'restore' => 'videoplayer'],
        ];
    }
}
