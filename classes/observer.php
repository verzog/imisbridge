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
 * Event observer — hooks into Moodle events and queues iMIS sync work.
 *
 * Observers never call iMIS synchronously: every external SOAP round-trip is
 * pushed onto an adhoc task so it runs in cron rather than blocking the
 * user-facing login/quiz/completion request.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Event observer for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Triggered on user login. Queues a per-user enrolment/cancellation/group sync.
     *
     * @param \core\event\user_loggedin $event The login event.
     * @return void
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB;

        // No WSDL configured means no client can be built; skip rather than queue a failing task.
        if (!util::is_configured()) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $event->userid], 'id, username, idnumber, email');
        if (empty($user)) {
            return;
        }

        // Resolve the iMIS contact ID from the configured user field (default username).
        $imisid = util::get_imis_id($user);
        if ($imisid === '') {
            return;
        }

        // Nothing to do if every login sync is disabled.
        if (
            !util::is_enabled('sync_enrolments_on_login')
            && !util::is_enabled('sync_cancellations_on_login')
            && !util::is_enabled('sync_groups_on_login')
        ) {
            return;
        }

        $task = new task\sync_user_task();
        $task->set_custom_data(['imisid' => $imisid]);

        // Collapse repeat logins into a single queued sync.
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Triggered on course completion. Queues a completion record push to iMIS.
     *
     * @param \core\event\course_completed $event The course completed event.
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        if (!util::is_enabled('push_completions')) {
            return;
        }

        if (!util::is_configured()) {
            return;
        }

        $data   = $event->get_data();
        $user   = $DB->get_record('user', ['id' => $data['relateduserid']], 'id, username, idnumber, email');
        $course = $DB->get_record('course', ['id' => $data['courseid']], 'id');

        if (empty($user) || empty($course)) {
            return;
        }

        $imisid = util::get_imis_id($user);
        if ($imisid === '') {
            return;
        }

        $data = self::completion_push_data($imisid, $course, (int)$user->id);
        self::queue_activity_push(
            $data->toggle,
            $data->imisid,
            $data->coursenum,
            $data->creditvalue,
            $data->startdate,
            $data->completiondate,
            $data->grantdate,
            $data->status,
            $data->score
        );
    }

    /**
     * Build the completion push payload for a user and course.
     *
     * Shared by the automatic course-completion observer and the manual
     * "push completion" test control on the sync admin page, so both send an
     * identical record. The status is always a pass (a completion event, or an
     * admin explicitly pushing a completion); the score and credit value come
     * from the course and its gradebook.
     *
     * @param string    $imisid The member ID to record the activity against.
     * @param \stdClass $course A course record carrying at least the numeric id.
     * @param int       $userid The Moodle user ID, for the course grade (0 if unknown).
     * @return \stdClass The push payload (matching push_activity_task's custom data).
     */
    public static function completion_push_data(string $imisid, \stdClass $course, int $userid): \stdClass {
        $credittype = get_config('local_imisbridge', 'credit_type');
        if (empty($credittype)) {
            $credittype = 'CEU';
        }
        $today = date('Y-m-d');

        return (object)[
            'toggle'         => 'push_completions',
            'imisid'         => $imisid,
            'coursenum'      => self::course_number($course),
            'credittype'     => $credittype,
            'creditvalue'    => self::course_credit_value((int)$course->id),
            'startdate'      => $today,
            'completiondate' => $today,
            'grantdate'      => $today,
            'status'         => 'COMPLETE',
            'score'          => self::course_score((int)$course->id, $userid),
        ];
    }

    /**
     * Build the data for a module-completion MOODLENOTE push.
     *
     * Shared by the course_module_completion_updated observer and the manual
     * "push activity note" admin test. The status is taken from the learner's
     * actual completion state for the module when present, otherwise it defaults
     * to COMPLETE (so the manual test still exercises the createActivity path);
     * the score is the learner's **raw** gradebook grade for the module (0 when
     * not yet graded), matching the vendor's module notes, which carry the raw
     * item grade rather than a percentage. Graded modules only: returns null for
     * an ungraded module so the caller rejects the write, upholding the same
     * invariant the automatic observer enforces.
     *
     * @param string    $imisid The iMIS contact ID.
     * @param \stdClass $cm     The course-module record (needs id, course, modname, instance, name).
     * @param int       $userid The Moodle user ID (0 when the member maps to no single user).
     * @return \stdClass|null The queued push data, or null when the module is ungraded.
     */
    public static function module_note_data(string $imisid, \stdClass $cm, int $userid): ?\stdClass {
        global $DB, $CFG;
        // The module_status() mapping references the COMPLETION_* constants; the
        // manual push path does not go through the observer that includes them.
        require_once($CFG->libdir . '/completionlib.php');

        // Re-check graded here, not just at listing time: the push is a separate
        // request, so the grade item could have been removed since the activity
        // was listed (or pushcmid posted directly for an ungraded module).
        $grade = self::module_grade_detail($cm, $userid);
        if (!$grade->graded) {
            return null;
        }
        // Send the raw gradebook grade (the actual mark), not a percentage.
        $score = $grade->finalgrade ?? 0.0;

        $status = 'COMPLETE';
        if ($userid > 0) {
            $completion = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $cm->id,
                'userid'         => $userid,
            ]);
            if (!empty($completion)) {
                $mapped = self::module_status((int)$completion->completionstate);
                if ($mapped !== null) {
                    $status = $mapped;
                }
            }
        }

        return (object)[
            'toggle'    => 'push_module_completions',
            'imisid'    => $imisid,
            'coursenum' => (string)$cm->course,
            'cmid'      => (int)$cm->id,
            'modname'   => $cm->modname,
            'cmname'    => format_string($cm->name),
            'status'    => $status,
            'score'     => $score,
            'date'      => date('Y-m-d'),
        ];
    }

    /**
     * Triggered on quiz attempt submission. Queues a graded record push to iMIS.
     *
     * Preview attempts (teacher previews) and ungraded quizzes are ignored. The
     * score sent is the raw quiz grade (rescaled to the quiz's maximum grade), not
     * a percentage, matching the module notes; pass/fail still uses the percentage.
     *
     * @param \mod_quiz\event\attempt_submitted $event The attempt submitted event.
     * @return void
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        if (!util::is_enabled('push_quiz_scores')) {
            return;
        }

        if (!util::is_configured()) {
            return;
        }

        $data = $event->get_data();
        $user = $DB->get_record('user', ['id' => $data['userid']], 'id, username, idnumber, email');
        if (empty($user)) {
            return;
        }

        $imisid = util::get_imis_id($user);
        if ($imisid === '') {
            return;
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $data['objectid']]);
        if (empty($attempt) || !empty($attempt->preview)) {
            // Ignore teacher/preview attempts.
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if (empty($quiz) || empty($quiz->sumgrades)) {
            // Ungraded quiz — nothing meaningful to report.
            return;
        }

        $course = $DB->get_record('course', ['id' => $quiz->course], 'id');
        if (empty($course)) {
            return;
        }

        // Pass/fail uses the attempt percentage against the quiz pass mark, but the
        // score sent is the raw quiz grade (rescaled to the quiz's maximum grade),
        // not a percentage — matching the module notes, which carry the raw grade.
        $percent  = self::calculate_percentage((float)($attempt->sumgrades ?? 0), (float)$quiz->sumgrades);
        $passmark = !empty($quiz->gradepass) ? (float)$quiz->gradepass : 50.0;
        $status   = self::passfail_status($percent, $passmark);
        $score    = self::quiz_raw_grade(
            (float)($attempt->sumgrades ?? 0),
            (float)$quiz->sumgrades,
            (float)$quiz->grade
        );
        $finish   = !empty($attempt->timefinish) ? date('Y-m-d', $attempt->timefinish) : date('Y-m-d');
        $start    = !empty($attempt->timestart) ? date('Y-m-d', $attempt->timestart) : date('Y-m-d');

        self::queue_activity_push(
            'push_quiz_scores',
            $imisid,
            self::course_number($course),
            self::course_credit_value((int)$course->id),
            $start,
            $finish,
            $finish,
            $status,
            $score
        );
    }

    /**
     * Triggered when a course-module completion changes. Queues a MOODLENOTE push.
     *
     * Opt-in (the `push_module_completions` toggle, default off) and **graded
     * modules only** — a module with no grade item is ignored. Only completed
     * states (pass/fail/complete) are sent; a revert to incomplete is skipped. The
     * record is written as a supplementary MOODLENOTE activity, never the canonical
     * course-completion record.
     *
     * @param \core\event\course_module_completion_updated $event The completion event.
     * @return void
     */
    public static function course_module_completion_updated(
        \core\event\course_module_completion_updated $event
    ): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if (!util::is_enabled_optin('push_module_completions')) {
            return;
        }

        if (!util::is_configured()) {
            return;
        }

        $data = $event->get_data();

        // Read the completion record for the authoritative user, module and state.
        $completion = $DB->get_record('course_modules_completion', ['id' => $data['objectid'] ?? 0]);
        if (empty($completion)) {
            return;
        }

        $status = self::module_status((int)$completion->completionstate);
        if ($status === null) {
            // Incomplete or unknown state — nothing to push.
            return;
        }

        $user = $DB->get_record('user', ['id' => $completion->userid], 'id, username, idnumber, email');
        if (empty($user)) {
            return;
        }

        $imisid = util::get_imis_id($user);
        if ($imisid === '') {
            return;
        }

        $cm = get_coursemodule_from_id('', (int)$completion->coursemoduleid);
        if (!$cm) {
            return;
        }

        // Graded modules only: skip a module that has no grade item.
        $grade = self::module_grade_detail($cm, (int)$user->id);
        if (!$grade->graded) {
            return;
        }
        // Send the raw gradebook grade (the actual mark), not a percentage.
        $score = $grade->finalgrade ?? 0.0;

        $task = new task\push_module_note_task();
        $task->set_custom_data([
            'imisid'    => $imisid,
            'coursenum' => (string)$cm->course,
            'cmid'      => (int)$cm->id,
            'modname'   => $cm->modname,
            'cmname'    => format_string($cm->name),
            'status'    => $status,
            'score'     => $score,
            'date'      => date('Y-m-d'),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Triggered when a learner's grade is written. Queues a score MOODLENOTE push for
     * an activity of a type ticked in the `push_score_modules` checklist.
     *
     * This is what lets a tutor-marked activity (an assignment, a case study, ...)
     * send its score when it is **marked**, which is often after the learner has
     * completed it. Only the activity's own grade counts (grade item type `mod`,
     * item number 0); course and category totals and manual gradebook items are
     * ignored, and quiz is left to its own push on submission. A grade that is
     * unchanged from the last one queued for this learner and grade item is not sent
     * again, so a bulk regrade cannot flood iMIS with duplicate notes. Like every
     * score, it is written as an append-only MOODLENOTE, never the course-completion
     * record.
     *
     * @param \core\event\user_graded $event The user graded event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $modules = util::score_push_modules();
        if (empty($modules) || !util::is_configured()) {
            return;
        }

        // The event's object is the grade_grades row; read it fresh so a burst of
        // events all see the latest stored grade.
        $grade = \grade_grade::fetch(['id' => $event->objectid]);
        if (empty($grade) || $grade->finalgrade === null) {
            // Grade removed or not yet set: nothing to report.
            return;
        }
        $item = $grade->load_grade_item();
        if (
            empty($item) || $item->itemtype !== 'mod' || (int)$item->itemnumber !== 0
                || !in_array($item->itemmodule, $modules, true)
        ) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $grade->userid], 'id, username, idnumber, email');
        if (empty($user)) {
            return;
        }
        $imisid = util::get_imis_id($user);
        if ($imisid === '') {
            return;
        }

        $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
        if (!$cm) {
            return;
        }

        $finalgrade = (float)$grade->finalgrade;
        if (!self::claim_score_push((int)$user->id, (int)$item->id, $finalgrade)) {
            // Same grade as the last one queued: do not send it again.
            return;
        }

        $task = new task\push_score_note_task();
        $task->set_custom_data(self::score_note_data($imisid, $cm, (int)$user->id, $item, $grade));
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Build the data for an activity score MOODLENOTE push.
     *
     * The score is the raw gradebook grade (the actual mark), as on module notes.
     * The status is PASS or FAIL against the grade item's pass mark when it has one,
     * otherwise COMPLETE.
     *
     * @param string       $imisid The iMIS contact ID.
     * @param \stdClass    $cm     The course-module record (needs id, course, modname, name).
     * @param int          $userid The Moodle user ID.
     * @param \grade_item  $item   The activity's grade item.
     * @param \grade_grade $grade  The learner's grade.
     * @return \stdClass The queued push data.
     */
    public static function score_note_data(
        string $imisid,
        \stdClass $cm,
        int $userid,
        \grade_item $item,
        \grade_grade $grade
    ): \stdClass {
        $passed = $grade->is_passed($item);
        $status = ($passed === null) ? 'COMPLETE' : ($passed ? 'PASS' : 'FAIL');
        $graded = !empty($grade->timemodified) ? (int)$grade->timemodified : time();

        return (object)[
            'notekind'  => 'score',
            'imisid'    => $imisid,
            'userid'    => $userid,
            'itemid'    => (int)$item->id,
            'coursenum' => (string)$cm->course,
            'cmid'      => (int)$cm->id,
            'modname'   => $cm->modname,
            'cmname'    => format_string($cm->name),
            'status'    => $status,
            'score'     => (float)$grade->finalgrade,
            'date'      => date('Y-m-d', $graded),
        ];
    }

    /**
     * Record a grade as the latest queued for a learner and grade item, unless it is
     * the same as the one already recorded.
     *
     * The table holds the last grade **queued**; {@see task\push_score_note_task}
     * checks it again before sending, so when several regrades queue in a burst only
     * the latest value is sent.
     *
     * @param int   $userid     The Moodle user ID.
     * @param int   $itemid     The grade item ID.
     * @param float $finalgrade The grade to queue.
     * @return bool True when the grade is new or changed (queue it); false when unchanged.
     */
    public static function claim_score_push(int $userid, int $itemid, float $finalgrade): bool {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $record = $DB->get_record('local_imisbridge_scorepush', ['userid' => $userid, 'itemid' => $itemid]);
        if ($record && !grade_floats_different((float)$record->finalgrade, $finalgrade)) {
            return false;
        }
        if ($record) {
            $record->finalgrade = $finalgrade;
            $record->timequeued = time();
            $DB->update_record('local_imisbridge_scorepush', $record);
        } else {
            try {
                $DB->insert_record('local_imisbridge_scorepush', (object)[
                    'userid'     => $userid,
                    'itemid'     => $itemid,
                    'finalgrade' => $finalgrade,
                    'timequeued' => time(),
                ]);
            } catch (\dml_write_exception $e) {
                // A concurrent event for the same learner and grade item inserted the
                // row first (unique index); that event queues the push, so skip this one.
                return false;
            }
        }
        return true;
    }

    /**
     * Map a course-module completion state to the iMIS MOODLENOTE status.
     *
     * Only completed states map to a value; an incomplete or unknown state returns
     * null so nothing is pushed.
     *
     * @param int $completionstate A COMPLETION_* constant.
     * @return string|null 'PASS', 'FAIL', 'COMPLETE', or null to skip.
     */
    public static function module_status(int $completionstate): ?string {
        switch ($completionstate) {
            case COMPLETION_COMPLETE_PASS:
                return 'PASS';
            case COMPLETION_COMPLETE_FAIL:
                return 'FAIL';
            case COMPLETION_COMPLETE:
                return 'COMPLETE';
            default:
                return null;
        }
    }

    /**
     * Detailed view of the learner's grade for a module. The single source of a
     * module's grade: the note builders read `graded` (whether to send at all) and
     * the raw `finalgrade` (what the note carries), and the manual test control
     * uses the rest to explain how a score was resolved (why it is 0, in particular).
     *
     * Reports the grade item and the learner's gradebook grade, not the raw
     * activity attempt: a module can show a mark in its own report while the
     * gradebook final grade is still empty (not regraded, hidden, or overridden),
     * which is exactly when a note would carry score 0. `score` is the percentage;
     * `finalgrade` is the raw grade the note actually sends.
     *
     * @param \stdClass $cm     The course-module record (needs modname, instance).
     * @param int       $userid The Moodle user ID (0 when no single user matched).
     * @return \stdClass {graded:bool, score:float, itemid:?int, grademax:?float,
     *                    finalgrade:?float, hasgrade:bool}
     */
    public static function module_grade_detail(\stdClass $cm, int $userid): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);
        if (!$gradeitem) {
            // No grade item: the module is ungraded, so the caller skips it.
            return (object)[
                'graded'     => false,
                'score'      => 0.0,
                'itemid'     => null,
                'grademax'   => null,
                'finalgrade' => null,
                'hasgrade'   => false,
            ];
        }

        $grade      = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
        $finalgrade = ($grade && $grade->finalgrade !== null) ? (float)$grade->finalgrade : null;
        // A graded module with no gradebook grade yet still counts as graded; send 0.
        $score = ($finalgrade === null)
            ? 0.0
            : self::calculate_percentage($finalgrade, (float)$gradeitem->grademax);

        return (object)[
            'graded'     => true,
            'score'      => $score,
            'itemid'     => (int)$gradeitem->id,
            'grademax'   => (float)$gradeitem->grademax,
            'finalgrade' => $finalgrade,
            'hasgrade'   => ($finalgrade !== null),
        ];
    }

    /**
     * Queue an activity-record push to iMIS.
     *
     * @param string $toggle         Config name of the write toggle governing this push.
     * @param string $imisid         iMIS contact ID (from the configured Moodle user field).
     * @param string $coursenum      Numeric Moodle course id (MoodleUpdate productID).
     * @param float  $creditvalue    Number of credit hours awarded.
     * @param string $startdate      Course start date (Y-m-d).
     * @param string $completiondate Course completion date (Y-m-d).
     * @param string $grantdate      Date credit hours were granted (Y-m-d).
     * @param string $status         Pass or Fail.
     * @param float  $score          Last test score.
     * @return void
     */
    private static function queue_activity_push(
        string $toggle,
        string $imisid,
        string $coursenum,
        float $creditvalue,
        string $startdate,
        string $completiondate,
        string $grantdate,
        string $status,
        float $score
    ): void {
        $credittype = get_config('local_imisbridge', 'credit_type');
        if (empty($credittype)) {
            $credittype = 'CEU';
        }

        $task = new task\push_activity_task();
        $task->set_custom_data([
            'toggle'         => $toggle,
            'imisid'         => $imisid,
            'coursenum'      => $coursenum,
            'credittype'     => $credittype,
            'creditvalue'    => $creditvalue,
            'startdate'      => $startdate,
            'completiondate' => $completiondate,
            'grantdate'      => $grantdate,
            'status'         => $status,
            'score'          => $score,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * The learner's final course grade as a percentage.
     *
     * Sent on completion so a completion event firing after a quiz does not
     * overwrite the recorded score with 0. Returns 0 when the course is not
     * yet graded or has no gradable content.
     *
     * @param int $courseid The Moodle course ID.
     * @param int $userid   The Moodle user ID.
     * @return float
     */
    private static function course_score(int $courseid, int $userid): float {
        return self::course_score_detail($courseid, $userid)->score;
    }

    /**
     * Detailed view of the learner's final course grade, for the "Push completion
     * (test)" control to explain how a score was resolved (why it is 0, in
     * particular). Reports the course-total grade item and the learner's gradebook
     * grade, not any single activity attempt.
     *
     * @param int $courseid The Moodle course ID.
     * @param int $userid   The Moodle user ID (0 when no single user matched).
     * @return \stdClass {score:float, itemid:?int, grademax:?float, finalgrade:?float,
     *                    hasgrade:bool}
     */
    public static function course_score_detail(int $courseid, int $userid): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $gradeitem = \grade_item::fetch_course_item($courseid);
        if (!$gradeitem) {
            // No course-total grade item: the course has no gradable content.
            return (object)[
                'score'      => 0.0,
                'itemid'     => null,
                'grademax'   => null,
                'finalgrade' => null,
                'hasgrade'   => false,
            ];
        }

        $grade      = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
        $finalgrade = ($grade && $grade->finalgrade !== null) ? (float)$grade->finalgrade : null;
        $score = ($finalgrade === null)
            ? 0.0
            : self::calculate_percentage($finalgrade, (float)$gradeitem->grademax);

        return (object)[
            'score'      => $score,
            'itemid'     => (int)$gradeitem->id,
            'grademax'   => (float)$gradeitem->grademax,
            'finalgrade' => $finalgrade,
            'hasgrade'   => ($finalgrade !== null),
        ];
    }

    /**
     * Percentage score for an attempt, rounded to two decimal places.
     *
     * Returns 0 when the quiz is not gradable, guarding against divide-by-zero.
     *
     * @param float $attemptsum The learner's summed grade for the attempt.
     * @param float $quizsum    The maximum summed grade for the quiz.
     * @return float
     */
    public static function calculate_percentage(float $attemptsum, float $quizsum): float {
        if ($quizsum <= 0) {
            return 0.0;
        }
        return round(($attemptsum / $quizsum) * 100, 2);
    }

    /**
     * The learner's raw quiz grade for an attempt, rescaled to the quiz's maximum
     * grade — the actual grade (e.g. 8/10, 80/100), not a percentage — so quiz
     * scores match the module notes, which carry the raw grade.
     *
     * Mirrors Moodle's own quiz_rescale_grade (attempt marks / max marks * max
     * grade), rounded to two places. Returns 0 when the quiz is ungraded: a max
     * grade of 0 makes the quiz grade item GRADE_TYPE_NONE (no scaled grade), and a
     * non-positive max marks guards against divide-by-zero.
     *
     * @param float $attemptsum The learner's summed marks for the attempt.
     * @param float $maxmarks   The quiz's maximum summed marks (quiz->sumgrades).
     * @param float $maxgrade   The quiz's maximum grade (quiz->grade); 0 is ungraded.
     * @return float
     */
    public static function quiz_raw_grade(float $attemptsum, float $maxmarks, float $maxgrade): float {
        if ($maxmarks <= 0) {
            return 0.0;
        }
        // A max grade of 0 is an ungraded quiz (GRADE_TYPE_NONE); the rescale below
        // then yields 0, so no arbitrary question-weight points reach the record.
        return round(($attemptsum / $maxmarks) * $maxgrade, 2);
    }

    /**
     * Map a score against a pass mark to the iMIS 'Pass'/'Fail' status.
     *
     * @param float $score    The percentage score.
     * @param float $passmark The pass threshold.
     * @return string 'Pass' or 'Fail'.
     */
    public static function passfail_status(float $score, float $passmark): string {
        return ($score >= $passmark) ? 'Pass' : 'Fail';
    }

    /**
     * Resolve the Moodle course number sent to iMIS as MoodleUpdate's productID.
     *
     * Per the ATS bridge spec, productID is the **numeric Moodle Course ID**: iMIS
     * maps it to the iMIS product code through the MOODLE_PRODUCTS lookup table
     * (Code = iMIS product code, Description = numeric Moodle course id), and the
     * vendor's existing activity history is keyed on it. It is NOT the course
     * idnumber or shortname.
     *
     * @param \stdClass $course Course record with the numeric id.
     * @return string
     */
    private static function course_number(\stdClass $course): string {
        return (string)$course->id;
    }

    /**
     * Read the credit value from the configured course custom field.
     *
     * Returns 0 when no field is configured or the field holds a non-numeric value,
     * preserving the previous default behaviour.
     *
     * @param int $courseid The Moodle course ID.
     * @return float
     */
    private static function course_credit_value(int $courseid): float {
        $shortname = get_config('local_imisbridge', 'credit_field');
        if (empty($shortname)) {
            return 0.0;
        }

        $handler = \core_course\customfield\course_handler::create();
        foreach ($handler->get_instance_data($courseid, true) as $fielddata) {
            if ($fielddata->get_field()->get('shortname') === $shortname) {
                $value = $fielddata->get_value();
                return is_numeric($value) ? (float)$value : 0.0;
            }
        }

        return 0.0;
    }
}
