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
 * Tests for the shared enrolment helper.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Unit tests for {@see enrolment}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\enrolment
 */
final class enrolment_test extends \advanced_testcase {
    /** @var \stdClass The course, whose manual enrolments send a welcome message. */
    private \stdClass $course;

    /**
     * A course whose manual enrolment method sends the course welcome message.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/enrollib.php');
        $this->course = $this->getDataGenerator()->create_course();
        $DB->set_field(
            'enrol',
            'customint1',
            ENROL_SEND_EMAIL_FROM_NOREPLY,
            ['courseid' => $this->course->id, 'enrol' => 'manual']
        );
    }

    /**
     * The course welcome messages sent so far.
     *
     * @param \phpunit_message_sink $sink The message sink.
     * @return array
     */
    private function welcomes(\phpunit_message_sink $sink): array {
        return $sink->get_messages_by_component_and_type('moodle', 'enrolcoursewelcomemessage');
    }

    /**
     * A new enrolment sends one welcome message; reactivating it after a suspension
     * restores access and the Student role without sending another.
     *
     * @return void
     */
    public function test_reactivation_sends_no_second_welcome(): void {
        global $DB;
        $user      = $this->getDataGenerator()->create_user();
        $context   = \context_course::instance($this->course->id);
        $studentid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $sink      = $this->redirectMessages();

        $this->assertSame(enrolment::ENROLLED, enrolment::ensure_active((int)$user->id, (int)$this->course->id));
        $this->assertCount(1, $this->welcomes($sink));

        enrolment::suspend((int)$user->id, (int)$this->course->id);
        $this->assertFalse(is_enrolled($context, $user->id, '', true));
        $this->assertFalse(user_has_role_assignment($user->id, $studentid, $context->id));

        $this->assertSame(enrolment::REACTIVATED, enrolment::ensure_active((int)$user->id, (int)$this->course->id));
        $this->assertTrue(is_enrolled($context, $user->id, '', true));
        $this->assertTrue(user_has_role_assignment($user->id, $studentid, $context->id));
        $this->assertCount(1, $this->welcomes($sink));

        // A learner already active is left alone, so repeat runs send nothing either.
        $this->assertSame(enrolment::ALREADY, enrolment::ensure_active((int)$user->id, (int)$this->course->id));
        $this->assertCount(1, $this->welcomes($sink));
    }

    /**
     * A dry run reports the reactivation but changes nothing.
     *
     * @return void
     */
    public function test_dry_run_reactivation_changes_nothing(): void {
        $user    = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($this->course->id);
        enrolment::ensure_active((int)$user->id, (int)$this->course->id);
        enrolment::suspend((int)$user->id, (int)$this->course->id);

        $result = enrolment::ensure_active((int)$user->id, (int)$this->course->id, true);

        $this->assertSame(enrolment::REACTIVATED, $result);
        $this->assertFalse(is_enrolled($context, $user->id, '', true));
    }
}
