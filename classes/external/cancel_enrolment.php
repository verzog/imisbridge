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
 * External web service: cancel a Moodle enrolment without destroying data.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * Cancel a user's enrolment in a course by suspending it and removing their role,
 * never unenrolling.
 *
 * The inbound cancellation path (iMIS order cancelled) must not lose learner data:
 * a hard unenrol (core enrol_manual_unenrol_users) deletes that enrolment's grades
 * and completion. This function instead **suspends** the enrolment (the row, grades
 * and completion all remain; the learner simply loses course access) and
 * **unassigns** their role in the course, which is fully reversible by a later
 * re-enrol. It is non-destructive by construction, so an error can never delete
 * data: each entry is processed independently and a failure is reported as a
 * warning rather than aborting the batch or removing anything.
 *
 * Point the ATS `FN_ENROL_MANUAL_UNENROL_USERS` function mapping at
 * `local_imisbridge_cancel_enrolment` and send `userid`+`courseid` (which the ATS
 * already resolves) instead of core unenrol's manual-only `ueid`.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cancel_enrolment extends external_api {
    /**
     * Describe the function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enrolments' => new external_multiple_structure(
                new external_single_structure([
                    'userid'   => new external_value(PARAM_INT, 'Moodle user id to cancel.'),
                    'courseid' => new external_value(PARAM_INT, 'Moodle course id to cancel the user in.'),
                    'roleid'   => new external_value(
                        PARAM_INT,
                        'Role to unassign in the course context. 0 (default) unassigns every role the '
                            . 'user holds in that course.',
                        VALUE_DEFAULT,
                        0
                    ),
                ]),
                'The user+course enrolments to cancel (suspend the enrolment and remove the role).'
            ),
        ]);
    }

    /**
     * Suspend each enrolment and remove the role, never unenrolling.
     *
     * @param array $enrolments List of {userid, courseid, roleid} to cancel.
     * @return array The per-entry results and any warnings.
     */
    public static function execute(array $enrolments): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['enrolments' => $enrolments]);

        $plugins  = enrol_get_plugins(true);
        $results  = [];
        $warnings = [];

        foreach ($params['enrolments'] as $index => $entry) {
            $userid   = (int)$entry['userid'];
            $courseid = (int)$entry['courseid'];
            $roleid   = (int)$entry['roleid'];

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                $warnings[] = self::warn($index, $courseid, 'coursenotfound', 'No course with that id.');
                $results[]  = self::result($userid, $courseid, 0, false, 'coursenotfound');
                continue;
            }

            // Security: the cancel capability, checked in the course context. A
            // system-context service role satisfies it for every course.
            $context = context_course::instance($courseid);
            self::validate_context($context);
            require_capability('local/imisbridge:cancelenrolment', $context);

            if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                $warnings[] = self::warn($index, $courseid, 'usernotfound', 'No active user with that id.');
                $results[]  = self::result($userid, $courseid, 0, false, 'usernotfound');
                continue;
            }

            try {
                // Suspend every enrolment the user holds in the course. Suspending
                // keeps the enrolment row, grades and completion; it only removes
                // access, and is undone by a later re-enrol. Nothing is deleted.
                $suspended = 0;
                foreach (enrol_get_instances($courseid, false) as $instance) {
                    $plugin = $plugins[$instance->enrol] ?? null;
                    if ($plugin === null) {
                        // The instance's enrol plugin is disabled; its enrolment
                        // already grants no access and its API is unavailable.
                        continue;
                    }
                    $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
                    if (!$ue) {
                        continue;
                    }
                    if ((int)$ue->status !== ENROL_USER_SUSPENDED) {
                        $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
                    }
                    $suspended++;
                }

                // Remove the role(s) in the course context so the learner has no
                // role there. role_unassign_all only deletes role assignments, never
                // the enrolment or its grades/completion. It matches by the given
                // fields and, with the default includemanual = false, removes the
                // automated (enrol-component) assignments — which the inbound enrol
                // created — while leaving a manual admin override in place.
                $roleparams = ['userid' => $userid, 'contextid' => $context->id];
                if ($roleid > 0) {
                    $roleparams['roleid'] = $roleid;
                }
                role_unassign_all($roleparams);

                $results[] = self::result(
                    $userid,
                    $courseid,
                    $suspended,
                    true,
                    $suspended > 0 ? 'cancelled' : 'notenrolled'
                );
            } catch (\Throwable $e) {
                // Non-destructive path, so a failure leaves data intact; report it.
                $warnings[] = self::warn($index, $courseid, 'error', $e->getMessage());
                $results[]  = self::result($userid, $courseid, 0, false, 'error');
            }
        }

        return ['results' => $results, 'warnings' => $warnings];
    }

    /**
     * Build one per-entry result row.
     *
     * @param int    $userid          The Moodle user id.
     * @param int    $courseid        The Moodle course id.
     * @param int    $suspended       Number of enrolments suspended.
     * @param bool   $rolesunassigned Whether the role unassignment step ran.
     * @param string $status          Outcome keyword.
     * @return array
     */
    private static function result(
        int $userid,
        int $courseid,
        int $suspended,
        bool $rolesunassigned,
        string $status
    ): array {
        return [
            'userid'              => $userid,
            'courseid'            => $courseid,
            'enrolmentssuspended' => $suspended,
            'rolesunassigned'     => $rolesunassigned,
            'status'              => $status,
        ];
    }

    /**
     * Build one warning row.
     *
     * @param int    $index   The 0-based index in the input list.
     * @param int    $courseid The Moodle course id (the warning item id).
     * @param string $code    Short warning code.
     * @param string $message Human-readable detail.
     * @return array
     */
    private static function warn(int $index, int $courseid, string $code, string $message): array {
        return [
            'item'        => 'enrolment ' . $index,
            'itemid'      => $courseid,
            'warningcode' => $code,
            'message'     => $message,
        ];
    }

    /**
     * Describe the function return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'userid'   => new external_value(PARAM_INT, 'Moodle user id.'),
                    'courseid' => new external_value(PARAM_INT, 'Moodle course id.'),
                    'enrolmentssuspended' => new external_value(
                        PARAM_INT,
                        'Number of the user\'s enrolments in the course that are now suspended.'
                    ),
                    'rolesunassigned' => new external_value(
                        PARAM_BOOL,
                        'True if the role-removal step ran for this entry.'
                    ),
                    'status' => new external_value(
                        PARAM_ALPHA,
                        'Outcome: cancelled, notenrolled, coursenotfound, usernotfound or error.'
                    ),
                ])
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
