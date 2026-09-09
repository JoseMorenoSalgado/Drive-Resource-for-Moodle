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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Supported Moodle platform compatibility contract tests.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[CoversNothing]
final class platform_compatibility_test extends \advanced_testcase {
    /**
     * The plugin metadata must explicitly support Moodle 4.5 through 5.2.
     */
    public function test_plugin_metadata_supports_declared_range(): void {
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('mod_videoplayer');

        $this->assertNotNull($plugininfo);
        $this->assertSame('mod_videoplayer', $plugininfo->component);
        $this->assertSame(2024100700, (int) $plugininfo->versionrequires);
        $this->assertSame([405, 502], $plugininfo->pluginsupported);
    }

    /**
     * Core APIs used by Drive Resource must exist on every supported branch.
     */
    public function test_required_moodle_apis_exist(): void {
        $this->assertTrue(class_exists(\core_external\external_api::class));
        $this->assertTrue(class_exists(\core\task\adhoc_task::class));
        $this->assertTrue(class_exists(\core\task\scheduled_task::class));
        $this->assertTrue(class_exists(\core_privacy\local\request\writer::class));
        $this->assertTrue(method_exists(\core\task\manager::class, 'queue_adhoc_task'));
        $this->assertTrue(method_exists(\core\session\manager::class, 'write_close'));
    }

    /**
     * Drive Resource intentionally requires PHP 8.2+ across all supported Moodle branches.
     */
    public function test_runtime_meets_plugin_php_requirement(): void {
        $this->assertGreaterThanOrEqual(80200, PHP_VERSION_ID);
    }
}
