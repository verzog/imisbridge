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
 * Tests for the local_membershipbridge cancel-enrolment web service.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\external;

use core_external\external_api;

/**
 * Unit tests for {@see cancel_enrolment}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\external\cancel_enrolment
 */
final class cancel_enrolment_test extends \advanced_testcase {
    /**
     * Reset the database and run as admin (who holds the cancel capability).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Call the web service and validate the declared return structure.
     *
     * @param array $enrolments The entries to cancel.
     * @return array The cleaned return value.
     */
    private function call(array $enrolments): array {
        $result = cancel_enrolment::execute($enrolments);
        return external_api::clean_returnvalue(cancel_enrolment::execute_returns(), $result);
    }

    /**
     * Cancelling suspends the enrolment and removes the role, but keeps the
     * enrolment, its grade and its completion — no data is lost.
     *
     * @return void
     */
    public function test_cancel_suspends_and_unassigns_but_keeps_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentroleid);

        $context = \context_course::instance($course->id);

        // Give the learner a grade so we can prove it survives the cancellation.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id]);
        $DB->insert_record('grade_grades', (object)[
            'itemid'       => $gradeitem->id,
            'userid'       => $user->id,
            'finalgrade'   => 42.0,
            'rawgrademax'  => $gradeitem->grademax,
            'rawgrademin'  => $gradeitem->grademin,
            'timecreated'  => 1000,
            'timemodified' => 1000,
        ]);

        // Preconditions: actively enrolled, has the Student role.
        $this->assertTrue(is_enrolled($context, $user, '', true));
        $this->assertTrue(user_has_role_assignment($user->id, $studentroleid, $context->id));

        $result = $this->call([['userid' => $user->id, 'courseid' => $course->id]]);

        // Reported outcome.
        $this->assertCount(1, $result['results']);
        $this->assertSame('cancelled', $result['results'][0]['status']);
        $this->assertSame(1, $result['results'][0]['enrolmentssuspended']);
        $this->assertTrue($result['results'][0]['rolesunassigned']);
        $this->assertEmpty($result['warnings']);

        // Still enrolled (row kept) but no longer active, and no Student role.
        $this->assertTrue(is_enrolled($context, $user), 'enrolment must be kept');
        $this->assertFalse(is_enrolled($context, $user, '', true), 'enrolment must be suspended');
        $this->assertFalse(user_has_role_assignment($user->id, $studentroleid, $context->id));

        // The grade — the data we must not lose — is still there.
        $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $user->id]);
        $this->assertNotEmpty($grade);
        $this->assertEqualsWithDelta(42.0, (float)$grade->finalgrade, 0.001);
    }

    /**
     * Cancelling removes only the role the enrolment granted; a manually assigned
     * role (an admin override) in the same course is preserved.
     *
     * @return void
     */
    public function test_cancel_preserves_manual_role(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $user    = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        // Enrolled as Student (role assigned via the enrol component) plus a manual
        // Teacher override (component '').
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentroleid);
        role_assign($teacherroleid, $user->id, $context->id);

        $this->call([['userid' => $user->id, 'courseid' => $course->id]]);

        // The enrol-granted Student role is gone; the manual Teacher override stays.
        $this->assertFalse(user_has_role_assignment($user->id, $studentroleid, $context->id));
        $this->assertTrue(user_has_role_assignment($user->id, $teacherroleid, $context->id));
        // Still enrolled, but suspended.
        $this->assertTrue(is_enrolled($context, $user));
        $this->assertFalse(is_enrolled($context, $user, '', true));
    }

    /**
     * A user who is not enrolled in the course yields a clean "notenrolled"
     * outcome and changes nothing.
     *
     * @return void
     */
    public function test_cancel_user_not_enrolled(): void {
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();

        $result = $this->call([['userid' => $user->id, 'courseid' => $course->id]]);

        $this->assertSame('notenrolled', $result['results'][0]['status']);
        $this->assertSame(0, $result['results'][0]['enrolmentssuspended']);
        $this->assertFalse(is_enrolled(\context_course::instance($course->id), $user));
    }

    /**
     * A missing course is reported as a warning, not a fatal error, and never
     * touches anything.
     *
     * @return void
     */
    public function test_cancel_course_not_found(): void {
        $user = $this->getDataGenerator()->create_user();

        $result = $this->call([['userid' => $user->id, 'courseid' => 999999]]);

        $this->assertSame('coursenotfound', $result['results'][0]['status']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('coursenotfound', $result['warnings'][0]['warningcode']);
    }
}
