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

namespace mod_videoplayer\output;

use mod_videoplayer\local\access\activity_context;
use mod_videoplayer\local\plugin_config;
use mod_videoplayer\local\resource\resource_descriptor;

/**
 * Template model for the Elearning Stream learner view.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resource_view implements \renderable, \templatable {
    /** @var activity_context Activity access context. */
    private activity_context $activity;

    /** @var resource_descriptor Normalised resource. */
    private resource_descriptor $resource;

    /** @var \stdClass|null Current user progress. */
    private ?\stdClass $progress;

    /**
     * @param activity_context $activity Activity context.
     * @param resource_descriptor $resource Resource descriptor.
     * @param \stdClass|null $progress Current user progress.
     */
    public function __construct(
        activity_context $activity,
        resource_descriptor $resource,
        ?\stdClass $progress = null
    ) {
        $this->activity = $activity;
        $this->resource = $resource;
        $this->progress = $progress;
    }

    /**
     * Template name for the supported resource.
     *
     * @return string
     */
    public function template_name(): string {
        return $this->resource->is_pdf()
            ? 'mod_videoplayer/pdfjs'
            : 'mod_videoplayer/video';
    }

    /**
     * Export browser-safe template data.
     *
     * @param \renderer_base $output Moodle renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $USER;

        $instance = $this->activity->instance();
        $cmid = (int)$this->activity->cm()->id;
        $isguest = isguestuser();
        $trackprogress = !$isguest && plugin_config::tracking_enabled();

        $playerstyle = '';
        if (plugin_config::player_color_mode() === 'custom') {
            $playerstyle = '--mod-videoplayer-player-color: ' . plugin_config::player_color() . ';';
        }

        $initialpage = max(1, (int)($this->progress->lastpage ?? 1));
        $totalpages = max(0, (int)($this->progress->totalpages ?? 0));
        $lastposition = max(0.0, (float)($this->progress->lastposition ?? 0));
        $duration = max(0.0, (float)($this->progress->duration ?? 0));
        $watchedranges = (string)($this->progress->watchedranges ?? '[]');
        $timespent = max(0, (int)($this->progress->timespent ?? 0));
        $completionpercent = max(
            0.0,
            min(100.0, (float)($this->progress->completionpercentage ?? 0))
        );

        $watermark = '';
        if (!empty($instance->enablewatermark) && !$isguest) {
            $watermark = fullname($USER) . ' · '
                . userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
        }

        $data = [
            'type' => $this->resource->type(),
            'cmid' => $cmid,
            'title' => format_string($instance->name, true, ['context' => $this->activity->context()]),
            'trackprogress' => $trackprogress,
            'disablecontextmenu' => !empty($instance->disablecontextmenu),
            'enablewatermark' => !empty($instance->enablewatermark),
            'watermark' => $watermark,
            'initialtimespent' => $timespent,
            'completionpercent' => round($completionpercent, 2),
        ];

        if ($this->resource->is_pdf()) {
            $data['pdfurl'] = $this->resource->protected_url($cmid)->out(false);
            $data['initialpage'] = $initialpage;
            $data['totalpages'] = $totalpages;
            return $data;
        }

        $data['videourl'] = $this->resource->protected_url($cmid, 'managed')->out(false);
        $data['videofallbackurl'] = '';
        $data['playerstyle'] = $playerstyle;
        $data['initialposition'] = round($lastposition, 3);
        $data['initialduration'] = round($duration, 3);
        $data['watchedranges'] = $watchedranges;

        return $data;
    }
}
