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
 * Scheduled task: sync iMIS groups (incremental, based on last run time).
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\task;

/**
 * Syncs iMIS groups, incrementally based on the last run timestamp.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_groups_task extends \core\task\scheduled_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_groups', 'local_membershipbridge');
    }

    /**
     * Executes the group sync, passing the last run time for incremental updates.
     *
     * @return void
     */
    public function execute(): void {
        if (!\local_membershipbridge\util::is_enabled('task_groups_enabled')) {
            mtrace('Membership Bridge: Group sync task disabled in plugin settings; skipping.');
            return;
        }

        $provider = \local_membershipbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            mtrace('Membership Bridge: no configured sync provider (see the Sync provider setting); skipping group sync.');
            return;
        }
        if ($provider instanceof \local_membershipbridge\rest_client) {
            // Under REST, groups come from the tutorial group sync. Return without
            // touching the watermark, so switching back to the ATS bridge resumes its
            // incremental sync from where it left off rather than skipping changes.
            mtrace('Membership Bridge: ' . $provider->update_groups() . ' Skipping group sync.');
            return;
        }

        mtrace('Membership Bridge: Starting group sync...');
        try {
            // Incremental sync uses a watermark this task manages itself, rather than
            // the scheduled-task last-run time. A disabled run returns early without
            // advancing it, so re-enabling the task does not skip changes made while
            // it was off. Null means sync everything (first run).
            $lastsync    = get_config('local_membershipbridge', 'groups_last_sync');
            $lastupdated = !empty($lastsync) ? gmdate('Y-m-d\TH:i:s', (int)$lastsync) : null;

            // Stamp the window start before the call so changes made during the sync
            // are re-checked next time (a safe overlap) rather than missed.
            $windowstart = time();

            $result = $provider->update_groups(null, null, $lastupdated);

            // Advance the watermark only after a successful sync.
            set_config('groups_last_sync', $windowstart, 'local_membershipbridge');
            mtrace('Membership Bridge: Group sync complete. Result: ' . var_export($result, true));
        } catch (\Exception $e) {
            mtrace('Membership Bridge: Group sync FAILED: ' . $e->getMessage());
            throw $e;
        }
    }
}
