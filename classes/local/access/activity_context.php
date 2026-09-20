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

namespace mod_videoplayer\local\access;

/**
 * Immutable activity access context.
 *
 * Centralises course-module loading, login enforcement and capability checks so
 * browser endpoints and external APIs do not duplicate security-critical code.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_context {
    /** @var \stdClass Course module record. */
    private \stdClass $cm;

    /** @var \stdClass Course record. */
    private \stdClass $course;

    /** @var \stdClass Drive Resource instance. */
    private \stdClass $instance;

    /** @var \context_module Module context. */
    private \context_module $context;

    /**
     * Constructor.
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @param \stdClass $instance
     * @param \context_module $context
     */
    private function __construct(
        \stdClass $cm,
        \stdClass $course,
        \stdClass $instance,
        \context_module $context
    ) {
        $this->cm = $cm;
        $this->course = $course;
        $this->instance = $instance;
        $this->context = $context;
    }

    /**
     * Load an activity context and enforce access.
     *
     * @param int $cmid Course module id.
     * @param string $capability Capability required after login.
     * @param bool $autologinguest Whether Moodle may auto-login the guest user.
     * @return self
     */
    public static function require_from_cmid(
        int $cmid,
        string $capability = 'mod/videoplayer:view',
        bool $autologinguest = true
    ): self {
        global $DB;

        $cm = get_coursemodule_from_id('videoplayer', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $instance = $DB->get_record('videoplayer', ['id' => $cm->instance], '*', MUST_EXIST);

        require_login($course, $autologinguest, $cm);
        $context = \context_module::instance($cm->id);
        require_capability($capability, $context);

        return new self($cm, $course, $instance, $context);
    }

    /**
     * Course module record.
     *
     * @return \stdClass
     */
    public function cm(): \stdClass {
        return $this->cm;
    }

    /**
     * Course record.
     *
     * @return \stdClass
     */
    public function course(): \stdClass {
        return $this->course;
    }

    /**
     * Activity instance record.
     *
     * @return \stdClass
     */
    public function instance(): \stdClass {
        return $this->instance;
    }

    /**
     * Module context.
     *
     * @return \context_module
     */
    public function context(): \context_module {
        return $this->context;
    }
}
