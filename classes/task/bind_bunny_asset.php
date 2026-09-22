<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\task;

defined('MOODLE_INTERNAL') || die();

use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Bind an uploaded Bunny asset to a persisted Moodle activity in WHMCS.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bind_bunny_asset extends \core\task\adhoc_task {
    /**
     * Execute the bind request.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->instanceid) || empty($data->courseid) || empty($data->videoid) || empty($data->uploadid)) {
            return;
        }

        (new whmcs_gateway_client())->bind_asset(
            (string)$data->uploadid,
            (string)$data->videoid,
            (int)$data->instanceid,
            (int)$data->courseid
        );

        // The reservation ID is needed only until WHMCS confirms the durable
        // activity-to-asset reference. Remove it from Moodle afterwards.
        $instance = $DB->get_record(
            'videoplayer',
            ['id' => (int)$data->instanceid],
            'id, providerassetid, provideruploadid',
            IGNORE_MISSING
        );
        if ($instance
            && hash_equals((string)$instance->providerassetid, (string)$data->videoid)
            && hash_equals((string)$instance->provideruploadid, (string)$data->uploadid)) {
            $DB->set_field('videoplayer', 'provideruploadid', null, ['id' => (int)$instance->id]);
        }
    }
}
