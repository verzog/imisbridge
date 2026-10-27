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
 * Scheduled task: sync iMIS enrollments for all users.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\task;

/**
 * Syncs iMIS enrollments for all Moodle users.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_enrollments_task extends \core\task\scheduled_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_enrollments', 'local_membershipbridge');
    }

    /**
     * Executes the enrollment sync for all users (empty contactId = all).
     *
     * @return void
     */
    public function execute(): void {
        if (!\local_membershipbridge\util::is_enabled('task_enrolments_enabled')) {
            mtrace('Membership Bridge: Enrolment sync task disabled in plugin settings; skipping.');
            return;
        }

        $provider = \local_membershipbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            mtrace('Membership Bridge: no configured sync provider (see the Sync provider setting); skipping enrolment sync.');
            return;
        }

        mtrace('Membership Bridge: Starting enrollment sync for all users...');
        try {
            $result = $provider->sync_orders(null);
            mtrace('Membership Bridge: Enrollment sync complete. Result: ' . var_export($result, true));
        } catch (\Exception $e) {
            mtrace('Membership Bridge: Enrollment sync FAILED: ' . $e->getMessage());
            throw $e;
        }
    }
}
