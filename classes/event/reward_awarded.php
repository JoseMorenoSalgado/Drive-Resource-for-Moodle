<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\event;

/**
 * Event triggered when a Drive Resource reward is awarded.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reward_awarded extends \core\event\base {

    /**
     * Initialise event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'videoplayer_rewards';
    }

    /**
     * Return event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventrewardawarded', 'mod_videoplayer');
    }

    /**
     * Return event description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' earned reward '{$this->other['rewardkey']}' in Drive Resource with id '{$this->other['videoplayerid']}'.";
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
        return ['db' => 'videoplayer_rewards', 'restore' => 'videoplayer_reward'];
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
