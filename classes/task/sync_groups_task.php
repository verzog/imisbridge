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
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Syncs iMIS groups, incrementally based on the last run timestamp.
 *
 * @package    local_imisbridge
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
        return get_string('task_sync_groups', 'local_imisbridge');
    }

    /**
     * Executes the group sync, passing the last run time for incremental updates.
     *
     * @return void
     */
    public function execute(): void {
        if (!\local_imisbridge\util::is_enabled('task_groups_enabled')) {
            mtrace('iMIS Bridge: Group sync task disabled in plugin settings; skipping.');
            return;
        }

        $provider = \local_imisbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            mtrace('iMIS Bridge: no configured sync provider (see the Sync provider setting); skipping group sync.');
            return;
        }

        mtrace('iMIS Bridge: Starting group sync...');
        try {
            // Incremental sync uses a watermark this task manages itself, rather than
            // the scheduled-task last-run time. A disabled run returns early without
            // advancing it, so re-enabling the task does not skip changes made while
            // it was off. Null means sync everything (first run).
            $lastsync    = get_config('local_imisbridge', 'groups_last_sync');
            $lastupdated = !empty($lastsync) ? gmdate('Y-m-d\TH:i:s', (int)$lastsync) : null;

            // Stamp the window start before the call so changes made during the sync
            // are re-checked next time (a safe overlap) rather than missed.
            $windowstart = time();

            $result = $provider->update_groups(null, null, $lastupdated);

            // Advance the watermark only after a successful sync.
            set_config('groups_last_sync', $windowstart, 'local_imisbridge');
            mtrace('iMIS Bridge: Group sync complete. Result: ' . var_export($result, true));
        } catch (\Exception $e) {
            mtrace('iMIS Bridge: Group sync FAILED: ' . $e->getMessage());
            throw $e;
        }
    }
}
