<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Version information for mod_videoplayer.
 *
 * Moodle 4.5 LTS compatibility branch.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_videoplayer';
$plugin->version = 2026092013;
$plugin->release = '1.1.33-rc17-m45';
$plugin->requires = 2024100700; // Moodle 4.5.0 (LTS).
$plugin->supported = [405, 405];
$plugin->incompatible = 500;
$plugin->maturity = MATURITY_RC;
