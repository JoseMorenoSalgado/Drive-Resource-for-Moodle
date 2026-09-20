<?php
// This file is part of Moodle - http://moodle.org/

/**
 * External service definitions for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_videoplayer_save_progress' => [
        'classname' => 'mod_videoplayer\\external\\save_progress',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Save Drive Resource progress, reading state and completion data.',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
