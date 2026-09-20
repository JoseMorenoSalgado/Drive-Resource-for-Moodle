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
use mod_videoplayer\local\resource\resource_descriptor;

/**
 * Template model for the learner resource view.
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
     * Constructor.
     *
     * @param activity_context $activity
     * @param resource_descriptor $resource
     * @param \stdClass|null $progress
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
     * Template to use for this resource type.
     *
     * @return string
     */
    public function template_name(): string {
        if ($this->resource->is_pdf_like()) {
            return 'mod_videoplayer/pdfjs';
        }
        if ($this->resource->is_video()) {
            return 'mod_videoplayer/video';
        }
        if ($this->resource->is_audio()) {
            return 'mod_videoplayer/audio';
        }
        if ($this->resource->is_image()) {
            return 'mod_videoplayer/image';
        }
        return 'mod_videoplayer/resource';
    }

    /**
     * Export browser-safe template data.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $USER;

        $instance = $this->activity->instance();
        $cmid = (int)$this->activity->cm()->id;
        $type = $this->resource->type();
        $protectedurl = $this->resource->protected_url($cmid);
        $primaryvideourl = $this->resource->is_video()
            ? $this->resource->protected_url($cmid, 'transcoded')->out(false)
            : '';
        $fallbackvideourl = $this->resource->is_video()
            ? $this->resource->protected_url($cmid, 'source')->out(false)
            : '';
        $config = get_config('mod_videoplayer');

        $typestringkey = 'type' . $type;
        $typestring = get_string_manager()->string_exists($typestringkey, 'mod_videoplayer')
            ? get_string($typestringkey, 'mod_videoplayer')
            : get_string('typefile', 'mod_videoplayer');

        $playerstyle = '';
        if (($config->playercolormode ?? '') === 'custom') {
            $playercolor = trim((string)($config->playercolor ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $playercolor)) {
                $playerstyle = '--mod-videoplayer-player-color: ' . $playercolor . ';';
            }
        }

        $initialpage = max(1, (int)($this->progress->lastpage ?? 1));
        $totalpages = max(0, (int)($this->progress->totalpages ?? 0));
        $lastposition = max(0.0, (float)($this->progress->lastposition ?? 0));
        $duration = max(0.0, (float)($this->progress->duration ?? 0));
        $timespent = max(0, (int)($this->progress->timespent ?? 0));
        $completionpercent = max(0.0, min(100.0, (float)($this->progress->completionpercentage ?? 0)));
        $points = max(0, (int)($this->progress->points ?? 0));

        $watermark = '';
        if (!empty($instance->enablewatermark) && !isguestuser()) {
            $watermark = fullname($USER) . ' · '
                . userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
        }

        return [
            'type' => $type,
            'cmid' => $cmid,
            'title' => format_string($instance->name, true, ['context' => $this->activity->context()]),
            'showresourcetype' => (string)($config->showresourcetype ?? '1') !== '0',
            'trackprogress' => !isguestuser() && (string)($config->enabletracking ?? '1') !== '0',
            'resourcetype' => get_string('resourcetype', 'mod_videoplayer') . ': ' . $typestring,
            'protectedurl' => $protectedurl->out(false),
            'pdfurl' => $protectedurl->out(false),
            'videourl' => $primaryvideourl,
            'videofallbackurl' => $fallbackvideourl,
            'audiourl' => $protectedurl->out(false),
            'imageurl' => $protectedurl->out(false),
            'playerstyle' => $playerstyle,
            'disablecontextmenu' => !empty($instance->disablecontextmenu),
            'enablewatermark' => !empty($instance->enablewatermark),
            'enablegamification' => !empty($instance->enablegamification),
            'initialpage' => $initialpage,
            'totalpages' => $totalpages,
            'initialposition' => round($lastposition, 3),
            'initialduration' => round($duration, 3),
            'initialtimespent' => $timespent,
            'points' => $points,
            'completionpercent' => round($completionpercent, 2),
            'watermark' => $watermark,
        ];
    }
}
