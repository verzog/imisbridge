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
 * Adhoc task: push an activity score to iMIS as a MOODLENOTE record.
 *
 * Queued when an activity of a type ticked in the score-push checklist is graded,
 * so the external call runs in cron rather than blocking the grader's request.
 * Always a supplementary MOODLENOTE activity — never the canonical
 * course-completion record.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Pushes one activity score to iMIS as a MOODLENOTE activity.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_score_note_task extends \core\task\adhoc_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_push_score_note', 'local_imisbridge');
    }

    /**
     * Executes the score-note push. Exceptions propagate so Moodle retries with backoff.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $data = $this->get_custom_data();

        if (empty($data->imisid)) {
            return;
        }

        // Re-check the checklist: an admin may have unticked this type after queueing.
        if (!in_array((string)($data->modname ?? ''), \local_imisbridge\util::score_push_modules(), true)) {
            mtrace('iMIS Bridge: score push not enabled for ' . ($data->modname ?? '?') . '; skipping '
                . $data->imisid . '.');
            return;
        }

        if (!\local_imisbridge\util::is_configured()) {
            mtrace('iMIS Bridge: external system not configured; skipping ' . $data->imisid . '.');
            return;
        }

        // A later grade for the same learner and activity supersedes this one: when a
        // burst of regrades queued several tasks, send only the latest value.
        $latest = $DB->get_record('local_imisbridge_scorepush', [
            'userid' => (int)($data->userid ?? 0),
            'itemid' => (int)($data->itemid ?? 0),
        ]);
        if ($latest && grade_floats_different((float)$latest->finalgrade, (float)$data->score)) {
            mtrace('iMIS Bridge: score for ' . $data->imisid . ' (cm ' . (int)$data->cmid
                . ') superseded by a later grade; skipping.');
            return;
        }

        mtrace('iMIS Bridge: pushing score note for ' . $data->imisid . ' (cm ' . (int)$data->cmid . ')...');

        // The active provider records the supplementary note (never the canonical
        // course-completion record).
        \local_imisbridge\provider\manager::instance()->push_module_note($data);

        mtrace('iMIS Bridge: score note pushed for ' . $data->imisid . '.');
    }
}
