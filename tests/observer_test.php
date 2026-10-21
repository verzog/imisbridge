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
 * Tests for the local_imisbridge event observer.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

use local_imisbridge\task\sync_user_task;
use local_imisbridge\task\push_activity_task;
use local_imisbridge\task\push_module_note_task;

/**
 * Unit tests for {@see observer}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Percentage scores are rounded and guard against a non-gradable quiz.
     *
     * @return void
     */
    public function test_calculate_percentage(): void {
        $this->assertSame(50.0, observer::calculate_percentage(5.0, 10.0));
        $this->assertSame(100.0, observer::calculate_percentage(10.0, 10.0));
        $this->assertSame(0.0, observer::calculate_percentage(0.0, 10.0));
        $this->assertEqualsWithDelta(33.33, observer::calculate_percentage(1.0, 3.0), 0.001);

        // Not gradable — must not divide by zero.
        $this->assertSame(0.0, observer::calculate_percentage(5.0, 0.0));
    }

    /**
     * A score at or above the pass mark is a pass; below is a fail.
     *
     * @return void
     */
    public function test_passfail_status(): void {
        $this->assertSame('Pass', observer::passfail_status(50.0, 50.0));
        $this->assertSame('Pass', observer::passfail_status(80.0, 50.0));
        $this->assertSame('Fail', observer::passfail_status(49.99, 50.0));
    }

    /**
     * Logging in queues a per-user sync task carrying the username as the iMIS ID.
     *
     * @return void
     */
    public function test_user_loggedin_queues_sync_task(): void {
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');

        // Moodle stores usernames in lower case; the iMIS ID mirrors the username.
        $user = $this->getDataGenerator()->create_user(['username' => 'member123']);

        $event = \core\event\user_loggedin::create([
            'userid'   => $user->id,
            'objectid' => $user->id,
            'other'    => ['username' => $user->username],
        ]);

        // Call the observer directly: non-internal observers are buffered until the
        // enclosing DB transaction commits, which does not happen within a unit test.
        observer::user_loggedin($event);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_user_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame('member123', reset($tasks)->get_custom_data()->imisid);
    }

    /**
     * When configured to use the ID number field, that value is queued as the iMIS ID.
     *
     * @return void
     */
    public function test_user_loggedin_uses_configured_id_source(): void {
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        set_config('imis_id_source', 'idnumber', 'local_imisbridge');

        $user = $this->getDataGenerator()->create_user([
            'username' => 'member123',
            'idnumber' => 'IM-4242',
        ]);

        $event = \core\event\user_loggedin::create([
            'userid'   => $user->id,
            'objectid' => $user->id,
            'other'    => ['username' => $user->username],
        ]);
        observer::user_loggedin($event);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_user_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame('IM-4242', reset($tasks)->get_custom_data()->imisid);
    }

    /**
     * A user with no value in the configured ID field queues no task.
     *
     * @return void
     */
    public function test_user_loggedin_skips_when_id_field_empty(): void {
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        // Generated users have an empty idnumber by default.
        set_config('imis_id_source', 'idnumber', 'local_imisbridge');

        $user = $this->getDataGenerator()->create_user(['username' => 'member123']);

        $event = \core\event\user_loggedin::create([
            'userid'   => $user->id,
            'objectid' => $user->id,
            'other'    => ['username' => $user->username],
        ]);
        observer::user_loggedin($event);

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(sync_user_task::class));
    }

    /**
     * With no WSDL configured, login queues no task (a clean no-op, not an error).
     *
     * @return void
     */
    public function test_user_loggedin_skips_when_unconfigured(): void {
        $this->resetAfterTest();

        // No wsdl_url set: the client cannot be built, so nothing should be queued.
        $user = $this->getDataGenerator()->create_user(['username' => 'member123']);

        $event = \core\event\user_loggedin::create([
            'userid'   => $user->id,
            'objectid' => $user->id,
            'other'    => ['username' => $user->username],
        ]);
        observer::user_loggedin($event);

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(sync_user_task::class));
    }

    /**
     * Completing a course queues a push carrying the numeric Moodle course id
     * (the ATS productID), not the course idnumber or shortname.
     *
     * @return void
     */
    public function test_course_completed_pushes_numeric_course_id(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');

        $user   = $this->getDataGenerator()->create_user(['username' => 'member123']);
        // An idnumber/shortname that must NOT be used as the productID.
        $course = $this->getDataGenerator()->create_course([
            'idnumber'  => 'IMIS-PROD-99',
            'shortname' => 'SHORT99',
        ]);

        // Give the learner a course-total grade so course_score() is exercised.
        $gradeitem = \grade_item::fetch_course_item((int)$course->id);
        $DB->insert_record('grade_grades', (object)[
            'itemid'       => $gradeitem->id,
            'userid'       => $user->id,
            'finalgrade'   => 80.0,
            'rawgrademax'  => $gradeitem->grademax,
            'rawgrademin'  => $gradeitem->grademin,
            'timecreated'  => 1000,
            'timemodified' => 1000,
        ]);

        $event = \core\event\course_completed::create([
            'objectid'      => 1,
            'relateduserid' => $user->id,
            'courseid'      => $course->id,
            'context'       => \context_course::instance($course->id),
            'other'         => ['relateduserid' => $user->id],
        ]);
        observer::course_completed($event);

        $tasks = \core\task\manager::get_adhoc_tasks(push_activity_task::class);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        // Numeric course id, not the idnumber/shortname.
        $this->assertSame((string)$course->id, $data->coursenum);
        // The course-total grade resolves to a percentage via course_score().
        $this->assertEqualsWithDelta(80.0, (float)$data->score, 0.001);
    }

    /**
     * Completion states map to the MOODLENOTE status; incomplete/unknown skip.
     *
     * @return void
     */
    public function test_module_status(): void {
        $this->assertSame('PASS', observer::module_status(COMPLETION_COMPLETE_PASS));
        $this->assertSame('FAIL', observer::module_status(COMPLETION_COMPLETE_FAIL));
        $this->assertSame('COMPLETE', observer::module_status(COMPLETION_COMPLETE));
        $this->assertNull(observer::module_status(COMPLETION_INCOMPLETE));
        $this->assertNull(observer::module_status(-1));
    }

    /**
     * Complete a graded module and return the queued module-note tasks.
     *
     * @param string $modname       The module to create ('assign' graded, 'label' not).
     * @param bool   $enabletoggle  Whether to enable push_module_completions.
     * @return \local_imisbridge\task\push_module_note_task[]
     */
    private function run_module_completion(string $modname, bool $enabletoggle): array {
        global $DB;

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        if ($enabletoggle) {
            set_config('push_module_completions', '1', 'local_imisbridge');
        }

        $user   = $this->getDataGenerator()->create_user(['username' => 'member123']);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $instance = $this->getDataGenerator()->get_plugin_generator('mod_' . $modname)->create_instance([
            'course'     => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_instance($modname, $instance->id);

        // Record the completion via the API (buffered observers are not dispatched
        // in a unit test), then invoke the observer directly with the event.
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $rec = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $user->id]);
        $this->assertNotEmpty($rec, 'completion record should exist');

        // Calling update_state() may itself dispatch this observer (its completion
        // event fires during the call), so clear the queue and count only the
        // explicit invocation below — deterministic regardless of event buffering.
        $DB->delete_records('task_adhoc');

        $event = \core\event\course_module_completion_updated::create([
            'objectid'      => $rec->id,
            'context'       => \context_module::instance($cm->id),
            'relateduserid' => $user->id,
            'other'         => ['relateduserid' => $user->id, 'overrideby' => $rec->overrideby],
        ]);
        observer::course_module_completion_updated($event);

        return \core\task\manager::get_adhoc_tasks(push_module_note_task::class);
    }

    /**
     * A graded module completion queues one MOODLENOTE push with the right data.
     *
     * @return void
     */
    public function test_module_completion_graded_queues_note(): void {
        $this->resetAfterTest();

        $tasks = $this->run_module_completion('assign', true);

        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame('member123', $data->imisid);
        $this->assertSame('COMPLETE', $data->status);
        $this->assertNotEmpty($data->cmid);
    }

    /**
     * With the opt-in toggle off (the default), a graded completion queues nothing.
     *
     * @return void
     */
    public function test_module_completion_default_off(): void {
        $this->resetAfterTest();

        $this->assertCount(0, $this->run_module_completion('assign', false));
    }

    /**
     * An ungraded module (no grade item) is skipped even with the toggle on.
     *
     * @return void
     */
    public function test_module_completion_ungraded_skipped(): void {
        $this->resetAfterTest();

        $this->assertCount(0, $this->run_module_completion('label', true));
    }

    /**
     * With all login syncs disabled, no task is queued.
     *
     * @return void
     */
    public function test_user_loggedin_respects_disabled_toggles(): void {
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        set_config('sync_enrolments_on_login', '0', 'local_imisbridge');
        set_config('sync_cancellations_on_login', '0', 'local_imisbridge');
        set_config('sync_groups_on_login', '0', 'local_imisbridge');

        $user = $this->getDataGenerator()->create_user(['username' => 'member123']);

        $event = \core\event\user_loggedin::create([
            'userid'   => $user->id,
            'objectid' => $user->id,
            'other'    => ['username' => $user->username],
        ]);
        observer::user_loggedin($event);

        $tasks = \core\task\manager::get_adhoc_tasks(sync_user_task::class);
        $this->assertCount(0, $tasks);
    }

    /**
     * completion_push_data builds a pass record keyed on the numeric course id.
     *
     * @return void
     */
    public function test_completion_push_data(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $data   = observer::completion_push_data('20018', $course, 0);

        $this->assertSame('push_completions', $data->toggle);
        $this->assertSame('20018', $data->imisid);
        $this->assertSame((string)$course->id, $data->coursenum);
        $this->assertSame('COMPLETE', $data->status);
        $this->assertSame('CEU', $data->credittype);
        $this->assertSame(0.0, $data->creditvalue);
        $this->assertSame(0.0, $data->score);
        $this->assertSame(date('Y-m-d'), $data->completiondate);
    }
}
