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
 * Tests for the registration sync engine.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\registration;

use local_imisbridge\enrolment;
use local_imisbridge\tutorial\course_resolver;

/**
 * Unit tests for {@see registration_sync} and {@see rest_reader::to_row()}.
 *
 * A fake reader and course resolver feed fixed rows, so the tests assert only the
 * engine's decisions and the Moodle changes it makes.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\registration\registration_sync
 */
final class registration_sync_test extends \advanced_testcase {
    /** @var \stdClass The course the test event maps to. */
    private \stdClass $course;

    /**
     * Reset the database, match members on ID number (as on the live site), and create
     * the course the event code ATS_BRIDGE maps to.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('imis_id_source', 'idnumber', 'local_imisbridge');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * An engine over fixed rows, with ATS_BRIDGE mapped to the test course.
     *
     * @param registration_row[] $rows    The rows the reader returns.
     * @param array              $options Engine options (merged over the defaults).
     * @return registration_sync
     */
    private function engine(array $rows, array $options = []): registration_sync {
        $reader = new class ($rows) implements reader {
            /** @var registration_row[] The fixed rows. */
            private array $rows;

            /**
             * Store the fixed rows.
             *
             * @param registration_row[] $rows The rows.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * Return the fixed rows.
             *
             * @return registration_row[]
             */
            public function fetch_rows(): array {
                return $this->rows;
            }
        };
        $resolver = new class ((int)$this->course->id) implements course_resolver {
            /** @var int The course ATS_BRIDGE maps to. */
            private int $courseid;

            /**
             * Store the mapped course.
             *
             * @param int $courseid The course id.
             */
            public function __construct(int $courseid) {
                $this->courseid = $courseid;
            }

            /**
             * Map ATS_BRIDGE to the course; anything else is unmapped.
             *
             * @param string $code The event code.
             * @return int|null
             */
            public function resolve_courseid(string $code): ?int {
                return ($code === 'ATS_BRIDGE') ? $this->courseid : null;
            }
        };
        $options += [
            'createusers' => true,
            'trace'       => static function (): void {
                // Swallow progress output in tests.
                return;
            },
        ];
        return new registration_sync($reader, $resolver, $options);
    }

    /**
     * A row for ATS_BRIDGE.
     *
     * @param string   $imisid  The iMIS ID.
     * @param string   $status  The status ('' active, 'C' cancelled).
     * @param bool     $active  The source's active flag.
     * @param int|null $enddate The event end date.
     * @return registration_row
     */
    private function row(string $imisid, string $status = '', bool $active = true, ?int $enddate = null): registration_row {
        return new registration_row(
            $imisid,
            'ATS_BRIDGE',
            $status,
            "2041.00-{$imisid}",
            $active,
            $enddate,
            'First' . $imisid,
            'Last' . $imisid,
            "member{$imisid}@example.com"
        );
    }

