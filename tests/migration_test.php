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
 * Tests for the carry-over from local_imisbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

use local_membershipbridge\task\push_activity_task;
use local_membershipbridge\task\push_module_note_task;

/**
 * Unit tests for {@see migration}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\migration
 */
final class migration_test extends \advanced_testcase {
    /**
     * Reset the database after each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create one of the old plugin's tables, with the new table's structure.
     *
     * @param string $suffix The table name without the component prefix.
     * @return \xmldb_table The created table.
     */
    private function old_table(string $suffix): \xmldb_table {
        global $DB;
        $file = new \xmldb_file(\core_component::get_component_directory(migration::NEW) . '/db/install.xml');
        $file->loadXMLStructure();
        $table = $file->getStructure()->getTable(migration::NEW . '_' . $suffix);
        $table->setName(migration::OLD . '_' . $suffix);
        $DB->get_manager()->create_temp_table($table);
        return $table;
    }

    /**
     * Queue a task as the old plugin would have: under its component and class name.
     *
     * @param \core\task\adhoc_task $task The task, of this plugin's class.
     * @return int The task_adhoc id.
     */
    private function old_task(\core\task\adhoc_task $task): int {
        global $DB;
        $id = \core\task\manager::queue_adhoc_task($task);
        $record = $DB->get_record('task_adhoc', ['id' => $id], '*', MUST_EXIST);
        $DB->update_record('task_adhoc', (object)[
            'id' => $id,
            'component' => migration::OLD,
            'classname' => str_replace(migration::NEW, migration::OLD, $record->classname),
        ]);
        return $id;
    }

    /**
     * Without local_imisbridge installed, nothing is carried over.
     *
     * @return void
     */
    public function test_nothing_to_carry_over(): void {
        $this->assertSame([], migration::from_imisbridge());
    }

