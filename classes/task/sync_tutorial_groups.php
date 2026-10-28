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
 * Scheduled task: sync iMIS tutorial groups into Moodle course groups (nightly by default).
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\task;

use local_membershipbridge\rest_client;
use local_membershipbridge\tutorial\group_sync;
use local_membershipbridge\tutorial\rest_course_resolver;
use local_membershipbridge\tutorial\rest_reader;

/**
 * Makes Moodle course groups match the iMIS MoodleTutorialGroup panel.
 *
 * Reads the panel directly over the iMIS REST API with the plugin's server account
 * and reconciles groups server-side, so it needs no Moodle web-service user and no
 * group web-service capabilities. The feature is off until explicitly enabled, and
 * defaults to dry-run so a new site logs what it would do before it touches data.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_tutorial_groups extends \core\task\scheduled_task {
    /**
     * The task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_tutorial_groups', 'local_membershipbridge');
    }

    /**
     * Read the panel and reconcile the Moodle groups.
     *
     * @return void
     */
    public function execute(): void {
        if (!\local_membershipbridge\util::is_enabled_optin('tutorial_sync_enabled')) {
            mtrace('Membership Bridge: Tutorial group sync disabled in plugin settings; skipping.');
            return;
        }

        // This feature talks to iMIS over REST directly, independently of the active
        // push transport, so it needs the REST connection settings regardless of
        // whether the plugin is otherwise configured for SOAP.
        if (!rest_client::is_configured()) {
            mtrace('Membership Bridge: REST connection not configured; skipping tutorial group sync.');
            return;
        }

        $dryrun = \local_membershipbridge\util::is_enabled('tutorial_dry_run');
        $guard  = (int)get_config('local_membershipbridge', 'tutorial_removal_guard_pct');

        mtrace('Membership Bridge: Starting tutorial group sync' . ($dryrun ? ' (DRY RUN)' : '') . '...');
        try {
            $client   = new rest_client();
            $sync     = new group_sync(
                new rest_reader($client),
                new rest_course_resolver($client),
                ['dryrun' => $dryrun, 'removalguardpct' => $guard, 'tutorroleid' => group_sync::configured_tutor_role()]
            );
            $report = $sync->run();

            mtrace(sprintf(
                'Membership Bridge: Tutorial group sync %s. Courses: %d, groups created: %d, '
                    . 'members added: %d, removed: %d, rows skipped: %d, removal guards: %d, '
                    . 'tutors enrolled: %d, tutor roles added: %d, removed: %d.',
                $report['aborted'] ? 'ABORTED (no changes)' : 'complete',
                $report['coursesprocessed'],
                $report['groupscreated'],
                $report['membersadded'],
                $report['membersremoved'],
                $report['rowsskipped'],
                $report['guardskipped'],
                $report['tutorsenrolled'],
                $report['tutorrolesadded'],
                $report['tutorrolesremoved']
            ));
        } catch (\Exception $e) {
            mtrace('Membership Bridge: Tutorial group sync FAILED: ' . $e->getMessage());
            throw $e;
        }
    }
}
