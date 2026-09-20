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
 * Administration settings for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'mod_videoplayer/enabletracking',
        get_string('setting_enabletracking', 'mod_videoplayer'),
        get_string('setting_enabletracking_desc', 'mod_videoplayer'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'mod_videoplayer/defaultrequiredseconds',
        get_string('setting_defaultrequiredseconds', 'mod_videoplayer'),
        get_string('setting_defaultrequiredseconds_desc', 'mod_videoplayer'),
        300,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_videoplayer/defaultcompletionpercentage',
        get_string('setting_defaultcompletionpercentage', 'mod_videoplayer'),
        get_string('setting_defaultcompletionpercentage_desc', 'mod_videoplayer'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_videoplayer/showresourcetype',
        get_string('setting_showresourcetype', 'mod_videoplayer'),
        get_string('setting_showresourcetype_desc', 'mod_videoplayer'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'mod_videoplayer/playercolormode',
        get_string('setting_playercolormode', 'mod_videoplayer'),
        get_string('setting_playercolormode_desc', 'mod_videoplayer'),
        'theme',
        [
            'theme' => get_string('setting_playercolormode_theme', 'mod_videoplayer'),
            'custom' => get_string('setting_playercolormode_custom', 'mod_videoplayer'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_videoplayer/playercolor',
        get_string('setting_playercolor', 'mod_videoplayer'),
        get_string('setting_playercolor_desc', 'mod_videoplayer'),
        '#3b82f6',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_videoplayer/pdfcacheenabled',
        get_string('setting_pdfcacheenabled', 'mod_videoplayer'),
        get_string('setting_pdfcacheenabled_desc', 'mod_videoplayer'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'mod_videoplayer/pdfcachettl',
        get_string('setting_pdfcachettl', 'mod_videoplayer'),
        get_string('setting_pdfcachettl_desc', 'mod_videoplayer'),
        2592000,
        PARAM_INT
    ));
}
