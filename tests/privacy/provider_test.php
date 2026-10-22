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
 * Tests for the local_imisbridge privacy provider.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Unit tests for {@see provider}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * Insert one audit-log row for a user.
     *
     * @param int $userid The Moodle user id.
     * @return void
     */
    private function insert_log(int $userid): void {
        global $DB;
        $DB->insert_record('local_imisbridge_log', (object)[
            'userid'      => $userid,
            'operation'   => 'MoodleUpdate',
            'status'      => 'success',
            'timecreated' => time(),
        ]);
    }

    /**
     * The provider declares the audit-log table and the external iMIS data flow.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $this->resetAfterTest();

        $collection = new collection('local_imisbridge');
        $result     = provider::get_metadata($collection);

        $this->assertInstanceOf(collection::class, $result);

        $names = [];
        foreach ($result->get_collection() as $item) {
            $names[] = $item->get_name();
        }
        $this->assertContains('local_imisbridge_log', $names);
        $this->assertContains('imis', $names);
    }

    /**
     * A user with log rows is found in the system context, and their data exports.
     *
     * @return void
     */
    public function test_get_contexts_and_export(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->insert_log($user->id);

        $systemcontext = \context_system::instance();

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertEquals([$systemcontext->id], $contextlist->get_contextids());

        $approved = new approved_contextlist($user, 'local_imisbridge', [$systemcontext->id]);
        provider::export_user_data($approved);
        $this->assertTrue(writer::with_context($systemcontext)->has_any_data());
    }

    /**
     * Deleting one user's data leaves other users' rows intact.
     *
     * @return void
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user  = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->insert_log($user->id);
        $this->insert_log($other->id);

        $systemcontext = \context_system::instance();
        $approved = new approved_contextlist($user, 'local_imisbridge', [$systemcontext->id]);
        provider::delete_data_for_user($approved);

        $this->assertEquals(0, $DB->count_records('local_imisbridge_log', ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_imisbridge_log', ['userid' => $other->id]));
    }

    /**
     * The userlist provider finds and deletes the right users.
     *
     * @return void
     */
    public function test_userlist(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->insert_log($user->id);

        $systemcontext = \context_system::instance();

        $userlist = new userlist($systemcontext, 'local_imisbridge');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user->id], $userlist->get_userids());

        $approved = new approved_userlist($systemcontext, 'local_imisbridge', [$user->id]);
        provider::delete_data_for_users($approved);
        $this->assertEquals(0, $DB->count_records('local_imisbridge_log', ['userid' => $user->id]));
    }

    /**
     * Score-push rows are declared, found, exported and deleted like the log, even
     * for a user with no log rows.
     *
     * @return void
     */
    public function test_scorepush_rows_are_covered(): void {
        global $DB;
        $this->resetAfterTest();

        $names = [];
        foreach (provider::get_metadata(new collection('local_imisbridge'))->get_collection() as $item) {
            $names[] = $item->get_name();
        }
        $this->assertContains('local_imisbridge_scorepush', $names);

        $user  = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$user, $other] as $u) {
            $DB->insert_record('local_imisbridge_scorepush', (object)[
                'userid'     => $u->id,
                'itemid'     => 42,
                'finalgrade' => 80,
                'timequeued' => time(),
            ]);
        }

        $systemcontext = \context_system::instance();
        $this->assertEquals([$systemcontext->id], provider::get_contexts_for_userid($user->id)->get_contextids());

        $userlist = new userlist($systemcontext, 'local_imisbridge');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user->id, $other->id], $userlist->get_userids());

        $approved = new approved_contextlist($user, 'local_imisbridge', [$systemcontext->id]);
        provider::export_user_data($approved);
        $this->assertTrue(writer::with_context($systemcontext)->has_any_data());

        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_imisbridge_scorepush', ['userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_imisbridge_scorepush', ['userid' => $other->id]));

        provider::delete_data_for_users(new approved_userlist($systemcontext, 'local_imisbridge', [$other->id]));
        $this->assertEquals(0, $DB->count_records('local_imisbridge_scorepush'));
    }
}

