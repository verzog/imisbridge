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
 * Scheduled task: prune old Membership Bridge audit-log rows.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\task;

/**
 * Deletes audit-log rows older than the configured retention period.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_log_task extends \core\task\scheduled_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_log', 'local_membershipbridge');
    }

    /**
     * Deletes audit-log rows older than the configured retention period.
     *
     * @return void
     */
    public function execute(): void {
        // Default to 90 days when the setting has not been saved yet (e.g. just
        // after an upgrade); an explicitly saved 0 still means keep indefinitely.
        $days = get_config('local_membershipbridge', 'log_retention_days');
        $days = ($days === false) ? 90 : (int)$days;

        if ($days <= 0) {
            mtrace('Membership Bridge: audit-log retention disabled; keeping all rows.');
            return;
        }

        $deleted = \local_membershipbridge\logger::purge($days);
        mtrace('Membership Bridge: pruned ' . $deleted . ' audit-log row(s) older than ' . $days . ' days.');
    }
}
