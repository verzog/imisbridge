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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply the local_imisbridge upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_imisbridge_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026101400) {
        // Define the audit log table.
        $table = new xmldb_table('local_imisbridge_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('operation', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('imisid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('productcode', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('request', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('operation', XMLDB_INDEX_NOTUNIQUE, ['operation']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026101400, 'local', 'imisbridge');
    }

    if ($oldversion < 2026101901) {
        // 1.8.0 changes the productID the completion/quiz observers send from the
        // course idnumber/shortname to the numeric course id. Any adhoc
        // push_activity_task queued before this upgrade still carries the old
        // value in its custom data, and push_activity_task::execute() forwards it
        // unchanged — which would write to the wrong iMIS product. Drop those
        // pending tasks; affected completions can be re-sent via a manual sync or
        // the bulk pull web service (get_completions_since).
        $DB->delete_records('task_adhoc', [
            'classname' => '\\local_imisbridge\\task\\push_activity_task',
        ]);

        upgrade_plugin_savepoint(true, 2026101901, 'local', 'imisbridge');
    }

    if ($oldversion < 2026102204) {
        // Widen the audit log operation column from char(100) to char(255): a REST
        // operation name can be a full IQA GET URL (with encoded prompt values) that
        // overflows 100 characters, which made the audit-log insert fail.
        //
        // The operation column carries a non-unique index, and the database won't
        // alter an indexed column (ddl_dependency_exception), so drop the index,
        // widen the column, then re-add the index. Each step is guarded so the step
        // is safe to re-run after the 1.19.3 attempt failed at change_field_precision.
        $table = new xmldb_table('local_imisbridge_log');
        $field = new xmldb_field('operation', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'userid');
        $index = new xmldb_index('operation', XMLDB_INDEX_NOTUNIQUE, ['operation']);

        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026102204, 'local', 'imisbridge');
    }

    return true;
}
