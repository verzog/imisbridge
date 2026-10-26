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
 * Tests for the results back-fill.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

use local_imisbridge\task\push_activity_task;
use local_imisbridge\task\push_score_note_task;

/**
 * Unit tests for {@see backfill}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\backfill
 */
final class backfill_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /**
     * A configured site (members matched on ID number) and a course.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');
        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        set_config('imis_id_source', 'idnumber', 'local_imisbridge');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * A learner who completed the course at the given time.
     *
     * @param string $imisid    The iMIS ID ('' for none).
     * @param int    $completed The completion time.
     * @return \stdClass The user.
     */
    private function completer(string $imisid, int $completed): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['idnumber' => $imisid]);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        $DB->insert_record('course_completions', (object)[
            'userid'        => $user->id,
            'course'        => $this->course->id,
            'timeenrolled'  => $completed - DAYSECS,
            'timestarted'   => 0,
            'timecompleted' => $completed,
            'reaggregate'   => 0,
        ]);
        return $user;
    }

    /**
     * A dry run queues nothing; --execute queues one completion per completer with the
     * real completion date, skipping a learner without an iMIS ID.
     *
     * @return void
     */
    public function test_completions(): void {
        $completed = mktime(12, 0, 0, 8, 14, 2026);
        $this->completer('20016', $completed);
        $this->completer('20017', $completed);
        $this->completer('', $completed);

        $report = (new backfill((int)$this->course->id, false))->completions();
        $this->assertSame(2, $report['found']);
        $this->assertSame(2, $report['queued']);
        $this->assertSame(1, $report['skipped']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(push_activity_task::class));

        $report = (new backfill((int)$this->course->id, true))->completions();
        $tasks = \core\task\manager::get_adhoc_tasks(push_activity_task::class);
        $this->assertCount(2, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame('push_completions', $data->toggle);
        $this->assertSame('2026-08-14', $data->completiondate);
        $this->assertSame('2026-08-13', $data->startdate);
        $this->assertSame((string)$this->course->id, $data->coursenum);
    }

    /**
     * With the Digits only iMIS ID format, an anonymised account is skipped with a reason.
     *
     * @return void
     */
    public function test_skips_ids_not_in_imis_format(): void {
        $this->completer('20016', time());
        $this->completer('anon47', time());
        set_config('imis_id_format', 'digits', 'local_imisbridge');

        $report = (new backfill((int)$this->course->id, true))->completions();

        $this->assertSame(1, $report['queued']);
        $this->assertSame(1, $report['skipped']);
        $this->assertStringContainsString("'anon47' is not in the iMIS ID format", implode("\n", $report['messages']));
        $tasks = \core\task\manager::get_adhoc_tasks(push_activity_task::class);
        $this->assertSame('20016', reset($tasks)->get_custom_data()->imisid);
    }

    /**
     * --member limits the back-fill to one learner, and a push course id override applies.
     *
     * @return void
     */
    public function test_member_filter_and_override(): void {
        $this->completer('20016', time());
        $this->completer('20017', time());
        set_config('push_course_overrides', "{$this->course->id}=2152", 'local_imisbridge');

        $report = (new backfill((int)$this->course->id, true, '20017'))->completions();

        $this->assertSame(1, $report['queued']);
        $tasks = \core\task\manager::get_adhoc_tasks(push_activity_task::class);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame('20017', $data->imisid);
        $this->assertSame('2152', $data->coursenum);
    }

    /**
     * With Push completions off, the back-fill queues nothing.
     *
     * @return void
     */
    public function test_completions_respect_toggle(): void {
        $this->completer('20016', time());
        set_config('push_completions', '0', 'local_imisbridge');

        $report = (new backfill((int)$this->course->id, true))->completions();

        $this->assertTrue($report['aborted']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(push_activity_task::class));
    }

    /**
     * Scores of a ticked type are queued once; a second run finds them already sent.
     *
     * @return void
     */
    public function test_scores_sent_once(): void {
        global $DB;
        set_config('push_score_modules', 'assign', 'local_imisbridge');
        $user   = $this->getDataGenerator()->create_user(['idnumber' => '20016']);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'grade' => 100]);
        $item   = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'itemnumber'   => 0,
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid'       => $item->id,
            'userid'       => $user->id,
            'finalgrade'   => 72.0,
            'rawgrademax'  => $item->grademax,
            'rawgrademin'  => $item->grademin,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $dry = (new backfill((int)$this->course->id, false))->scores();
        $this->assertSame(1, $dry['queued']);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(push_score_note_task::class));

        $first = (new backfill((int)$this->course->id, true))->scores();
        $this->assertSame(1, $first['queued']);
        $tasks = \core\task\manager::get_adhoc_tasks(push_score_note_task::class);
        $this->assertCount(1, $tasks);
        $this->assertEqualsWithDelta(72.0, (float)reset($tasks)->get_custom_data()->score, 0.001);

        $second = (new backfill((int)$this->course->id, true))->scores();
        $this->assertSame(0, $second['queued']);
        $this->assertSame(1, $second['skipped']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(push_score_note_task::class));
    }

    /**
     * With no activity types ticked, the score back-fill queues nothing.
     *
     * @return void
     */
    public function test_scores_need_ticked_types(): void {
        $report = (new backfill((int)$this->course->id, true))->scores();

        $this->assertTrue($report['aborted']);
    }
}