    /**
     * Settings, sent-score records, capability grants, group memberships, queued
     * pushes and hand-built service functions carry over, and the old plugin's
     * writes are switched off.
     *
     * @return void
     */
    public function test_carries_site_over(): void {
        global $DB;

        // The old plugin's settings; one is already set on the new plugin and is kept.
        set_config('version', 2026102700, migration::OLD);
        set_config('wsdl_url', 'https://ats.example.invalid/wsmoodle.asmx?WSDL', migration::OLD);
        set_config('groups_last_sync', '1761400000', migration::OLD);
        set_config('push_completions', '1', migration::OLD);
        set_config('transport', 'soap', migration::OLD);
        unset_config('wsdl_url', migration::NEW);
        set_config('transport', 'rest', migration::NEW);

        // A sent score, keeping its id so queued pushes still match their claim.
        $user  = $this->getDataGenerator()->create_user();
        $table = $this->old_table('scorepush');
        $DB->import_record(migration::OLD . '_scorepush', (object)[
            'id' => 41,
            'userid' => $user->id,
            'itemid' => 7,
            'finalgrade' => 72.0,
            'generation' => 3,
            'timequeued' => 1,
        ]);

        // A role granted the old cancellation capability.
        $roleid = $this->getDataGenerator()->create_role();
        $syscontext = \context_system::instance();
        $DB->insert_record('role_capabilities', (object)[
            'contextid' => $syscontext->id,
            'roleid' => $roleid,
            'capability' => 'local/imisbridge:cancelenrolment',
            'permission' => CAP_ALLOW,
            'timemodified' => time(),
            'modifierid' => 0,
        ]);

        // A tutorial-group membership the old plugin manages.
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        groups_add_member($group->id, $user->id);
        $DB->set_field('groups_members', 'component', migration::OLD, ['groupid' => $group->id]);

        // A push the old plugin queued but had not sent.
        \core\task\manager::queue_adhoc_task(new push_activity_task());
        $task = $DB->get_record('task_adhoc', ['component' => migration::NEW]);
        $DB->update_record('task_adhoc', (object)[
            'id' => $task->id,
            'component' => migration::OLD,
            'classname' => str_replace(migration::NEW, migration::OLD, $task->classname),
        ]);

        // A hand-built inbound service holding the old cancel function, and the old
        // plugin's own service, which is left for its uninstall to remove.
        $ats = $DB->insert_record('external_services', (object)[
            'name' => 'ATS inbound',
            'enabled' => 1,
            'restrictedusers' => 1,
            'timecreated' => time(),
        ]);
        $own = $DB->insert_record('external_services', (object)[
            'name' => 'iMIS bridge',
            'enabled' => 1,
            'restrictedusers' => 1,
            'component' => migration::OLD,
            'timecreated' => time(),
        ]);
        foreach ([$ats, $own] as $serviceid) {
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => 'local_imisbridge_cancel_enrolment',
            ]);
        }

        $report = migration::from_imisbridge();

        $this->assertSame('https://ats.example.invalid/wsmoodle.asmx?WSDL', get_config(migration::NEW, 'wsdl_url'));
        $this->assertSame('1761400000', get_config(migration::NEW, 'groups_last_sync'));
        $this->assertSame('rest', get_config(migration::NEW, 'transport'));
        $this->assertNotEquals('2026102700', get_config(migration::NEW, 'version'));

        $this->assertSame(1, $report['scorepush']);
        $this->assertSame(0, $report['log']);
        $copied = $DB->get_record(migration::NEW . '_scorepush', ['id' => 41], '*', MUST_EXIST);
        $this->assertEquals(3, $copied->generation);

        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $roleid,
            'contextid' => $syscontext->id,
            'capability' => 'local/membershipbridge:cancelenrolment',
        ]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => $group->id,
            'userid' => $user->id,
            'component' => migration::NEW,
        ]));

        $moved = $DB->get_record('task_adhoc', ['id' => $task->id], '*', MUST_EXIST);
        $this->assertSame(migration::NEW, $moved->component);
        $this->assertSame($task->classname, $moved->classname);

        $this->assertTrue($DB->record_exists('external_services_functions', [
            'externalserviceid' => $ats,
            'functionname' => 'local_membershipbridge_cancel_enrolment',
        ]));
        $this->assertFalse($DB->record_exists('external_services_functions', [
            'externalserviceid' => $own,
            'functionname' => 'local_membershipbridge_cancel_enrolment',
        ]));

        $this->assertSame('0', get_config(migration::OLD, 'push_completions'));
        $this->assertSame('0', get_config(migration::OLD, 'tutorial_sync_enabled'));

        $DB->get_manager()->drop_table($table);
    }

    /**
     * From a local_imisbridge older than 1.8.0, queued activity pushes still carry the
     * course idnumber or shortname as the product, so they are dropped rather than
     * sent to the wrong iMIS product; other queued tasks still carry over.
     *
     * @return void
     */
    public function test_drops_activity_pushes_from_before_numeric_product(): void {
        global $DB;
        set_config('version', 2026101400, migration::OLD);
        $push = $this->old_task(new push_activity_task());
        $note = $this->old_task(new push_module_note_task());

        $report = migration::from_imisbridge();

        $this->assertSame(1, $report['droppedtasks']);
        $this->assertSame(1, $report['adhoctasks']);
        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $push]));
        $this->assertSame(migration::NEW, $DB->get_field('task_adhoc', 'component', ['id' => $note]));
    }

    /**
     * From 1.8.0 on, queued activity pushes are kept and carried over.
     *
     * @return void
     */
    public function test_keeps_activity_pushes_from_numeric_product_on(): void {
        global $DB;
        set_config('version', 2026101901, migration::OLD);
        $push = $this->old_task(new push_activity_task());

        $report = migration::from_imisbridge();

        $this->assertSame(0, $report['droppedtasks']);
        $this->assertSame(migration::NEW, $DB->get_field('task_adhoc', 'component', ['id' => $push]));
    }
}
