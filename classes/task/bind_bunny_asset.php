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
    }
}
