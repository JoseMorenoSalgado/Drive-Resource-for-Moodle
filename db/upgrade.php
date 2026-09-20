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
 * Upgrade steps for mod_videoplayer.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Execute upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_videoplayer_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061400) {
        $table = new xmldb_table('videoplayer');

        if ($dbman->table_exists($table)) {
            $legacyfield = new xmldb_field('start');
            $newfield = new xmldb_field('starttime', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'displayasstartscreen');
            if ($dbman->field_exists($table, $legacyfield) && !$dbman->field_exists($table, $newfield)) {
                $dbman->rename_field($table, $legacyfield, 'starttime');
            }

            $legacyfield = new xmldb_field('end');
            $newfield = new xmldb_field('endtime', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'starttime');
            if ($dbman->field_exists($table, $legacyfield) && !$dbman->field_exists($table, $newfield)) {
                $dbman->rename_field($table, $legacyfield, 'endtime');
            }

            $fields = [
                new xmldb_field('source', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'googledrive', 'introformat'),
                new xmldb_field('videourl', XMLDB_TYPE_CHAR, '1024', null, XMLDB_NOTNULL, null, '', 'source'),
                new xmldb_field('type', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'auto', 'videourl'),
                new xmldb_field('video', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'type'),
                new xmldb_field('endscreentext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'video'),
                new xmldb_field('displayasstartscreen', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'endscreentext'),
                new xmldb_field('starttime', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'displayasstartscreen'),
                new xmldb_field('endtime', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'starttime'),
                new xmldb_field('completionpercentage', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '80', 'endtime'),
                new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'completionpercentage'),
                new xmldb_field('displayoptions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'grade'),
                new xmldb_field('posterimage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'displayoptions'),
                new xmldb_field('extendedcompletion', XMLDB_TYPE_TEXT, null, null, null, null, null, 'posterimage'),
            ];

            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }

            // Normalize legacy nullable values before enforcing NOT NULL definitions.
            $DB->execute("UPDATE {videoplayer} SET source = 'googledrive' WHERE source IS NULL OR source = ''");
            $DB->execute("UPDATE {videoplayer} SET videourl = '' WHERE videourl IS NULL");
            $DB->execute("UPDATE {videoplayer} SET type = 'auto' WHERE type IS NULL OR type = ''");
            $sql = "UPDATE {videoplayer}
                       SET completionpercentage = 80
                     WHERE completionpercentage IS NULL OR completionpercentage = 0";
            $DB->execute($sql);

            // XMLDB does not allow changing an indexed field while a dependent index exists.
            // Drop the logical indexes first; Moodle resolves the actual database-specific names.
            $sourceindex = new xmldb_index('source_idx', XMLDB_INDEX_NOTUNIQUE, ['source']);
            if ($dbman->index_exists($table, $sourceindex)) {
                $dbman->drop_index($table, $sourceindex);
            }

            $typeindex = new xmldb_index('type_idx', XMLDB_INDEX_NOTUNIQUE, ['type']);
            if ($dbman->index_exists($table, $typeindex)) {
                $dbman->drop_index($table, $typeindex);
            }

            $videourlfield = new xmldb_field(
                'videourl',
                XMLDB_TYPE_CHAR,
                '1024',
                null,
                XMLDB_NOTNULL,
                null,
                '',
                'source'
            );
            if ($dbman->field_exists($table, $videourlfield)) {
                $dbman->change_field_type($table, $videourlfield);
                $dbman->change_field_default($table, $videourlfield);
            }

            $sourcefield = new xmldb_field(
                'source',
                XMLDB_TYPE_CHAR,
                '32',
                null,
                XMLDB_NOTNULL,
                null,
                'googledrive',
                'introformat'
            );
            if ($dbman->field_exists($table, $sourcefield)) {
                $dbman->change_field_type($table, $sourcefield);
                $dbman->change_field_default($table, $sourcefield);
            }

            $typefield = new xmldb_field(
                'type',
                XMLDB_TYPE_CHAR,
                '32',
                null,
                XMLDB_NOTNULL,
                null,
                'auto',
                'videourl'
            );
            if ($dbman->field_exists($table, $typefield)) {
                $dbman->change_field_type($table, $typefield);
                $dbman->change_field_default($table, $typefield);
            }

            $completionfield = new xmldb_field(
                'completionpercentage',
                XMLDB_TYPE_INTEGER,
                '3',
                null,
                XMLDB_NOTNULL,
                null,
                '80',
                'endtime'
            );
            if ($dbman->field_exists($table, $completionfield)) {
                $dbman->change_field_type($table, $completionfield);
                $dbman->change_field_default($table, $completionfield);
            }

            $indexes = [
                new xmldb_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['course']),
                $sourceindex,
                $typeindex,
            ];

            foreach ($indexes as $index) {
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        $viewstable = new xmldb_table('videoplayer_views');
        if (!$dbman->table_exists($viewstable)) {
            $viewstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $viewstable->add_field('videoplayerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $viewstable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $viewstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $viewstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $viewstable->add_field('progress', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $viewstable->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $viewstable->add_field('completionpercentage', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0');
            $viewstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $viewstable->add_key('fk_videoplayerid', XMLDB_KEY_FOREIGN, ['videoplayerid'], 'videoplayer', ['id']);
            $viewstable->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $viewstable->add_key('uniq_videoplayer_user', XMLDB_KEY_UNIQUE, ['videoplayerid', 'userid']);
            $viewstable->add_index('completed_idx', XMLDB_INDEX_NOTUNIQUE, ['completed']);
            $viewstable->add_index('timemodified_idx', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
            $dbman->create_table($viewstable);
        } else {
            $progressfield = new xmldb_field('progress');
            if ($dbman->field_exists($viewstable, $progressfield)) {
                $DB->execute("UPDATE {videoplayer_views} SET progress = 0 WHERE progress IS NULL");
            }

            $completedfield = new xmldb_field('completed');
            if ($dbman->field_exists($viewstable, $completedfield)) {
                $DB->execute("UPDATE {videoplayer_views} SET completed = 0 WHERE completed IS NULL");
            }

            $completionpercentagefield = new xmldb_field('completionpercentage');
            if ($dbman->field_exists($viewstable, $completionpercentagefield)) {
                $DB->execute("UPDATE {videoplayer_views} SET completionpercentage = 0 WHERE completionpercentage IS NULL");
            }

            $completedindex = new xmldb_index('completed_idx', XMLDB_INDEX_NOTUNIQUE, ['completed']);
            if ($dbman->index_exists($viewstable, $completedindex)) {
                $dbman->drop_index($viewstable, $completedindex);
            }

            $fields = [
                new xmldb_field('progress', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
                new xmldb_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'progress'),
                new xmldb_field('completionpercentage', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0', 'completed'),
            ];

            foreach ($fields as $field) {
                if (!$dbman->field_exists($viewstable, $field)) {
                    $dbman->add_field($viewstable, $field);
                } else {
                    $dbman->change_field_type($viewstable, $field);
                    $dbman->change_field_default($viewstable, $field);
                }
            }

            $indexes = [
                $completedindex,
                new xmldb_index('timemodified_idx', XMLDB_INDEX_NOTUNIQUE, ['timemodified']),
            ];

            foreach ($indexes as $index) {
                if (!$dbman->index_exists($viewstable, $index)) {
                    $dbman->add_index($viewstable, $index);
                }
            }

            $unique = new xmldb_key('uniq_videoplayer_user', XMLDB_KEY_UNIQUE, ['videoplayerid', 'userid']);
            if (!$dbman->key_exists($viewstable, $unique)) {
                $duplicates = $DB->get_records_sql("SELECT videoplayerid, userid, COUNT(*) AS total
                                                      FROM {videoplayer_views}
                                                  GROUP BY videoplayerid, userid
                                                    HAVING COUNT(*) > 1");
                foreach ($duplicates as $duplicate) {
                    $records = $DB->get_records('videoplayer_views', [
                        'videoplayerid' => $duplicate->videoplayerid,
                        'userid' => $duplicate->userid,
                    ], 'timemodified DESC, id DESC', 'id');
                    $keepfirst = true;
                    foreach ($records as $record) {
                        if ($keepfirst) {
                            $keepfirst = false;
                            continue;
                        }
                        $DB->delete_records('videoplayer_views', ['id' => $record->id]);
                    }
                }
                $dbman->add_key($viewstable, $unique);
            }
        }

        upgrade_mod_savepoint(true, 2026061400, 'videoplayer');
    }

    if ($oldversion < 2026062100) {
        $table = new xmldb_table('videoplayer');
        if ($dbman->table_exists($table)) {
            $fields = [
                new xmldb_field('displaymode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'standard', 'type'),
                new xmldb_field('disabledownload', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'displaymode'),
                new xmldb_field('disablecontextmenu', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'disabledownload'),
                new xmldb_field('enablewatermark', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'disablecontextmenu'),
                new xmldb_field('enablegamification', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enablewatermark'),
                new xmldb_field('pointsperpage', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '1', 'enablegamification'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }

            $videourlfield = new xmldb_field('videourl', XMLDB_TYPE_CHAR, '1024', null, XMLDB_NOTNULL, null, '', 'source');
            if ($dbman->field_exists($table, $videourlfield)) {
                $DB->execute("UPDATE {videoplayer} SET videourl = '' WHERE videourl IS NULL");
                $dbman->change_field_default($table, $videourlfield);
            }
        }

        $viewstable = new xmldb_table('videoplayer_views');
        if ($dbman->table_exists($viewstable)) {
            $fields = [
                new xmldb_field('lastpage', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'completionpercentage'),
                new xmldb_field('totalpages', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastpage'),
                new xmldb_field('timespent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'totalpages'),
                new xmldb_field('points', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timespent'),
            ];
            foreach ($fields as $field) {
                if (!$dbman->field_exists($viewstable, $field)) {
                    $dbman->add_field($viewstable, $field);
                }
            }
        }

        $rewardstable = new xmldb_table('videoplayer_rewards');
        if (!$dbman->table_exists($rewardstable)) {
            $rewardstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $rewardstable->add_field('videoplayerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $rewardstable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $rewardstable->add_field('rewardtype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $rewardstable->add_field('rewardkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $rewardstable->add_field('points', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rewardstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rewardstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $rewardstable->add_key('fk_videoplayerid', XMLDB_KEY_FOREIGN, ['videoplayerid'], 'videoplayer', ['id']);
            $rewardstable->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $rewardstable->add_key('uniq_reward', XMLDB_KEY_UNIQUE, ['videoplayerid', 'userid', 'rewardtype', 'rewardkey']);
            $rewardstable->add_index('rewardkey_idx', XMLDB_INDEX_NOTUNIQUE, ['rewardkey']);
            $dbman->create_table($rewardstable);
        }

        upgrade_mod_savepoint(true, 2026062100, 'videoplayer');
    }

    if ($oldversion < 2026062200) {
        upgrade_mod_savepoint(true, 2026062200, 'videoplayer');
    }

    if ($oldversion < 2026062201) {
        upgrade_mod_savepoint(true, 2026062201, 'videoplayer');
    }

    if ($oldversion < 2026062202) {
        upgrade_mod_savepoint(true, 2026062202, 'videoplayer');
    }

    if ($oldversion < 2026062203) {
        upgrade_mod_savepoint(true, 2026062203, 'videoplayer');
    }

    if ($oldversion < 2026072200) {
        upgrade_mod_savepoint(true, 2026072200, 'videoplayer');
    }

    // Moodle 4.5 LTS compatibility release. No schema change is required;
    // the savepoint allows sites running later RC builds to register the
    // compatibility package cleanly after deployment.
    if ($oldversion < 2026092007) {
        upgrade_mod_savepoint(true, 2026092007, 'videoplayer');
    }

    // Native HTML5-only video player cleanup. No schema change is required.
    if ($oldversion < 2026092012) {
        upgrade_mod_savepoint(true, 2026092012, 'videoplayer');
    }

    // Refactored progress model: preserve exact resume position and media duration.
    if ($oldversion < 2026092013) {
        $viewstable = new xmldb_table('videoplayer_views');
        if ($dbman->table_exists($viewstable)) {
            $lastposition = new xmldb_field(
                'lastposition',
                XMLDB_TYPE_NUMBER,
                '12, 3',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'timespent'
            );
            if (!$dbman->field_exists($viewstable, $lastposition)) {
                $dbman->add_field($viewstable, $lastposition);
            }

            $duration = new xmldb_field(
                'duration',
                XMLDB_TYPE_NUMBER,
                '12, 3',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'lastposition'
            );
            if (!$dbman->field_exists($viewstable, $duration)) {
                $dbman->add_field($viewstable, $duration);
            }
        }

        upgrade_mod_savepoint(true, 2026092013, 'videoplayer');
    }

    // Empty-string defaults on CHAR NOT NULL fields are invalid in XMLDB.
    // Existing values are preserved; only the database-level default is removed.
    if ($oldversion < 2026092014) {
        $table = new xmldb_table('videoplayer');
        $videourlfield = new xmldb_field(
            'videourl',
            XMLDB_TYPE_CHAR,
            '1024',
            null,
            XMLDB_NOTNULL,
            null,
            null,
            'source'
        );
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $videourlfield)) {
            $dbman->change_field_default($table, $videourlfield);
        }

        upgrade_mod_savepoint(true, 2026092014, 'videoplayer');
    }

    // Moodle custom progress completion rule.
    if ($oldversion < 2026092015) {
        $table = new xmldb_table('videoplayer');
        $field = new xmldb_field(
            'completionprogressenabled',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'completionpercentage'
        );

        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092015, 'videoplayer');
    }

    return true;
}