    /**
     * Create a user whose ID number is the iMIS ID, optionally enrolled in the course.
     *
     * @param string $imisid The iMIS ID.
     * @param bool   $enrol  Whether to enrol them.
     * @return \stdClass The user.
     */
    private function member(string $imisid, bool $enrol = false): \stdClass {
        $user = $this->getDataGenerator()->create_user(['idnumber' => $imisid]);
        if ($enrol) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        }
        return $user;
    }

    /**
     * Whether a user has an active enrolment in the test course.
     *
     * @param int $userid The user id.
     * @return bool
     */
    private function active(int $userid): bool {
        return is_enrolled(\context_course::instance($this->course->id), $userid, '', true);
    }

    /**
     * An active registration enrols the member with the Student role.
     *
     * @return void
     */
    public function test_enrols_active_registrant(): void {
        global $DB;
        $user = $this->member('20018');

        $report = $this->engine([$this->row('20018')])->run(registration_sync::MODE_ENROL);

        $this->assertSame(1, $report['enrolled']);
        $this->assertTrue($this->active((int)$user->id));
        $student = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->assertTrue(user_has_role_assignment($user->id, $student, \context_course::instance($this->course->id)->id));
    }

    /**
     * A dry run reports the enrolment and the account it would create, and changes nothing.
     *
     * @return void
     */
    public function test_dry_run_changes_nothing(): void {
        global $DB;
        $user = $this->member('20018');

        $report = $this->engine([$this->row('20018'), $this->row('20040')], ['dryrun' => true])
            ->run(registration_sync::MODE_ENROL);

        $this->assertSame(2, $report['enrolled']);
        $this->assertSame(1, $report['accountscreated']);
        $this->assertFalse($this->active((int)$user->id));
        $this->assertFalse($DB->record_exists('user', ['idnumber' => '20040']), 'dry run must not create the account');
    }

    /**
     * A registrant with no account gets one (username and ID number = iMIS ID, name and
     * email from the row), then is enrolled.
     *
     * @return void
     */
    public function test_creates_account_then_enrols(): void {
        global $DB;

        $report = $this->engine([$this->row('20040')])->run(registration_sync::MODE_ENROL);

        $user = $DB->get_record('user', ['idnumber' => '20040', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('20040', $user->username);
        $this->assertSame('First20040', $user->firstname);
        $this->assertSame('member20040@example.com', $user->email);
        $this->assertSame(1, $report['accountscreated']);
        $this->assertTrue($this->active((int)$user->id));
    }

    /**
     * With account creation off, a registrant with no account is skipped.
     *
     * @return void
     */
    public function test_no_account_creation_when_off(): void {
        global $DB;

        $report = $this->engine([$this->row('20040')], ['createusers' => false])->run(registration_sync::MODE_ENROL);

        $this->assertFalse($DB->record_exists('user', ['idnumber' => '20040']));
        $this->assertSame(0, $report['enrolled']);
        $this->assertSame(1, $report['rowsskipped']);
    }

    /**
     * A member whose registrations are all cancelled is suspended, keeping the enrolment row.
     *
     * @return void
     */
    public function test_suspends_member_with_only_cancelled_registrations(): void {
        global $DB;
        $user = $this->member('20016', true);

        $report = $this->engine([$this->row('20016', 'C')])->run(registration_sync::MODE_CANCEL);

        $this->assertSame(1, $report['suspended']);
        $this->assertFalse($this->active((int)$user->id));
        $this->assertTrue(
            $DB->record_exists('user_enrolments', ['userid' => $user->id, 'status' => ENROL_USER_SUSPENDED]),
            'the enrolment is suspended, not deleted'
        );
    }

    /**
     * One active registration outweighs a cancelled one for the same member and course.
     *
     * @return void
     */
    public function test_active_registration_wins_over_cancelled(): void {
        $user = $this->member('20018', true);
        $rows = [$this->row('20018', 'C'), $this->row('20018')];

        $report = $this->engine($rows)->run(registration_sync::MODE_CANCEL);
        $this->assertSame(0, $report['suspended']);
        $this->assertTrue($this->active((int)$user->id));

        $report = $this->engine($rows)->run(registration_sync::MODE_ENROL);
        $this->assertSame(1, $report['alreadyactive']);
    }

    /**
     * A suspended member with an active registration is reactivated.
     *
     * @return void
     */
    public function test_reactivates_suspended_member(): void {
        $user = $this->member('20018', true);
        enrolment::suspend((int)$user->id, (int)$this->course->id);
        $this->assertFalse($this->active((int)$user->id));

        $report = $this->engine([$this->row('20018')])->run(registration_sync::MODE_ENROL);

        $this->assertSame(1, $report['reactivated']);
        $this->assertTrue($this->active((int)$user->id));
    }

    /**
     * Rows the source flags inactive, and rows for events that ended before the
     * look-back window, are ignored.
     *
     * @return void
     */
    public function test_ignores_inactive_and_old_rows(): void {
        $inactive = $this->member('20018');
        $old      = $this->member('20040');
        $now      = time();
        $rows = [
            $this->row('20018', '', false),
            $this->row('20040', '', true, $now - 200 * DAYSECS),
        ];

        $report = $this->engine($rows, ['lookbackdays' => 90, 'now' => $now])->run(registration_sync::MODE_ENROL);

        $this->assertFalse($this->active((int)$inactive->id));
        $this->assertFalse($this->active((int)$old->id));
        $this->assertSame(1, $report['rowsskipped']);
        $this->assertSame(1, $report['rowsoutofwindow']);
    }

    /**
     * With no look-back (0, as the one-member manual override uses), a cancellation on
     * an event that ended long ago is still applied, and the report names the window.
     *
     * @return void
     */
    public function test_no_lookback_applies_old_events(): void {
        $user = $this->member('20016', true);
        $now  = time();
        $rows = [$this->row('20016', 'C', true, $now - 200 * DAYSECS)];

        $report = $this->engine($rows, ['lookbackdays' => 7, 'now' => $now])->run(registration_sync::MODE_CANCEL);
        $this->assertSame(7, $report['lookbackdays']);
        $this->assertSame(1, $report['rowsoutofwindow']);
        $this->assertTrue($this->active((int)$user->id));

        $report = $this->engine($rows, ['lookbackdays' => 0, 'now' => $now])->run(registration_sync::MODE_CANCEL);
        $this->assertSame(0, $report['lookbackdays']);
        $this->assertSame(1, $report['suspended']);
        $this->assertFalse($this->active((int)$user->id));
    }

    /**
     * A member with no rows is left alone, even when others are cancelled.
     *
     * @return void
     */
    public function test_member_without_rows_is_untouched(): void {
        $kept = $this->member('20007', true);
        $this->member('20016', true);

        $this->engine([$this->row('20016', 'C')])->run(registration_sync::MODE_CANCEL);

        $this->assertTrue($this->active((int)$kept->id));
    }

    /**
     * An empty read makes no changes at all.
     *
     * @return void
     */
    public function test_empty_read_aborts(): void {
        $report = $this->engine([])->run(registration_sync::MODE_CANCEL);

        $this->assertTrue($report['aborted']);
        $this->assertSame(0, $report['suspended']);
    }

    /**
     * A run for one member touches only that member.
     *
     * @return void
     */
    public function test_contact_filter_limits_to_one_member(): void {
        $one = $this->member('20018');
        $two = $this->member('20040');

        $this->engine([$this->row('20018'), $this->row('20040')])->run(registration_sync::MODE_ENROL, '20018');

        $this->assertTrue($this->active((int)$one->id));
        $this->assertFalse($this->active((int)$two->id));
    }

    /**
     * Registrations for an event with no mapped course are skipped.
     *
     * @return void
     */
    public function test_unmapped_event_is_skipped(): void {
        $user = $this->member('20018');
        $row  = new registration_row('20018', 'UNMAPPED', '', 'k', true);

        $report = $this->engine([$row])->run(registration_sync::MODE_ENROL);

        $this->assertSame(1, $report['rowsskipped']);
        $this->assertFalse($this->active((int)$user->id));
    }

    /**
     * When a run would suspend most of a course, its suspensions are skipped.
     *
     * @return void
     */
    public function test_suspension_guard(): void {
        $rows = [];
        $users = [];
        for ($i = 1; $i <= 10; $i++) {
            $imisid  = (string)(30000 + $i);
            $users[] = $this->member($imisid, true);
            $rows[]  = $this->row($imisid, 'C');
        }

        $report = $this->engine($rows, ['guardpct' => 50])->run(registration_sync::MODE_CANCEL);

        $this->assertSame(1, $report['guardskipped']);
        $this->assertSame(0, $report['suspended']);
        foreach ($users as $user) {
            $this->assertTrue($this->active((int)$user->id));
        }
    }

    /**
     * A status that is neither blank nor cancelled grants nothing and never suspends,
     * even alongside a cancelled registration.
     *
     * @return void
     */
    public function test_unrecognised_status_neither_enrols_nor_suspends(): void {
        $newcomer = $this->member('20018');
        $enrolled = $this->member('20016', true);

        $report = $this->engine([$this->row('20018', 'P')])->run(registration_sync::MODE_ENROL);
        $this->assertSame(0, $report['enrolled']);
        $this->assertSame(1, $report['rowsskipped']);
        $this->assertFalse($this->active((int)$newcomer->id));

        $report = $this->engine([$this->row('20016', 'C'), $this->row('20016', 'P')])->run(registration_sync::MODE_CANCEL);
        $this->assertSame(0, $report['suspended']);
        $this->assertTrue($this->active((int)$enrolled->id));
    }

    /**
     * Cancellation also suspends an enrolment that only starts in the future.
     *
     * @return void
     */
    public function test_suspends_future_dated_enrolment(): void {
        global $DB;
        $user = $this->member('20016');
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student', 'manual', time() + 7 * DAYSECS);
        $this->assertFalse($this->active((int)$user->id), 'not active until it starts');

        $report = $this->engine([$this->row('20016', 'C')])->run(registration_sync::MODE_CANCEL);

        $this->assertSame(1, $report['suspended']);
        $this->assertFalse($DB->record_exists('user_enrolments', ['userid' => $user->id, 'status' => ENROL_USER_ACTIVE]));
    }

    /**
     * Without an enabled manual enrolment method, no account is created (or reported as
     * would-be created in a dry run) for a registrant who could not then be enrolled.
     *
     * @return void
     */
    public function test_no_account_without_manual_enrolment_method(): void {
        global $DB;
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $this->course->id, 'enrol' => 'manual']);

        foreach ([true, false] as $dryrun) {
            $report = $this->engine([$this->row('20040')], ['dryrun' => $dryrun])->run(registration_sync::MODE_ENROL);

            $this->assertSame(0, $report['accountscreated']);
            $this->assertSame(0, $report['enrolled']);
            $this->assertSame(1, $report['rowsskipped']);
            $this->assertFalse($DB->record_exists('user', ['idnumber' => '20040']));
        }
    }

    /**
     * With the iMIS ID source set to username, a remote MNet account with that username
     * is not taken for the member.
     *
     * @covers \local_imisbridge\util::find_user_by_imis_id
     * @return void
     */
    public function test_username_lookup_ignores_remote_mnet_accounts(): void {
        global $CFG;
        set_config('imis_id_source', 'username', 'local_imisbridge');
        $this->getDataGenerator()->create_user(['username' => '20050', 'mnethostid' => $CFG->mnet_localhost_id + 1]);

        $this->assertNull(\local_imisbridge\util::find_user_by_imis_id('20050'));

        $local = $this->getDataGenerator()->create_user(['username' => '20050']);
        $this->assertSame((int)$local->id, (int)\local_imisbridge\util::find_user_by_imis_id('20050')->id);
    }

    /**
     * The REST reader maps columns by alias or caption, ignoring case, spaces and
     * underscores, and reads the active flag and end date.
     *
     * @covers \local_imisbridge\registration\rest_reader::to_row
     * @return void
     */
    public function test_rest_reader_maps_columns_by_alias_or_caption(): void {
        $row = rest_reader::to_row([
            'ID'                     => '20016',
            'registrationkey'        => '2044.00-20016',
            'Event Code'             => 'ATS_BRIDGE',
            'Status'                 => 'C',
            'Is Active Registration' => 'True',
            'End_Date'               => '2026-10-30T00:00:00',
            'First Name'             => 'Alex',
            'Last Name'              => 'Example',
            'Email'                  => 'member20016@example.com',
        ]);

        $this->assertSame('20016', $row->imisid);
        $this->assertSame('2044.00-20016', $row->key);
        $this->assertSame('ATS_BRIDGE', $row->eventcode);
        $this->assertSame('C', $row->status);
        $this->assertTrue($row->isactive);
        $this->assertSame(strtotime('2026-10-30T00:00:00'), $row->enddate);
        $this->assertSame('Alex', $row->firstname);
        $this->assertSame('Example', $row->lastname);

        $blank = rest_reader::to_row(['ID' => '1', 'EventCode' => 'X']);
        $this->assertNull($blank->isactive);
        $this->assertNull($blank->enddate);
        $this->assertSame('', $blank->status);
    }
}
