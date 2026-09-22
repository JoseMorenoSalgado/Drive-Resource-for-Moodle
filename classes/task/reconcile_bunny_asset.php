<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\task;

defined('MOODLE_INTERNAL') || die();

use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Reconcile a restored Bunny asset with the WHMCS service tenant.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_bunny_asset extends \core\task\adhoc_task {
    /**
     * Execute the reconciliation request.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->instanceid) || empty($data->courseid) || empty($data->videoid)) {
            return;
        }

        (new whmcs_gateway_client())->reconcile_asset(
            (string)$data->videoid,
            (int)$data->instanceid,
            (int)$data->courseid
        );
    }
}
