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
        $this->resetAfterTest();

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');

        $user   = $this->getDataGenerator()->create_user(['username' => 'member123']);
        // An idnumber/shortname that must NOT be used as the productID.
        $course = $this->getDataGenerator()->create_course([
            'idnumber'  => 'IMIS-PROD-99',
            'shortname' => 'SHORT99',
        ]);

        $event = \core\event\course_completed::create([
            'objectid'      => 1,
            'relateduserid' => $user->id,
            'courseid'      => $course->id,
            'context'       => \context_course::instance($course->id),
        ]);
        observer::course_completed($event);

        $tasks = \core\task\manager::get_adhoc_tasks(push_activity_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((string)$course->id, reset($tasks)->get_custom_data()->coursenum);
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
}
