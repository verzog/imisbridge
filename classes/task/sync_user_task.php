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
 * Adhoc task: synchronise a single iMIS user off the request path.
 *
 * Queued on login so the external SOAP round-trips do not block the user.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Synchronises one iMIS user's enrolments, cancellations and groups.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_user_task extends \core\task\adhoc_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_user', 'local_imisbridge');
    }

    /**
     * Executes the per-user sync. Exceptions propagate so Moodle retries with backoff.
     *
     * @return void
     */
    public function execute(): void {
        $data   = $this->get_custom_data();
        $imisid = $data->imisid ?? '';

        if (empty($imisid)) {
            return;
        }
        // Re-check the iMIS ID format: it may have been set to Digits only after queueing.
        if (!\local_imisbridge\util::is_valid_imis_id((string)$imisid)) {
            mtrace('Membership Bridge: ' . $imisid . ' is not in the iMIS ID format; skipping.');
            return;
        }

        if (!\local_imisbridge\provider\manager::sync_configured()) {
            mtrace('Membership Bridge: no configured sync provider; skipping ' . $imisid . '.');
            return;
        }

        $enrolments = \local_imisbridge\util::is_enabled('sync_enrolments_on_login');
        $cancellations = \local_imisbridge\util::is_enabled('sync_cancellations_on_login');
        $groups = \local_imisbridge\util::is_enabled('sync_groups_on_login');

        // Return before touching the network if every login sync was disabled
        // after this task was queued: constructing the client fetches the WSDL.
        if (!$enrolments && !$cancellations && !$groups) {
            mtrace('Membership Bridge: all login syncs disabled; skipping ' . $imisid . '.');
            return;
        }

        $provider = \local_imisbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            mtrace('Membership Bridge: no configured sync provider; skipping ' . $imisid . '.');
            return;
        }

        mtrace('Membership Bridge: syncing user ' . $imisid . '...');

        if ($enrolments) {
            $provider->sync_orders($imisid);
        }
        if ($cancellations) {
            $provider->sync_cancelled_orders($imisid);
        }
        if ($groups) {
            $provider->update_groups($imisid);
        }

        mtrace('Membership Bridge: user sync complete for ' . $imisid . '.');
    }
}
