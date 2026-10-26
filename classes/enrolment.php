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
 * Enrolment changes shared by the cancellation web service and the registration sync.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

use context_course;

/**
 * Enrol, reactivate and suspend learners without ever deleting their data.
 *
 * Suspending keeps the enrolment row, grades and completion; it only removes
 * access, and a later {@see ensure_active()} restores it. Nothing here unenrols.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment {
    /** @var string The learner already had an active enrolment in the course. */
    public const ALREADY = 'already';

    /** @var string The learner was enrolled through the course's manual instance. */
    public const ENROLLED = 'enrolled';

    /** @var string A suspended manual enrolment was made active again. */
    public const REACTIVATED = 'reactivated';

    /** @var string The course has no enabled manual enrolment instance to enrol through. */
    public const NO_INSTANCE = 'noinstance';

    /**
     * Make sure a learner has an active enrolment in a course.
     *
     * An active enrolment by any method counts. Otherwise the learner is enrolled,
     * or their suspended enrolment reactivated, through the course's enabled manual
     * instance with that instance's default role (normally Student).
     *
     * @param int  $userid   The Moodle user ID.
     * @param int  $courseid The course ID.
     * @param bool $dryrun   When true, report what would happen without changing anything.
     * @return string One of the class constants.
     */
    public static function ensure_active(int $userid, int $courseid, bool $dryrun = false): string {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        if (is_enrolled(context_course::instance($courseid), $userid, '', true)) {
            return self::ALREADY;
        }

        $instance = self::manual_instance($courseid);
        $plugin   = enrol_get_plugin('manual');
        if ($instance === null || $plugin === null) {
            return self::NO_INSTANCE;
        }

        $existing = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if (!$dryrun) {
            if ($existing) {
                self::reactivate($plugin, $instance, $userid);
            } else {
                $plugin->enrol_user($instance, $userid, (int)$instance->roleid, 0, 0, ENROL_USER_ACTIVE);
            }
        }
        return $existing ? self::REACTIVATED : self::ENROLLED;
    }

    /**
     * Make an existing (suspended or expired) manual enrolment active again and give
     * back its default role, keeping its grades and completion.
     *
     * This deliberately avoids enrol_user(): Moodle sends the course welcome message
     * on every enrol_user() call, not only for a new enrolment, so reactivating
     * through it would welcome a returning learner a second time.
     *
     * @param \enrol_plugin $plugin   The manual enrol plugin.
     * @param \stdClass     $instance The course's manual enrolment instance.
     * @param int           $userid   The Moodle user ID.
     * @return void
     */
    private static function reactivate(\enrol_plugin $plugin, \stdClass $instance, int $userid): void {
        $plugin->update_user_enrol($instance, $userid, ENROL_USER_ACTIVE, 0, 0);

        $roleid = (int)$instance->roleid;
        if ($roleid <= 0) {
            return;
        }
        $contextid = context_course::instance((int)$instance->courseid)->id;
        if ($plugin->roles_protected()) {
            role_assign($roleid, $userid, $contextid, 'enrol_' . $instance->enrol, (int)$instance->id);
        } else {
            role_assign($roleid, $userid, $contextid);
        }
    }

    /**
     * The course's first enabled manual enrolment instance, or null when it has none
     * (or the manual enrol plugin is disabled site-wide).
     *
     * @param int $courseid The course ID.
     * @return \stdClass|null
     */
    public static function manual_instance(int $courseid): ?\stdClass {
        global $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        foreach (enrol_get_instances($courseid, true) as $candidate) {
            if ($candidate->enrol === 'manual') {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The learners who hold at least one unsuspended enrolment in a course, by any
     * method and whatever its dates or plugin state. This is wider than "active today":
     * a future-dated enrolment, or one owned by a disabled plugin, would still grant
     * access later, so it counts.
     *
     * @param int $courseid The course ID.
     * @return int[] User IDs.
     */
    public static function unsuspended_userids(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $sql = "SELECT DISTINCT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.status = :active";
        $ids = $DB->get_fieldset_sql($sql, ['courseid' => $courseid, 'active' => ENROL_USER_ACTIVE]);
        return array_map('intval', $ids);
    }

    /**
     * Suspend every enrolment a learner holds in a course and remove the role each
     * one grants. Call inside a transaction when the change must be all-or-nothing.
     *
     * @param int $userid   The Moodle user ID.
     * @param int $courseid The course ID.
     * @param int $roleid   The role to unassign, or 0 for each enrolment's own default role.
     * @return int The number of enrolments found (each now suspended).
     */
    public static function suspend(int $userid, int $courseid, int $roleid = 0): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $context = context_course::instance($courseid);

        // All installed enrol plugins, not only the enabled ones: an enrolment
        // owned by a globally-disabled plugin must still be suspended, or it would
        // grant access again if the plugin were later re-enabled.
        $plugins = enrol_get_plugins(false);

        // Suspend every enrolment the user holds in the course. Suspending
        // keeps the enrolment row, grades and completion; it only removes
        // access, and is undone by a later re-enrol. Nothing is deleted.
        $suspended = 0;
        foreach (enrol_get_instances($courseid, false) as $instance) {
            $plugin = $plugins[$instance->enrol] ?? null;
            if ($plugin === null) {
                // No installed plugin for this instance (orphaned); skip.
                continue;
            }
            $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
            if (!$ue) {
                continue;
            }
            if ((int)$ue->status !== ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }

            // Remove the role this enrolment grants: the explicit roleid when
            // given, otherwise the instance's own default role (enrol.roleid).
            // Targeting one role id removes the learner's enrolment role (e.g.
            // Student) while leaving any other role — such as a manual teacher
            // override — in place. We cannot scope by component here: the manual
            // enrol plugin assigns its role with no component (roles unprotected),
            // so its assignment is indistinguishable from a manual one. Role
            // unassignment deletes assignments only, never grades/completion.
            $targetroleid = $roleid > 0 ? $roleid : (int)$instance->roleid;
            if ($targetroleid > 0) {
                role_unassign_all([
                    'userid'    => $userid,
                    'contextid' => $context->id,
                    'roleid'    => $targetroleid,
                ]);
            }

            $suspended++;
        }

        return $suspended;
    }
}
