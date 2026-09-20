<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\local\progress;

use mod_videoplayer\local\gamification\reward_service;

/**
 * Drive Resource progress, resume and completion service.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_service {

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
        global $DB;

        $now = time();
        $lastpage = max(0, (int)($input['lastpage'] ?? 0));
        $totalpages = max(0, (int)($input['totalpages'] ?? 0));
        $timespent = max(0, (int)($input['timespent'] ?? 0));
        $progress = max(0.0, (float)($input['progress'] ?? 0));
        $lastposition = max(0.0, (float)($input['lastposition'] ?? 0));
        $duration = max(0.0, (float)($input['duration'] ?? 0));
        $clientpercentage = $this->clamp_percentage((float)($input['completionpercentage'] ?? 0));
        $derivedpercentage = $this->derive_percentage(
            $clientpercentage,
            $lastpage,
            $totalpages,
            $lastposition,
            $duration
        );
        $requiredpercentage = max(1, min(100, (int)($instance->completionpercentage ?? 80)));
        $completed = $derivedpercentage >= $requiredpercentage;

        $conditions = [
            'videoplayerid' => (int)$instance->id,
            'userid' => $userid,
        ];

        $transaction = $DB->start_delegated_transaction();
        $record = $DB->get_record('videoplayer_views', $conditions);
        $wascompleted = $record ? !empty($record->completed) : false;

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

        if (!empty($record->completed) && !$wascompleted) {
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }

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
     * Derive a trustworthy internal percentage from the resource-specific
     * position fields. Client percentage is retained as a fallback for generic
     * resources only.
     *
     * @param float $clientpercentage
     * @param int $lastpage
     * @param int $totalpages
     * @param float $lastposition
     * @param float $duration
     * @return float
     */
    private function derive_percentage(
        float $clientpercentage,
        int $lastpage,
        int $totalpages,
        float $lastposition,
        float $duration
    ): float {
        if ($totalpages > 0 && $lastpage > 0) {
            return $this->clamp_percentage(($lastpage / $totalpages) * 100);
        }
        if ($duration > 0) {
            return $this->clamp_percentage((min($lastposition, $duration) / $duration) * 100);
        }
        return $this->clamp_percentage($clientpercentage);
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
            'points' => (int)($record->points ?? 0),
            'rewards' => $rewards,
            'timemodified' => (int)$record->timemodified,
        ];
    }
}
