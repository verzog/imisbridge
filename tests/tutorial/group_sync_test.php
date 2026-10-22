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
 * Tests for the tutorial-group sync engine.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

/**
 * Unit tests for {@see group_sync}.
 *
 * The engine is exercised with in-memory fakes for the two injected interfaces, so
 * no iMIS connection is involved and the tests assert only Moodle-side behaviour.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\tutorial\group_sync
 */
final class group_sync_test extends \advanced_testcase {
    /**
     * Reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A reader fake returning a fixed list of rows.
     *
     * @param array $rows The membership rows to return.
     * @return reader
     */
    private function reader(array $rows): reader {
        return new class($rows) implements reader {
            /** @var array The fixed rows. */
            private array $rows;

            /**
             * @param array $rows The fixed rows.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * @return array The fixed rows.
             */
            public function fetch_rows(): array {
                return $this->rows;
            }
        };
    }

    /**
     * A resolver fake mapping codes to course ids from an array.
     *
     * @param array $map code => courseid.
     * @return course_resolver
     */
    private function resolver(array $map): course_resolver {
        return new class($map) implements course_resolver {
            /** @var array The code => courseid map. */
            private array $map;

            /**
             * @param array $map The code => courseid map.
             */
            public function __construct(array $map) {
                $this->map = $map;
            }

            /**
             * @param string $code The code to resolve.
             * @return int|null The mapped course id, or null.
             */
            public function resolve_courseid(string $code): ?int {
                return $this->map[$code] ?? null;
            }
        };
    }

    /**
     * Build an engine whose trace output is discarded.
     *
     * @param array $rows    The reader rows.
     * @param array $map     The code => courseid map.
     * @param array $options Engine options.
     * @return group_sync
     */
    private function engine(array $rows, array $map, array $options = []): group_sync {
        $options['trace'] = function (string $line): void {
            // Swallow progress output in tests.
            return;
        };
        return new group_sync($this->reader($rows), $this->resolver($map), $options);
    }

    /**
     * Create a user whose username is the given iMIS id and enrol them in the course.
     *
     * @param string $imisid   The iMIS id (used as the username).
     * @param int    $courseid The course to enrol them in.
     * @return \stdClass The user.
     */
    private function enrolled_user(string $imisid, int $courseid): \stdClass {
        $user = $this->getDataGenerator()->create_user(['username' => $imisid]);
        $this->getDataGenerator()->enrol_user($user->id, $courseid);
        return $user;
    }

    /**
     * A new tutorial group is created in the grouping and enrolled members are added.
     *
     * @return void
     */
    public function test_creates_group_and_adds_members(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $a = $this->enrolled_user('20001', $course->id);
        $b = $this->enrolled_user('20002', $course->id);

        $rows = [
            new membership_row('20001', 'ISC_26', 'Tutorial A'),
            new membership_row('20002', 'ISC_26', 'Tutorial A'),
        ];
        $report = $this->engine($rows, ['ISC_26' => $course->id])->run();

        $group = $DB->get_record('groups', ['courseid' => $course->id, 'idnumber' => 'imis:ISC_26:tutorial-a']);
        $this->assertNotEmpty($group);
        $this->assertSame('Tutorial A', $group->name);
        $this->assertTrue(groups_is_member($group->id, $a->id));
        $this->assertTrue(groups_is_member($group->id, $b->id));

        // The group sits in the "Tutorials (iMIS)" grouping.
        $grouping = $DB->get_record('groupings', ['courseid' => $course->id, 'idnumber' => group_sync::GROUPING_IDNUMBER]);
        $this->assertNotEmpty($grouping);
        $this->assertTrue($DB->record_exists('groupings_groups', ['groupingid' => $grouping->id, 'groupid' => $group->id]));

        $this->assertSame(1, $report['groupscreated']);
        $this->assertSame(2, $report['membersadded']);
        $this->assertFalse($report['aborted']);
    }

