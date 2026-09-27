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

namespace mod_videoplayer\local\provider;

use core\task\manager;
use mod_videoplayer\task\bind_bunny_asset;
use mod_videoplayer\task\release_bunny_asset;
use mod_videoplayer\task\rename_bunny_asset;
use stdClass;

/**
 * Coordinates Elearning Stream asset lifecycle tasks.
 *
 * Moodle persists activity state first. This service then translates lifecycle
 * transitions into idempotent asynchronous gateway operations, keeping provider
 * orchestration out of the activity callback layer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bunny_asset_lifecycle {
    /**
     * Queue the initial bind after a new activity has been persisted.
     *
     * @param stdClass $instance Persisted activity.
     * @return void
     */
    public function after_create(stdClass $instance): void {
        $this->queue_bind($instance);
    }

    /**
     * Reconcile provider references after an activity update.
     *
     * @param stdClass $oldinstance Previously persisted activity.
     * @param stdClass $newinstance Newly persisted activity.
     * @return void
     */
    public function after_update(stdClass $oldinstance, stdClass $newinstance): void {
        $oldasset = (string)($oldinstance->providerassetid ?? '');
        $newasset = (string)($newinstance->providerassetid ?? '');
        $oldisbunny = ($oldinstance->source ?? '') === bunny_stream::SOURCE;
        $newisbunny = ($newinstance->source ?? '') === bunny_stream::SOURCE;

        if ($oldisbunny && (!$newisbunny || $oldasset !== $newasset)) {
            $this->queue_release($oldinstance);
        }

        if (
            $newisbunny
            && (
                !$oldisbunny
                || $oldasset !== $newasset
                || (string)($oldinstance->provideruploadid ?? '') !== (string)($newinstance->provideruploadid ?? '')
            )
        ) {
            $this->queue_bind($newinstance);
        }

        if (
            $oldisbunny
            && $newisbunny
            && $oldasset === $newasset
            && (string)$oldinstance->name !== (string)$newinstance->name
        ) {
            $this->queue_rename($newinstance);
        }
    }

    /**
     * Queue provider release after the Moodle activity row is removed.
     *
     * @param stdClass $instance Deleted activity snapshot.
     * @return void
     */
    public function after_delete(stdClass $instance): void {
        $this->queue_release($instance);
    }

    /**
     * Queue server-to-server binding for one completed upload reservation.
     *
     * @param stdClass $instance Persisted activity.
     * @return void
     */
    private function queue_bind(stdClass $instance): void {
        if (
            ($instance->source ?? '') !== bunny_stream::SOURCE
            || !bunny_stream::is_valid_asset_id((string)($instance->providerassetid ?? ''))
            || !bunny_stream::is_valid_upload_id((string)($instance->provideruploadid ?? ''))
        ) {
            return;
        }

        $task = new bind_bunny_asset();
        $task->set_component('mod_videoplayer');
        $task->set_custom_data([
            'instanceid' => (int)$instance->id,
            'courseid' => (int)$instance->course,
            'videoid' => (string)$instance->providerassetid,
            'uploadid' => (string)$instance->provideruploadid,
        ]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue release through the WHMCS ownership and retention boundary.
     *
     * @param stdClass $instance Persisted activity snapshot.
     * @return void
     */
    private function queue_release(stdClass $instance): void {
        if (
            ($instance->source ?? '') !== bunny_stream::SOURCE
            || !bunny_stream::is_valid_asset_id((string)($instance->providerassetid ?? ''))
        ) {
            return;
        }

        $task = new release_bunny_asset();
        $task->set_component('mod_videoplayer');
        $task->set_custom_data([
            'instanceid' => (int)$instance->id,
            'videoid' => (string)$instance->providerassetid,
        ]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue a provider title synchronisation for the current owned asset.
     *
     * @param stdClass $instance Persisted activity.
     * @return void
     */
    private function queue_rename(stdClass $instance): void {
        if (
            ($instance->source ?? '') !== bunny_stream::SOURCE
            || !bunny_stream::is_valid_asset_id((string)($instance->providerassetid ?? ''))
        ) {
            return;
        }

        $task = new rename_bunny_asset();
        $task->set_component('mod_videoplayer');
        $task->set_custom_data([
            'instanceid' => (int)$instance->id,
            'videoid' => (string)$instance->providerassetid,
            'name' => (string)$instance->name,
        ]);
        manager::queue_adhoc_task($task, true);
    }
}
