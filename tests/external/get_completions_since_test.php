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
 * Tests for the local_membershipbridge bulk completions web service.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\external;

use core_external\external_api;

/**
 * Unit tests for {@see get_completions_since}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\external\get_completions_since
 */
final class get_completions_since_test extends \advanced_testcase {
    /**
     * Reset the database between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Call the web service and validate the declared return structure.
     *
     * @param int $since   The since timestamp.
     * @param int $limit   The page size.
     * @param int $afterid The paging cursor.
     * @return array The cleaned return value.
     */
    private function call(int $since, int $limit = 500, int $afterid = 0): array {
        $result = get_completions_since::execute($since, $limit, $afterid);
        return external_api::clean_returnvalue(get_completions_since::execute_returns(), $result);
    }

    /**
     * Insert a course completion row with a fixed completion time.
     *
     * @param int $userid        The Moodle user id.
     * @param int $courseid      The Moodle course id.
     * @param int $timecompleted The completion timestamp.
     * @return int The new completion id.
     */
    private function make_completion(int $userid, int $courseid, int $timecompleted): int {
        global $DB;
        return $DB->insert_record('course_completions', (object)[
            'userid'       => $userid,
            'course'       => $courseid,
            'timeenrolled' => 0,
            'timestarted'  => 0,
            'timecompleted' => $timecompleted,
            'reaggregate'  => 0,
        ]);
    }

    /**
     * Set a course-total grade for a user with a fixed modified time.
     *
     * @param int   $userid       The Moodle user id.
     * @param int   $courseid     The Moodle course id.
     * @param float $finalgrade   The final grade value.
     * @param int   $timemodified The grade modified timestamp.
     * @return void
     */
    private function make_course_grade(int $userid, int $courseid, float $finalgrade, int $timemodified): void {
        global $DB;
        $gradeitem = \grade_item::fetch_course_item($courseid);
        $DB->insert_record('grade_grades', (object)[
            'itemid'      => $gradeitem->id,
            'userid'      => $userid,
            'finalgrade'  => $finalgrade,
            'rawgrademax' => $gradeitem->grademax,
            'rawgrademin' => $gradeitem->grademin,
            'timecreated' => $timemodified,
            'timemodified' => $timemodified,
        ]);
    }

    /**
     * A completion with no grade returns null grade fields.
     *
     * @return void
     */
    public function test_completion_only(): void {
        $this->setAdminUser();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->make_completion((int)$user->id, (int)$course->id, 1000);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $record = $result['records'][0];
        $this->assertSame((int)$user->id, $record['userid']);
        $this->assertSame($user->username, $record['imisid']);
        $this->assertSame((int)$course->id, $record['courseid']);
        $this->assertSame(1000, $record['timecompleted']);
        $this->assertNull($record['graderaw']);
        $this->assertNull($record['grademax']);
        $this->assertNull($record['gradepercentage']);
        $this->assertNull($record['gradeletter']);
        $this->assertSame(1000, $record['timemodified']);
        $this->assertFalse($result['hasmore']);
    }

    /**
     * A completion with a course grade returns populated grade fields.
     *
     * @return void
     */
    public function test_completion_with_grade(): void {
        $this->setAdminUser();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->make_completion((int)$user->id, (int)$course->id, 1000);
        $this->make_course_grade((int)$user->id, (int)$course->id, 80.0, 1000);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $record = $result['records'][0];
        $this->assertEqualsWithDelta(80.0, $record['graderaw'], 0.001);
        $this->assertEqualsWithDelta(100.0, $record['grademax'], 0.001);
        $this->assertEqualsWithDelta(80.0, $record['gradepercentage'], 0.001);
        $this->assertNotNull($record['gradeletter']);
        $this->assertIsString($record['gradeletter']);
        $this->assertSame(1000, $record['timemodified']);
    }

    /**
     * A record qualifies when only the grade changed on or after since,
     * and timemodified reflects the later grade time.
     *
     * @return void
     */
    public function test_grade_change_qualifies(): void {
        $this->setAdminUser();
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        // Completion is old; only the grade is recent.
        $this->make_completion((int)$user->id, (int)$course->id, 100);
        $this->make_course_grade((int)$user->id, (int)$course->id, 55.0, 1000);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $record = $result['records'][0];
        $this->assertEqualsWithDelta(55.0, $record['graderaw'], 0.001);
        $this->assertSame(1000, $record['timemodified']);
    }

    /**
     * Records unchanged before since are excluded; recent ones are returned.
     *
     * @return void
     */
    public function test_since_filter(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $olduser = $this->getDataGenerator()->create_user();
        $this->make_completion((int)$olduser->id, (int)$course->id, 100);
        $this->make_course_grade((int)$olduser->id, (int)$course->id, 70.0, 100);

        $newuser = $this->getDataGenerator()->create_user();
        $this->make_completion((int)$newuser->id, (int)$course->id, 2000);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $this->assertSame((int)$newuser->id, $result['records'][0]['userid']);
    }

    /**
     * Paging returns stable, non-overlapping pages via the afterid cursor.
     *
     * @return void
     */
    public function test_paging(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->make_completion((int)$user->id, (int)$course->id, 1000 + $i);
            $ids[] = (int)$user->id;
        }

        $page1 = $this->call(500, 2, 0);
        $this->assertCount(2, $page1['records']);
        $this->assertTrue($page1['hasmore']);

        $page2 = $this->call(500, 2, $page1['nextafterid']);
        $this->assertCount(1, $page2['records']);
        $this->assertFalse($page2['hasmore']);

        $seen = array_merge(
            array_column($page1['records'], 'userid'),
            array_column($page2['records'], 'userid')
        );
        sort($seen);
        sort($ids);
        $this->assertSame($ids, $seen);
    }

    /**
     * Deleted and suspended users are excluded from the export.
     *
     * @return void
     */
    public function test_deleted_and_suspended_excluded(): void {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $active = $this->getDataGenerator()->create_user();
        $this->make_completion((int)$active->id, (int)$course->id, 1000);

        $deleted = $this->getDataGenerator()->create_user();
        $this->make_completion((int)$deleted->id, (int)$course->id, 1000);
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        $suspended = $this->getDataGenerator()->create_user();
        $this->make_completion((int)$suspended->id, (int)$course->id, 1000);
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $this->assertSame((int)$active->id, $result['records'][0]['userid']);
    }

    /**
     * imisid is resolved from the configured source field (here, email).
     *
     * @return void
     */
    public function test_imisid_respects_source(): void {
        $this->setAdminUser();
        set_config('imis_id_source', 'email', 'local_membershipbridge');
        $user   = $this->getDataGenerator()->create_user(['email' => 'member@example.org']);
        $course = $this->getDataGenerator()->create_course();
        $this->make_completion((int)$user->id, (int)$course->id, 1000);

        $result = $this->call(500);

        $this->assertCount(1, $result['records']);
        $this->assertSame('member@example.org', $result['records'][0]['imisid']);
    }

    /**
     * A caller without the capability is rejected.
     *
     * @return void
     */
    public function test_missing_capability_rejected(): void {
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->make_completion((int)$user->id, (int)$course->id, 1000);

        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_completions_since::execute(500);
    }
}
