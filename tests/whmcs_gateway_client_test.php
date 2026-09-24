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

namespace mod_videoplayer;

use mod_videoplayer\local\whmcs_gateway_client;

/**
 * Tests for Moodle-side Elearning Stream gateway configuration.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_videoplayer\local\whmcs_gateway_client
 */
final class whmcs_gateway_client_test extends \advanced_testcase {
    /**
     * Missing required settings are reported explicitly.
     *
     * @return void
     */
    public function test_missing_configuration_reports_required_settings(): void {
        $this->resetAfterTest();

        unset_config('whmcsgatewayurl', 'mod_videoplayer');
        unset_config('whmcsserviceid', 'mod_videoplayer');
        unset_config('whmcsservicetoken', 'mod_videoplayer');

        $this->assertSame(
            [
                'setting_whmcsgatewayurl',
                'setting_whmcsserviceid',
                'setting_whmcsservicetoken',
            ],
            whmcs_gateway_client::missing_configuration()
        );
        $this->assertFalse(whmcs_gateway_client::is_configured());
    }

    /**
     * Complete Moodle-side gateway configuration is recognised.
     *
     * @return void
     */
    public function test_complete_configuration_is_recognised(): void {
        $this->resetAfterTest();

        set_config(
            'whmcsgatewayurl',
            'https://billing.example.com/modules/addons/driveresource_gateway',
            'mod_videoplayer'
        );
        set_config('whmcsserviceid', 123, 'mod_videoplayer');
        set_config(
            'whmcsservicetoken',
            str_repeat('a', 64),
            'mod_videoplayer'
        );

        $this->assertSame([], whmcs_gateway_client::missing_configuration());
        $this->assertTrue(whmcs_gateway_client::is_configured());
    }

    /**
     * Generic WHMCS passwords must never be accepted as gateway tokens.
     *
     * @return void
     */
    public function test_generic_password_is_rejected_as_service_token(): void {
        $this->resetAfterTest();

        set_config(
            'whmcsgatewayurl',
            'https://billing.example.com/modules/addons/driveresource_gateway',
            'mod_videoplayer'
        );
        set_config('whmcsserviceid', 123, 'mod_videoplayer');
        set_config('whmcsservicetoken', 'ThisIsAGenericWHMCSPassword123456', 'mod_videoplayer');

        $this->assertSame(
            ['setting_whmcsservicetoken'],
            whmcs_gateway_client::missing_configuration()
        );
        $this->assertFalse(whmcs_gateway_client::is_configured());
    }
}
