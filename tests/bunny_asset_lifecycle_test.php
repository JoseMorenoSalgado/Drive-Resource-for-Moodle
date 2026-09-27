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
 * Tests for Elearning Stream asset lifecycle orchestration.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoplayer;

use mod_videoplayer\local\drive;
use mod_videoplayer\local\provider\bunny_asset_lifecycle;
use mod_videoplayer\local\provider\bunny_stream;

/**
 * Elearning Stream provider lifecycle tests.
 *
 * @covers \mod_videoplayer\local\provider\bunny_asset_lifecycle
 */
final class bunny_asset_lifecycle_test extends \advanced_testcase {
    /**
     * New managed videos queue one binding task.
     */
    public function test_after_create_queues_bind(): void {
        $this->resetAfterTest();

        (new bunny_asset_lifecycle())->after_create($this->bunny_instance());

        $task = $this->single_queued_task();
        $this->assertStringEndsWith('\\bind_bunny_asset', $task->classname);
        $data = json_decode($task->customdata, true);
        $this->assertSame(42, $data['instanceid']);
        $this->assertSame(7, $data['courseid']);
    }

    /**
     * Replacing one managed asset releases the old one and binds the new one.
     */
    public function test_after_update_replaces_asset_with_release_and_bind(): void {
        $this->resetAfterTest();

        $oldinstance = $this->bunny_instance();
        $newinstance = clone $oldinstance;
        $newinstance->providerassetid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $newinstance->provideruploadid = 'abcdefabcdefabcdefabcdefabcdefab';

        (new bunny_asset_lifecycle())->after_update($oldinstance, $newinstance);

        $classnames = $this->queued_classnames();
        sort($classnames);
        $this->assertCount(2, $classnames);
        $this->assertStringEndsWith('\\bind_bunny_asset', $classnames[0]);
        $this->assertStringEndsWith('\\release_bunny_asset', $classnames[1]);
    }

    /**
     * Renaming an activity with the same asset queues only title sync.
     */
    public function test_after_update_same_asset_queues_rename_only(): void {
        $this->resetAfterTest();

        $oldinstance = $this->bunny_instance();
        $newinstance = clone $oldinstance;
        $newinstance->name = 'Updated activity title';

        (new bunny_asset_lifecycle())->after_update($oldinstance, $newinstance);

        $task = $this->single_queued_task();
        $this->assertStringEndsWith('\\rename_bunny_asset', $task->classname);
        $data = json_decode($task->customdata, true);
        $this->assertSame('Updated activity title', $data['name']);
    }

    /**
     * Switching away from Elearning Stream releases the previous asset.
     */
    public function test_after_update_switching_source_queues_release(): void {
        $this->resetAfterTest();

        $oldinstance = $this->bunny_instance();
        $newinstance = clone $oldinstance;
        $newinstance->source = drive::SOURCE_GOOGLEDRIVE;
        $newinstance->providerassetid = null;
        $newinstance->provideruploadid = null;

        (new bunny_asset_lifecycle())->after_update($oldinstance, $newinstance);

        $task = $this->single_queued_task();
        $this->assertStringEndsWith('\\release_bunny_asset', $task->classname);
    }

    /**
     * Deleted managed videos queue one release task.
     */
    public function test_after_delete_queues_release(): void {
        $this->resetAfterTest();

        (new bunny_asset_lifecycle())->after_delete($this->bunny_instance());

        $task = $this->single_queued_task();
        $this->assertStringEndsWith('\\release_bunny_asset', $task->classname);
        $data = json_decode($task->customdata, true);
        $this->assertSame('0123456789abcdef0123456789abcdef', $data['uploadid']);
    }

    /**
     * Invalid or non-managed resources do not queue provider work.
     */
    public function test_invalid_resource_does_not_queue_tasks(): void {
        global $DB;

        $this->resetAfterTest();

        $instance = $this->bunny_instance();
        $instance->source = drive::SOURCE_GOOGLEDRIVE;

        $service = new bunny_asset_lifecycle();
        $service->after_create($instance);
        $service->after_delete($instance);

        $this->assertFalse($DB->record_exists('task_adhoc', ['component' => 'mod_videoplayer']));
    }

    /**
     * Return the only queued plugin adhoc task.
     *
     * @return \stdClass
     */
    private function single_queued_task(): \stdClass {
        global $DB;

        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_videoplayer']);
        $this->assertCount(1, $tasks);

        return reset($tasks);
    }

    /**
     * Return queued task class names.
     *
     * @return string[]
     */
    private function queued_classnames(): array {
        global $DB;

        $tasks = $DB->get_records('task_adhoc', ['component' => 'mod_videoplayer']);

        return array_values(array_map(
            static fn(\stdClass $task): string => $task->classname,
            $tasks
        ));
    }

    /**
     * Representative persisted Elearning Stream activity.
     *
     * @return \stdClass
     */
    private function bunny_instance(): \stdClass {
        return (object)[
            'id' => 42,
            'course' => 7,
            'name' => 'Managed training video',
            'source' => bunny_stream::SOURCE,
            'providerassetid' => 'd4b3b9ce-531f-4f7a-a8db-847f47a889e9',
            'provideruploadid' => '0123456789abcdef0123456789abcdef',
        ];
    }
}
