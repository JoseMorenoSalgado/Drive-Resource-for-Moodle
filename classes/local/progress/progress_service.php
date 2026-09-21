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

namespace mod_videoplayer\local\progress;

use mod_videoplayer\local\gamification\reward_service;
use mod_videoplayer\local\plugin_config;

/**
 * Drive Resource progress, resume and completion service.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_service {
    /** @var int Seconds to wait for a concurrent progress write to finish. */
    private const LOCK_TIMEOUT_SECONDS = 10;

    /** @var int Maximum accepted active time on the first progress write. */
    private const INITIAL_TIMESPENT_LIMIT = 35;

    /** @var int Grace seconds added to elapsed server wall time. */
    private const TIMESPENT_GRACE_SECONDS = 5;

    /**
     * Save progress and return the persisted state.
     *
     * @param object $cm Course module record/info.
     * @param object $course Course record.
     * @param object $instance Drive Resource instance.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @param array $input Validated external input.
     * @return array
     */
    public function save_progress(
        object $cm,
        object $course,
        object $instance,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_videoplayer');
        $lockkey = 'progress_' . (int)$instance->id . '_' . $userid;
        $lock = $lockfactory->get_lock($lockkey, self::LOCK_TIMEOUT_SECONDS);

        if (!$lock) {
            throw new \moodle_exception('progresslocktimeout', 'mod_videoplayer');
        }

        try {
            return $this->save_progress_locked($cm, $course, $instance, $context, $userid, $input);
        } finally {
            $lock->release();
        }
    }

    /**
     * Save progress after acquiring the per-user/resource lock.
     *
     * @param object $cm Course module record/info.
     * @param object $course Course record.
     * @param object $instance Drive Resource instance.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @param array $input Validated external input.
     * @return array
     */
    private function save_progress_locked(
        object $cm,
        object $course,
        object $instance,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        global $DB;

        $now = time();
        $lastpage = max(0, (int)($input['lastpage'] ?? 0));
        $totalpages = max(0, (int)($input['totalpages'] ?? 0));
        $clienttimespent = max(0, (int)($input['timespent'] ?? 0));
        $progress = max(0.0, (float)($input['progress'] ?? 0));
        $lastposition = max(0.0, (float)($input['lastposition'] ?? 0));
        $duration = max(0.0, (float)($input['duration'] ?? 0));
        $incomingranges = (string)($input['watchedranges'] ?? '');
        $conditions = [
            'videoplayerid' => (int)$instance->id,
            'userid' => $userid,
        ];

        $transaction = $DB->start_delegated_transaction();
        $record = $DB->get_record('videoplayer_views', $conditions);
        $wascompleted = $record ? !empty($record->completed) : false;
        $storedranges = (string)($record->watchedranges ?? '');
        $haswatchedranges = $incomingranges !== '' || $storedranges !== '';
        $watchedranges = watched_range_set::merge($storedranges, $incomingranges, $duration);
        $watchedseconds = watched_range_set::seconds($watchedranges, $duration);
        $timespent = $this->bounded_timespent($clienttimespent, $record ?: null, $now);
        $requiredseconds = plugin_config::required_seconds();
        $derivedpercentage = $this->derive_percentage(
            $lastpage,
            $totalpages,
            $lastposition,
            $duration,
            $watchedseconds,
            $haswatchedranges,
            $timespent,
            $requiredseconds
        );
        $requiredpercentage = max(1, min(100, (int)($instance->completionpercentage ?? 80)));
        $completed = $derivedpercentage >= $requiredpercentage;

        if ($duration > 0 && $haswatchedranges) {
            $progress = $watchedseconds;
        } else if ($totalpages === 0 && $duration <= 0) {
            $progress = min($progress, (float)$timespent);
        }

        if ($record) {
            $record->progress = max((float)$record->progress, $progress);
            $record->completionpercentage = max((float)$record->completionpercentage, $derivedpercentage);
            $record->completed = (!empty($record->completed) || $completed) ? 1 : 0;
            if ($lastpage > 0) {
                $record->lastpage = $lastpage;
            }
            $record->totalpages = max((int)($record->totalpages ?? 0), $totalpages);
            $record->timespent = max((int)($record->timespent ?? 0), $timespent);
            if ($lastposition >= 0 && $duration > 0) {
                $record->lastposition = min($lastposition, $duration);
                $record->duration = max((float)($record->duration ?? 0), $duration);
            }
            $record->watchedranges = $watchedranges;
            $record->timemodified = $now;
            $DB->update_record('videoplayer_views', $record);
        } else {
            $record = (object)[
                'videoplayerid' => (int)$instance->id,
                'userid' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
                'progress' => $progress,
                'completed' => $completed ? 1 : 0,
                'completionpercentage' => $derivedpercentage,
                'lastpage' => $lastpage,
                'totalpages' => $totalpages,
                'timespent' => $timespent,
                'lastposition' => $duration > 0 ? min($lastposition, $duration) : 0,
                'duration' => $duration,
                'watchedranges' => $watchedranges,
                'points' => 0,
            ];
            $record->id = $DB->insert_record('videoplayer_views', $record);
        }

        $rewarddata = [
            'rewards' => [],
            'totalpoints' => (int)($record->points ?? 0),
        ];
        if (!empty($instance->enablegamification)) {
            $rewarddata = (new reward_service())->award_rewards($instance, $record, $userid, $context);
            $record->points = (int)$rewarddata['totalpoints'];
            $DB->set_field('videoplayer_views', 'points', $record->points, ['id' => $record->id]);
        }

        $transaction->allow_commit();

        \mod_videoplayer\event\progress_updated::create([
            'objectid' => $record->id,
            'context' => $context,
            'userid' => $userid,
            'other' => [
                'videoplayerid' => (int)$instance->id,
                'completionpercentage' => (float)$record->completionpercentage,
                'lastpage' => (int)($record->lastpage ?? 0),
                'totalpages' => (int)($record->totalpages ?? 0),
            ],
        ])->trigger();

        if (!empty($record->completed) && !empty($instance->completionprogressenabled)) {
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) === COMPLETION_TRACKING_AUTOMATIC) {
                // Progress is monotonic, so this change can only make the
                // custom rule complete, never revert an existing completion.
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        }

        if (!empty($record->completed) && !$wascompleted) {
            \mod_videoplayer\event\resource_completed::create([
                'objectid' => $record->id,
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'videoplayerid' => (int)$instance->id,
                    'completionpercentage' => (float)$record->completionpercentage,
                ],
            ])->trigger();
        }

        return $this->response($record, $rewarddata['rewards']);
    }

    /**
     * Derive a server-side completion percentage from resource-specific
     * position fields. Generic resources use server-bounded active time.
     *
     * @param int $lastpage
     * @param int $totalpages
     * @param float $lastposition
     * @param float $duration
     * @param float $watchedseconds Unique media seconds actually reproduced.
     * @param bool $haswatchedranges Whether watched-range telemetry is available.
     * @param int $timespent Server-bounded active seconds.
     * @param int $requiredseconds Required active seconds for generic resources.
     * @return float
     */
    private function derive_percentage(
        int $lastpage,
        int $totalpages,
        float $lastposition,
        float $duration,
        float $watchedseconds,
        bool $haswatchedranges,
        int $timespent,
        int $requiredseconds
    ): float {
        if ($totalpages > 0 && $lastpage > 0) {
            return $this->clamp_percentage(($lastpage / $totalpages) * 100);
        }
        if ($duration > 0) {
            if ($haswatchedranges) {
                return $this->clamp_percentage((min($watchedseconds, $duration) / $duration) * 100);
            }

            // Compatibility for audio and older clients that do not yet submit ranges.
            return $this->clamp_percentage((min($lastposition, $duration) / $duration) * 100);
        }

        return $this->clamp_percentage(($timespent / max(1, $requiredseconds)) * 100);
    }

    /**
     * Bound client-reported active time by server-observed wall time.
     *
     * The browser remains responsible for determining whether the tab/player is
     * active, but it cannot jump cumulative time arbitrarily in one request.
     *
     * @param int $clienttimespent Client cumulative active time.
     * @param object|null $record Existing progress record.
     * @param int $now Current server timestamp.
     * @return int
     */
    private function bounded_timespent(int $clienttimespent, ?object $record, int $now): int {
        if ($record === null) {
            return min($clienttimespent, self::INITIAL_TIMESPENT_LIMIT);
        }

        $stored = max(0, (int)($record->timespent ?? 0));
        $elapsed = max(0, $now - (int)($record->timemodified ?? $now));
        $maximum = $stored + $elapsed + self::TIMESPENT_GRACE_SECONDS;

        return max($stored, min($clienttimespent, $maximum));
    }

    /**
     * Clamp one percentage.
     *
     * @param float $value
     * @return float
     */
    private function clamp_percentage(float $value): float {
        return max(0.0, min(100.0, $value));
    }

    /**
     * Build external API response.
     *
     * @param object $record
     * @param array $rewards
     * @return array
     */
    private function response(object $record, array $rewards): array {
        return [
            'status' => true,
            'completed' => !empty($record->completed),
            'progress' => (float)$record->progress,
            'completionpercentage' => (float)$record->completionpercentage,
            'lastpage' => (int)($record->lastpage ?? 0),
            'totalpages' => (int)($record->totalpages ?? 0),
            'timespent' => (int)($record->timespent ?? 0),
            'lastposition' => (float)($record->lastposition ?? 0),
            'duration' => (float)($record->duration ?? 0),
            'watchedranges' => (string)($record->watchedranges ?? '[]'),
            'points' => (int)($record->points ?? 0),
            'rewards' => $rewards,
            'timemodified' => (int)$record->timemodified,
        ];
    }
}
