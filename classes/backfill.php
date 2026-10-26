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
 * Back-fill of existing course results to iMIS.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Queues pushes for results that already exist in one course, as if each had just
 * happened: course completions (with their real completion date) and activity scores
 * for the types ticked in *Push activity scores*.
 *
 * Pushes are queued as the usual adhoc tasks, so they honour the same toggles, push
 * course id overrides and retry behaviour as live pushes, and are sent by cron.
 * Activity scores go through the live claim, so a score already sent and unchanged
 * is not sent again. A dry run (the default) reports what would be queued and
 * changes nothing.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var bool Queue the pushes (false: report only). */
    private bool $execute;

    /** @var string|null Limit to this member's iMIS ID, or null for everyone. */
    private ?string $member;

    /** @var callable Receives each log line. */
    private $trace;

    /**
     * Build the back-fill for one course.
     *
     * @param int           $courseid The Moodle course ID.
     * @param bool          $execute  Queue the pushes; false reports only (dry run).
     * @param string|null   $member   Only this member's iMIS ID, or null for everyone.
     * @param callable|null $trace    Receives each log line (default: discard).
     */
    public function __construct(int $courseid, bool $execute, ?string $member = null, ?callable $trace = null) {
        global $DB;
        $this->course  = $DB->get_record('course', ['id' => $courseid], 'id', MUST_EXIST);
        $this->execute = $execute;
        $this->member  = ($member === null || trim($member) === '') ? null : trim($member);
        $this->trace   = $trace ?? static function (string $line): void {
            // Discard by default; the CLI passes a writer.
            return;
        };
    }

    /**
     * Queue a completion push for every learner who has completed the course.
     *
     * @return array Report: found, queued (or would queue), skipped, aborted, messages.
     */
    public function completions(): array {
        global $DB;
        $report = $this->report();

        if (!util::is_enabled('push_completions')) {
            return $this->abort($report, 'Push completions is off in the plugin settings; nothing queued.');
        }
        if (!util::is_configured()) {
            return $this->abort($report, 'The connection provider is not configured; nothing queued.');
        }

        $rows = $DB->get_records_select(
            'course_completions',
            'course = :course AND timecompleted IS NOT NULL',
            ['course' => $this->course->id],
            'timecompleted, id',
            'id, userid, timecompleted, timestarted, timeenrolled'
        );
        foreach ($rows as $row) {
            $imisid = $this->imis_id((int)$row->userid, $report);
            if ($imisid === null) {
                continue;
            }
            $report['found']++;

            // Send the real dates rather than today's, as the completion happened then.
            $data = observer::completion_push_data($imisid, $this->course, (int)$row->userid);
            $completed = date('Y-m-d', (int)$row->timecompleted);
            $started   = (int)($row->timestarted ?: $row->timeenrolled ?: $row->timecompleted);
            $data->startdate      = date('Y-m-d', $started);
            $data->completiondate = $completed;
            $data->grantdate      = $completed;

            if ($this->execute) {
                $task = new task\push_activity_task();
                $task->set_custom_data($data);
                \core\task\manager::queue_adhoc_task($task);
            }
            $report['queued']++;
            $this->log($report, sprintf(
                'iMIS %s (user %d): %s completion on %s, score %s, as course %s',
                $imisid,
                (int)$row->userid,
                $this->execute ? 'queued' : 'would queue',
                $completed,
                round((float)$data->score, 2),
                $data->coursenum
            ));
        }
        return $report;
    }

    /**
     * Queue a score push for every graded learner in the course's activities of the
     * types ticked in *Push activity scores*, unless that score was already sent.
     *
     * @return array Report: found, queued (or would queue), skipped, aborted, messages.
     */
    public function scores(): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $report = $this->report();

        $modules = util::score_push_modules();
        if (empty($modules)) {
            return $this->abort($report, 'No activity types are ticked in Push activity scores; nothing queued.');
        }
        if (!util::is_configured()) {
            return $this->abort($report, 'The connection provider is not configured; nothing queued.');
        }

        $items = \grade_item::fetch_all([
            'courseid'   => $this->course->id,
            'itemtype'   => 'mod',
            'itemnumber' => 0,
        ]) ?: [];
        foreach ($items as $item) {
            if (!in_array($item->itemmodule, $modules, true)) {
                continue;
            }
            $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
            if (!$cm) {
                continue;
            }
            $grades = $DB->get_records_select(
                'grade_grades',
                'itemid = :itemid AND finalgrade IS NOT NULL',
                ['itemid' => $item->id],
                'id',
                'id, userid, finalgrade'
            );
            foreach ($grades as $grade) {
                $imisid = $this->imis_id((int)$grade->userid, $report);
                if ($imisid === null) {
                    continue;
                }
                $report['found']++;
                $label = sprintf(
                    'iMIS %s (user %d): %s "%s" score %s',
                    $imisid,
                    (int)$grade->userid,
                    $item->itemmodule,
                    format_string($cm->name),
                    round((float)$grade->finalgrade, 2)
                );

                $pending = $this->execute
                    ? observer::queue_score_push((int)$grade->userid, $imisid, $cm, $item, (int)$grade->id)
                    : self::score_unsent((int)$grade->userid, (int)$item->id, (float)$grade->finalgrade);
                if ($pending) {
                    $report['queued']++;
                    $this->log($report, $label . ($this->execute ? ' queued' : ' would queue'));
                } else {
                    $report['skipped']++;
                    $this->log($report, $label . ' already sent; skipped');
                }
            }
        }
        return $report;
    }

    /**
     * Whether a score differs from the one last sent for this learner and grade item
     * (read-only; the same test the live claim applies).
     *
     * @param int   $userid     The Moodle user ID.
     * @param int   $itemid     The grade item ID.
     * @param float $finalgrade The current grade.
     * @return bool
     */
    private static function score_unsent(int $userid, int $itemid, float $finalgrade): bool {
        global $DB;
        $record = $DB->get_record('local_imisbridge_scorepush', ['userid' => $userid, 'itemid' => $itemid]);
        return !$record || $record->finalgrade === null || grade_floats_different((float)$record->finalgrade, $finalgrade);
    }

    /**
     * The learner's iMIS ID, or null when they are filtered out or have none (logged).
     *
     * @param int   $userid The Moodle user ID.
     * @param array $report The running report, by reference.
     * @return string|null
     */
    private function imis_id(int $userid, array &$report): ?string {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, idnumber, email');
        $raw = $user ? trim((string)($user->{util::imis_id_source()} ?? '')) : '';
        $imisid = $user ? util::get_imis_id($user) : '';
        if ($this->member !== null && strcasecmp($imisid, $this->member) !== 0) {
            return null;
        }
        if ($imisid === '') {
            $report['skipped']++;
            $reason = ($raw === '') ? 'no iMIS ID' : "'{$raw}' is not in the iMIS ID format";
            $this->log($report, "user {$userid}: {$reason}; skipped");
            return null;
        }
        return $imisid;
    }

    /**
     * A new, empty report.
     *
     * @return array
     */
    private function report(): array {
        return [
            'courseid' => (int)$this->course->id,
            'execute'  => $this->execute,
            'found'    => 0,
            'queued'   => 0,
            'skipped'  => 0,
            'aborted'  => false,
            'messages' => [],
        ];
    }

    /**
     * Mark the report aborted with a reason.
     *
     * @param array  $report The report.
     * @param string $reason Why nothing was queued.
     * @return array The report.
     */
    private function abort(array $report, string $reason): array {
        $report['aborted'] = true;
        $this->log($report, $reason);
        return $report;
    }

    /**
     * Record a line in the report and pass it to the trace.
     *
     * @param array  $report The running report, by reference.
     * @param string $line   The message.
     * @return void
     */
    private function log(array &$report, string $line): void {
        $report['messages'][] = $line;
        ($this->trace)($line);
    }
}
