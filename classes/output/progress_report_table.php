<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_videoplayer\output;

/**
 * Scalable paginated progress report table.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_report_table extends \table_sql {

    /** @var int Course id used in profile links. */
    private int $courseid;

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param int $courseid
     */
    public function __construct(string $uniqueid, int $courseid) {
        parent::__construct($uniqueid);
        $this->courseid = $courseid;
    }

    /**
     * Format learner name.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_fullname($row): string {
        $url = new \moodle_url('/user/view.php', ['id' => $row->id, 'course' => $this->courseid]);
        return \html_writer::link($url, fullname($row));
    }

    /**
     * Format email.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_email($row): string {
        return s($row->email);
    }

    /**
     * Format active time.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timespent($row): string {
        return format_time((int)$row->timespent);
    }

    /**
     * Format resume position.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_lastposition($row): string {
        $position = max(0, (int)round((float)$row->lastposition));
        $duration = max(0, (int)round((float)$row->duration));
        if ($duration <= 0) {
            return $position > 0 ? format_time($position) : '—';
        }
        return format_time($position) . ' / ' . format_time($duration);
    }

    /**
     * Format completion percentage.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_completionpercentage($row): string {
        return format_float((float)$row->completionpercentage, 2) . '%';
    }

    /**
     * Format completion state.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_completed($row): string {
        return !empty($row->completed)
            ? \html_writer::span(get_string('yes'), 'badge badge-success')
            : \html_writer::span(get_string('no'), 'badge badge-secondary');
    }

    /**
     * Format modification time.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timemodified($row): string {
        return userdate((int)$row->timemodified);
    }
}