    /**
     * Membership is added with the plugin as the owning component.
     *
     * @return void
     */
    public function test_membership_is_component_owned(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->enrolled_user('20001', $course->id);

        $this->engine(
            [new membership_row('20001', 'ISC_26', 'Tutorial A')],
            ['ISC_26' => $course->id]
        )->run();

        $group = $DB->get_record('groups', ['courseid' => $course->id, 'idnumber' => 'imis:ISC_26:tutorial-a']);
        $member = $DB->get_record('groups_members', ['groupid' => $group->id, 'userid' => $user->id]);
        $this->assertSame('local_imisbridge', $member->component);
    }

    /**
     * A member no longer in the rows is removed; a manually added member is kept.
     *
     * @return void
     */
    public function test_removes_stale_but_keeps_manual_member(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $keep   = $this->enrolled_user('20001', $course->id);
        $stale  = $this->enrolled_user('20002', $course->id);
        $manual = $this->enrolled_user('20003', $course->id);

        // Seed a prior sync: both keep and stale are plugin-owned members; manual was
        // added by hand (no component).
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name'     => 'Tutorial A',
            'idnumber' => 'imis:ISC_26:tutorial-a',
        ]);
        groups_add_member($group->id, $keep->id, 'local_imisbridge');
        groups_add_member($group->id, $stale->id, 'local_imisbridge');
        groups_add_member($group->id, $manual->id);

        // The current rows list only "keep".
        $report = $this->engine(
            [new membership_row('20001', 'ISC_26', 'Tutorial A')],
            ['ISC_26' => $course->id]
        )->run();

        $this->assertTrue(groups_is_member($group->id, $keep->id), 'listed member kept');
        $this->assertFalse(groups_is_member($group->id, $stale->id), 'unlisted plugin member removed');
        $this->assertTrue(groups_is_member($group->id, $manual->id), 'manual member preserved');
        $this->assertSame(1, $report['membersremoved']);
    }

    /**
     * A user with no Moodle account, or not enrolled, is skipped and logged.
     *
     * @return void
     */
    public function test_skips_missing_and_unenrolled_users(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $enrolled = $this->enrolled_user('20001', $course->id);
        // 20002 exists but is not enrolled; 20003 has no account at all.
        $this->getDataGenerator()->create_user(['username' => '20002']);

        $rows = [
            new membership_row('20001', 'ISC_26', 'Tutorial A'),
            new membership_row('20002', 'ISC_26', 'Tutorial A'),
            new membership_row('20003', 'ISC_26', 'Tutorial A'),
        ];
        $report = $this->engine($rows, ['ISC_26' => $course->id])->run();

        $group = $DB->get_record('groups', ['courseid' => $course->id, 'idnumber' => 'imis:ISC_26:tutorial-a']);
        $this->assertSame(1, $report['membersadded']);
        $this->assertSame(2, $report['rowsskipped']);
        $this->assertTrue(groups_is_member($group->id, $enrolled->id));
    }

    /**
     * An empty read makes no changes at all and reports as aborted.
     *
     * @return void
     */
    public function test_empty_read_aborts_without_changes(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->enrolled_user('20001', $course->id);
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name'     => 'Tutorial A',
            'idnumber' => 'imis:ISC_26:tutorial-a',
        ]);
        groups_add_member($group->id, $user->id, 'local_imisbridge');

        $report = $this->engine([], ['ISC_26' => $course->id])->run();

        $this->assertTrue($report['aborted']);
        $this->assertSame(0, $report['membersremoved']);
        $this->assertTrue(groups_is_member($group->id, $user->id), 'empty read must not remove anyone');
    }

    /**
     * The removal guard skips a group's removals when they exceed the configured share.
     *
     * @return void
     */
    public function test_removal_guard_skips_large_removals(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $users = [];
        foreach (['20001', '20002', '20003', '20004'] as $id) {
            $users[$id] = $this->enrolled_user($id, $course->id);
        }
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name'     => 'Tutorial A',
            'idnumber' => 'imis:ISC_26:tutorial-a',
        ]);
        foreach ($users as $user) {
            groups_add_member($group->id, $user->id, 'local_imisbridge');
        }

        // Rows keep only one of four; removing three is 75% > the 50% guard.
        $report = $this->engine(
            [new membership_row('20001', 'ISC_26', 'Tutorial A')],
            ['ISC_26' => $course->id],
            ['removalguardpct' => 50]
        )->run();

        $this->assertSame(1, $report['guardskipped']);
        $this->assertSame(0, $report['membersremoved']);
        foreach ($users as $user) {
            $this->assertTrue(groups_is_member($group->id, $user->id), 'guard must keep every member');
        }
    }

    /**
     * When a group loses all its rows, its plugin members are removed but the group
     * is left in place (not deleted).
     *
     * @return void
     */
    public function test_vanished_group_is_emptied_not_deleted(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $gone = $this->enrolled_user('20001', $course->id);
        $kept = $this->enrolled_user('20002', $course->id);

        $groupa = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name'     => 'Tutorial A',
            'idnumber' => 'imis:ISC_26:tutorial-a',
        ]);
        groups_add_member($groupa->id, $gone->id, 'local_imisbridge');

        // This run only mentions Tutorial B; Tutorial A has vanished from the rows.
        $this->engine(
            [new membership_row('20002', 'ISC_26', 'Tutorial B')],
            ['ISC_26' => $course->id]
        )->run();

        $this->assertTrue(
            $DB->record_exists('groups', ['id' => $groupa->id]),
            'the vanished group must still exist'
        );
        $this->assertFalse(groups_is_member($groupa->id, $gone->id), 'its members must be removed');

        $groupb = $DB->get_record('groups', ['courseid' => $course->id, 'idnumber' => 'imis:ISC_26:tutorial-b']);
        $this->assertNotEmpty($groupb);
        $this->assertTrue(groups_is_member($groupb->id, $kept->id));
    }

    /**
     * Dry run reports the changes it would make but writes nothing.
     *
     * @return void
     */
    public function test_dry_run_makes_no_changes(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->enrolled_user('20001', $course->id);

        $report = $this->engine(
            [new membership_row('20001', 'ISC_26', 'Tutorial A')],
            ['ISC_26' => $course->id],
            ['dryrun' => true]
        )->run();

        $this->assertSame(1, $report['groupscreated']);
        $this->assertSame(1, $report['membersadded']);
        $this->assertFalse(
            $DB->record_exists('groups', ['courseid' => $course->id, 'idnumber' => 'imis:ISC_26:tutorial-a']),
            'dry run must not create the group'
        );
    }

    /**
     * Group names differing only by case collapse to the same group.
     *
     * @return void
     */
    public function test_group_names_match_case_insensitively(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $a = $this->enrolled_user('20001', $course->id);
        $b = $this->enrolled_user('20002', $course->id);

        $report = $this->engine(
            [
                new membership_row('20001', 'ISC_26', 'Tutorial A'),
                new membership_row('20002', 'ISC_26', 'tutorial a'),
            ],
            ['ISC_26' => $course->id]
        )->run();

        $groups = $DB->get_records_select(
            'groups',
            'courseid = ? AND ' . $DB->sql_like('idnumber', '?'),
            [$course->id, 'imis:ISC\_26:%']
        );
        $this->assertCount(1, $groups, 'the two spellings must be one group');
        $this->assertSame(1, $report['groupscreated']);
        $this->assertSame(2, $report['membersadded']);
    }

    /**
     * A code with no mapped course is skipped and its rows counted as skipped.
     *
     * @return void
     */
    public function test_unmapped_code_is_skipped(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->enrolled_user('20001', $course->id);

        $report = $this->engine(
            [new membership_row('20001', 'NOPE', 'Tutorial A')],
            ['ISC_26' => $course->id]
        )->run();

        $this->assertSame(0, $report['coursesprocessed']);
        $this->assertSame(1, $report['rowsskipped']);
        $this->assertSame(0, $report['groupscreated']);
    }
}
