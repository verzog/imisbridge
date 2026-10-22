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
use local_imisbridge\task\push_score_note_task;

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
     * The raw quiz grade is the attempt rescaled to the quiz maximum grade, not a
     * percentage: 8/10 marks on a quiz graded out of 100 is 80, and on a quiz
     * graded out of 10 is 8.
     *
     * @return void
     */
    public function test_quiz_raw_grade(): void {
        // 8 of 10 marks, quiz graded out of 100 -> 80 (not 80%).
        $this->assertSame(80.0, observer::quiz_raw_grade(8.0, 10.0, 100.0));
        // 8 of 10 marks, quiz graded out of 10 -> 8.
        $this->assertSame(8.0, observer::quiz_raw_grade(8.0, 10.0, 10.0));
        // Full marks out of 10 -> 10 (the raw grade, not 100%).
        $this->assertSame(10.0, observer::quiz_raw_grade(10.0, 10.0, 10.0));
        // Max grade 0 is an ungraded quiz (GRADE_TYPE_NONE) -> 0, not raw marks.
        $this->assertSame(0.0, observer::quiz_raw_grade(8.0, 10.0, 0.0));
        // Non-gradable attempt: guard divide-by-zero.
        $this->assertSame(0.0, observer::quiz_raw_grade(8.0, 0.0, 100.0));
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

    /**
     * module_note_data() builds a supplementary note (never a completion): it
     * targets the push_module_completions toggle and carries the course module.
     *
     * @covers \local_imisbridge\observer::module_note_data
     * @return void
     */
    public function test_module_note_data(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm     = get_coursemodule_from_id('', $assign->cmid);

        $data = observer::module_note_data('21886', $cm, 0);

        $this->assertSame('push_module_completions', $data->toggle);
        $this->assertSame('21886', $data->imisid);
        $this->assertSame((string)$course->id, $data->coursenum);
        $this->assertSame((int)$cm->id, $data->cmid);
        $this->assertSame('assign', $data->modname);
        // No matched Moodle user (userid 0): status defaults to COMPLETE, score 0.
        $this->assertSame('COMPLETE', $data->status);
        $this->assertSame(0.0, $data->score);
        $this->assertSame(date('Y-m-d'), $data->date);
    }

    /**
     * module_note_data() sends the learner's RAW gradebook grade, not a
     * percentage — matching the vendor's module notes, which carry the raw item
     * grade. A 15/20 grade is sent as 15, not 75(%).
     *
     * @covers \local_imisbridge\observer::module_note_data
     * @return void
     */
    public function test_module_note_data_sends_raw_grade(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 20]);
        $cm     = get_coursemodule_from_id('', $assign->cmid);
        $user   = $this->getDataGenerator()->create_user();

        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid'       => $gradeitem->id,
            'userid'       => $user->id,
            'finalgrade'   => 15.0,
            'rawgrademax'  => $gradeitem->grademax,
            'rawgrademin'  => $gradeitem->grademin,
            'timecreated'  => 1000,
            'timemodified' => 1000,
        ]);

        $data = observer::module_note_data('21886', $cm, (int)$user->id);

        // Raw grade 15 (out of 20), not the 75% a percentage would give.
        $this->assertEqualsWithDelta(15.0, (float)$data->score, 0.001);
    }

    /**
     * module_note_data() returns null for an ungraded module, so the manual push
     * path rejects it: ungraded activities are never sent (the same invariant the
     * automatic observer enforces).
     *
     * @covers \local_imisbridge\observer::module_note_data
     * @return void
     */
    public function test_module_note_data_ungraded_returns_null(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        // A page activity has no grade item, so it must never produce a note.
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm   = get_coursemodule_from_id('', $page->cmid);

        $this->assertNull(observer::module_note_data('21886', $cm, 0));
    }

    /**
     * module_grade_detail() reports the grade item and the learner's gradebook
     * grade. A graded module with a recorded grade yields the percentage; with no
     * recorded gradebook grade it is still "graded" but score 0 and hasgrade false
     * (the exact shape behind a note that carries score 0 for a graded activity).
     *
     * @covers \local_imisbridge\observer::module_grade_detail
     * @return void
     */
    public function test_module_grade_detail_reads_gradebook(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $cm     = get_coursemodule_from_id('', $assign->cmid);
        $user   = $this->getDataGenerator()->create_user();

        // No gradebook grade yet: graded, but score 0 and hasgrade false.
        $empty = observer::module_grade_detail($cm, (int)$user->id);
        $this->assertTrue($empty->graded);
        $this->assertFalse($empty->hasgrade);
        $this->assertNull($empty->finalgrade);
        $this->assertSame(0.0, $empty->score);
        $this->assertNotNull($empty->itemid);

        // Record a gradebook grade of 75/100 and re-read: 75%.
        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid'       => $gradeitem->id,
            'userid'       => $user->id,
            'finalgrade'   => 75.0,
            'rawgrademax'  => $gradeitem->grademax,
            'rawgrademin'  => $gradeitem->grademin,
            'timecreated'  => 1000,
            'timemodified' => 1000,
        ]);

        $graded = observer::module_grade_detail($cm, (int)$user->id);
        $this->assertTrue($graded->hasgrade);
        $this->assertEqualsWithDelta(75.0, (float)$graded->finalgrade, 0.001);
        $this->assertEqualsWithDelta(75.0, (float)$graded->score, 0.001);
    }

    /**
     * module_grade_detail() reports an ungraded module (no grade item) as not
     * graded, so the manual test can say "ungraded" rather than a misleading 0.
     *
     * @covers \local_imisbridge\observer::module_grade_detail
     * @return void
     */
    public function test_module_grade_detail_ungraded(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page   = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm     = get_coursemodule_from_id('', $page->cmid);

        $detail = observer::module_grade_detail($cm, 0);
        $this->assertFalse($detail->graded);
        $this->assertFalse($detail->hasgrade);
        $this->assertNull($detail->itemid);
    }

    /**
     * course_score_detail() reports the course-total grade item and the learner's
     * gradebook grade: no recorded grade reads as score 0 / hasgrade false, a
     * recorded course total reads as its percentage.
     *
     * @covers \local_imisbridge\observer::course_score_detail
     * @return void
     */
    public function test_course_score_detail_reads_course_total(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        // No gradebook grade yet: score 0, hasgrade false, but the course-total item exists.
        $empty = observer::course_score_detail((int)$course->id, (int)$user->id);
        $this->assertNull($empty->finalgrade);
        $this->assertFalse($empty->hasgrade);
        $this->assertSame(0.0, $empty->score);
        $this->assertNotNull($empty->itemid);

        // Record a course total of 80 and re-read: 80%.
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

        $graded = observer::course_score_detail((int)$course->id, (int)$user->id);
        $this->assertTrue($graded->hasgrade);
        $this->assertEqualsWithDelta(80.0, (float)$graded->finalgrade, 0.001);
        $this->assertEqualsWithDelta(80.0, (float)$graded->score, 0.001);
    }

    /**
     * Store a learner's final grade for an activity directly (no gradebook events),
     * run the user_graded observer on it, and return the queued score-note tasks.
     *
     * The grade is written straight to grade_grades so that grade_update() cannot
     * dispatch the observer itself and claim the grade first; only the explicit
     * observer call below counts, which keeps the dedupe tests deterministic.
     *
     * @param string    $modname    The activity's module name.
     * @param \stdClass $instance   The activity instance (needs id).
     * @param \stdClass $user       The learner.
     * @param float     $finalgrade The grade to store.
     * @return push_score_note_task[]
     */
    private function observe_grade(string $modname, \stdClass $instance, \stdClass $user, float $finalgrade): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => $modname,
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
        ]);
        $existing = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $user->id]);
        if ($existing) {
            $existing->finalgrade   = $finalgrade;
            $existing->timemodified = time();
            $DB->update_record('grade_grades', $existing);
        } else {
            $DB->insert_record('grade_grades', (object)[
                'itemid'       => $item->id,
                'userid'       => $user->id,
                'finalgrade'   => $finalgrade,
                'rawgrademax'  => $item->grademax,
                'rawgrademin'  => $item->grademin,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id]);
        // The event factory reads the grade's item, so load it first.
        $grade->load_grade_item();
        observer::user_graded(\core\event\user_graded::create_from_grade($grade));

        return \core\task\manager::get_adhoc_tasks(push_score_note_task::class);
    }

    /**
     * Set up a configured site, a learner and a graded assignment, ticking the given
     * activity types for score push.
     *
     * @param string $ticked The push_score_modules value (comma-separated module names).
     * @return array [user, assignment instance]
     */
    private function score_push_fixture(string $ticked): array {
        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        set_config('push_score_modules', $ticked, 'local_imisbridge');

        $user   = $this->getDataGenerator()->create_user(['username' => 'member123']);
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        return [$user, $assign];
    }

    /**
     * The queued score-note task carrying the given score.
     *
     * @param push_score_note_task[] $tasks The queued tasks.
     * @param float                  $score The score to find.
     * @return push_score_note_task
     */
    private function task_with_score(array $tasks, float $score): push_score_note_task {
        foreach ($tasks as $task) {
            if (abs((float)$task->get_custom_data()->score - $score) < 0.001) {
                return $task;
            }
        }
        $this->fail("no queued score note with score {$score}");
    }

    /**
     * Grading an activity of a ticked type queues one score note naming the
     * activity, with the raw grade.
     *
     * @covers \local_imisbridge\observer::user_graded
     * @return void
     */
    public function test_user_graded_ticked_type_queues_score_note(): void {
        $this->resetAfterTest();
        [$user, $assign] = $this->score_push_fixture('assign');

        $tasks = $this->observe_grade('assign', $assign, $user, 80.0);

        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame('score', $data->notekind);
        $this->assertSame('member123', $data->imisid);
        $this->assertSame('assign', $data->modname);
        $this->assertEquals($assign->cmid, $data->cmid);
        $this->assertEqualsWithDelta(80.0, (float)$data->score, 0.001);
        $this->assertSame('COMPLETE', $data->status, 'no pass mark set, so COMPLETE');
    }

    /**
     * Grading an activity whose type is not ticked queues nothing; neither does
     * an empty checklist (the default).
     *
     * @covers \local_imisbridge\observer::user_graded
     * @return void
     */
    public function test_user_graded_unticked_type_ignored(): void {
        $this->resetAfterTest();
        [$user, $assign] = $this->score_push_fixture('workshop');

        $this->assertCount(0, $this->observe_grade('assign', $assign, $user, 80.0));

        set_config('push_score_modules', '', 'local_imisbridge');
        $this->assertCount(0, $this->observe_grade('assign', $assign, $user, 85.0));
    }

    /**
     * A regrade to the same value is not sent again; a changed grade is.
     *
     * @covers \local_imisbridge\observer::user_graded
     * @covers \local_imisbridge\observer::claim_score_push
     * @return void
     */
    public function test_user_graded_sends_only_changed_grades(): void {
        $this->resetAfterTest();
        [$user, $assign] = $this->score_push_fixture('assign');

        $this->assertCount(1, $this->observe_grade('assign', $assign, $user, 80.0));
        $this->assertCount(1, $this->observe_grade('assign', $assign, $user, 80.0), 'unchanged grade not re-sent');
        $this->assertCount(2, $this->observe_grade('assign', $assign, $user, 90.0), 'changed grade sent');
    }

    /**
     * The score note's status is PASS or FAIL against the activity's pass mark.
     *
     * @covers \local_imisbridge\observer::score_note_data
     * @return void
     */
    public function test_score_note_data_uses_pass_mark(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        [$user, $assign] = $this->score_push_fixture('assign');

        $item = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'itemnumber'   => 0,
        ]);
        $item->gradepass = 50;
        $item->update();

        $tasks = $this->observe_grade('assign', $assign, $user, 40.0);
        $this->assertSame('FAIL', $this->task_with_score($tasks, 40.0)->get_custom_data()->status);

        $tasks = $this->observe_grade('assign', $assign, $user, 70.0);
        $this->assertSame('PASS', $this->task_with_score($tasks, 70.0)->get_custom_data()->status);
    }

    /**
     * Quiz is never pushed by the checklist (it has its own push on submission),
     * and the checklist offers gradable activities only.
     *
     * @covers \local_imisbridge\util::score_push_modules
     * @covers \local_imisbridge\util::gradable_activity_choices
     * @return void
     */
    public function test_score_push_modules_excludes_quiz(): void {
        $this->resetAfterTest();

        set_config('push_score_modules', 'quiz,assign', 'local_imisbridge');
        $this->assertSame(['assign'], util::score_push_modules());

        $choices = util::gradable_activity_choices();
        $this->assertArrayHasKey('assign', $choices);
        $this->assertArrayNotHasKey('quiz', $choices);
        $this->assertArrayNotHasKey('label', $choices, 'ungraded activities are not offered');
    }

    /**
     * A queued score superseded by a later grade is skipped, not sent.
     *
     * @covers \local_imisbridge\task\push_score_note_task
     * @return void
     */
    public function test_score_note_task_skips_superseded_grade(): void {
        $this->resetAfterTest();
        [$user, $assign] = $this->score_push_fixture('assign');

        // Two grades queue two tasks; the table now holds the later one (90).
        $this->observe_grade('assign', $assign, $user, 80.0);
        $tasks = $this->observe_grade('assign', $assign, $user, 90.0);
        $this->assertCount(2, $tasks);
        $first = $this->task_with_score($tasks, 80.0);

        // The 80 task finds 90 recorded and returns before any call to iMIS (a call
        // would fail here, as the WSDL is not reachable).
        $this->expectOutputRegex('/superseded by a later grade/');
        $first->execute();
    }
}
