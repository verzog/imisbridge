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
 * Adhoc task: push a course-module completion to iMIS as a MOODLENOTE record.
 *
 * Queued (opt-in) when a graded module's completion changes, so the external call
 * runs in cron rather than blocking the learner's request. Always a supplementary
 * MOODLENOTE activity — never the canonical course-completion record.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Pushes one course-module completion to iMIS as a MOODLENOTE activity.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_module_note_task extends \core\task\adhoc_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_push_module_note', 'local_imisbridge');
    }

    /**
     * Executes the module-note push. Exceptions propagate so Moodle retries with backoff.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        if (empty($data->imisid)) {
            return;
        }
        // Re-check the iMIS ID format: it may have been set to Digits only after queueing.
        if (!\local_imisbridge\util::is_valid_imis_id((string)$data->imisid)) {
            mtrace('Membership Bridge: ' . $data->imisid . ' is not in the iMIS ID format; skipping.');
            return;
        }

        // Re-check the opt-in toggle: an admin may have disabled it after queueing.
        if (!\local_imisbridge\util::is_enabled_optin('push_module_completions')) {
            mtrace('Membership Bridge: module-completion push disabled; skipping ' . $data->imisid . '.');
            return;
        }

        if (!\local_imisbridge\util::is_configured()) {
            mtrace('Membership Bridge: external system not configured; skipping ' . $data->imisid . '.');
            return;
        }

        $cmid = (int)($data->cmid ?? 0);
        mtrace('Membership Bridge: pushing module note for ' . $data->imisid . ' (cm ' . $cmid . ')...');

        // The active provider records the supplementary note (never the canonical
        // course-completion record).
        \local_imisbridge\provider\manager::instance()->push_module_note($data);

        mtrace('Membership Bridge: module note pushed for ' . $data->imisid . '.');
    }
}
