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

declare(strict_types=1);

namespace mod_videoplayer;

use cm_info;
use coding_exception;
use mod_videoplayer\completion\custom_completion;
use moodle_exception;

/**
 * Tests for Drive Resource Moodle custom completion.
 *
 * @package    mod_videoplayer
 * @category   test
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \mod_videoplayer\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /**
     * Completion states must reflect the persisted progress percentage.
     *
     * @param string $rule Rule name.
     * @param int $threshold Configured threshold.
     * @param float|false $percentage Saved learner percentage.
     * @param int|null $expected Expected state.
     * @param string|null $exception Expected exception class.
     * @return void
     * @dataProvider state_provider
     * @covers \\mod_videoplayer\\completion\\custom_completion::get_state
     */
    public function test_get_state(
        string $rule,
        int $threshold,
        float|false $percentage,
        ?int $expected,
        ?string $exception
    ): void {
        global $DB;

        if ($exception !== null) {
            $this->expectException($exception);
        }

        $customdata = [
            'customcompletionrules' => [
                custom_completion::RULE_PROGRESS => $threshold,
            ],
        ];

        $cm = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_custom_data', '__get'])
            ->getMock();

        $cm->method('get_custom_data')->willReturn($customdata);
        $cm->method('__get')->willReturnCallback(static function (string $name) use ($customdata) {
            return match ($name) {
                'instance' => 42,
                'customdata' => $customdata,
                default => null,
            };
        });

        $DB = $this->createMock(get_class($DB));
        $DB->expects($exception === null ? $this->once() : $this->never())
            ->method('get_field')
            ->willReturn($percentage);

        $completion = new custom_completion($cm, 7);
        $this->assertSame($expected, $completion->get_state($rule));
    }

    /**
     * Data provider for completion state tests.
     *
     * @return array
     */
    public static function state_provider(): array {
        return [
            'undefined rule' => [
                'unknownrule',
                80,
                80.0,
                null,
                coding_exception::class,
            ],
            'disabled rule' => [
                custom_completion::RULE_PROGRESS,
                0,
                false,
                null,
                moodle_exception::class,
            ],
            'no progress record' => [
                custom_completion::RULE_PROGRESS,
                80,
                false,
                \COMPLETION_INCOMPLETE,
                null,
            ],
            'below threshold' => [
                custom_completion::RULE_PROGRESS,
                80,
                79.99,
                \COMPLETION_INCOMPLETE,
                null,
            ],
            'at threshold' => [
                custom_completion::RULE_PROGRESS,
                80,
                80.0,
                \COMPLETION_COMPLETE,
                null,
            ],
            'above threshold' => [
                custom_completion::RULE_PROGRESS,
                80,
                100.0,
                \COMPLETION_COMPLETE,
                null,
            ],
        ];
    }

    /**
     * The plugin must define exactly one custom completion rule.
     *
     * @return void
     * @covers \\mod_videoplayer\\completion\\custom_completion::get_defined_custom_rules
     */
    public function test_defined_rules(): void {
        $this->assertSame(
            [custom_completion::RULE_PROGRESS],
            custom_completion::get_defined_custom_rules()
        );
    }

    /**
     * The custom rule description must include the configured threshold.
     *
     * @return void
     * @covers \\mod_videoplayer\\completion\\custom_completion::get_custom_rule_descriptions
     */
    public function test_rule_description_uses_threshold(): void {
        $customdata = [
            'customcompletionrules' => [
                custom_completion::RULE_PROGRESS => 75,
            ],
        ];

        $cm = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_custom_data'])
            ->getMock();
        $cm->method('get_custom_data')->willReturn($customdata);

        $completion = new custom_completion($cm, 7);
        $descriptions = $completion->get_custom_rule_descriptions();

        $this->assertArrayHasKey(custom_completion::RULE_PROGRESS, $descriptions);
        $this->assertStringContainsString('75', $descriptions[custom_completion::RULE_PROGRESS]);
    }

    /**
     * Custom completion should appear after view completion.
     *
     * @return void
     * @covers \\mod_videoplayer\\completion\\custom_completion::get_sort_order
     */
    public function test_sort_order(): void {
        $cm = $this->getMockBuilder(cm_info::class)
            ->disableOriginalConstructor()
            ->getMock();

        $completion = new custom_completion($cm, 7);
        $this->assertSame(
            ['completionview', custom_completion::RULE_PROGRESS],
            $completion->get_sort_order()
        );
    }
}
