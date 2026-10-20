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
 * Adhoc task: push a single Moodle activity record to the external system.
 *
 * Queued on course completion and quiz submission so the external call does not
 * block the learner's request. The active provider performs the actual write.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Pushes one completion/assessment record to the external system via the active provider.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_activity_task extends \core\task\adhoc_task {
    /**
     * Returns the task name shown in the Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_push_activity', 'local_imisbridge');
    }

    /**
     * Executes the activity push. Exceptions propagate so Moodle retries with backoff.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        if (empty($data->imisid)) {
            return;
        }

        // Re-check the governing write toggle: an admin may have disabled it after
        // this task was queued but before cron reached it.
        if (!empty($data->toggle) && !\local_imisbridge\util::is_enabled($data->toggle)) {
            mtrace('iMIS Bridge: activity push disabled in plugin settings; skipping ' . $data->imisid . '.');
            return;
        }

        if (!\local_imisbridge\util::is_configured()) {
            mtrace('iMIS Bridge: external system not configured; skipping ' . $data->imisid . '.');
            return;
        }

        mtrace('iMIS Bridge: pushing activity record for ' . $data->imisid . '...');

        // The active provider performs the write. A completion and a per-attempt
        // score are distinct operations so a score never overwrites a completion.
        $provider = \local_imisbridge\provider\manager::instance();
        if (($data->toggle ?? '') === 'push_completions') {
            $provider->push_completion($data);
        } else {
            $provider->push_score($data);
        }

        mtrace('iMIS Bridge: activity record pushed for ' . $data->imisid . '.');
    }
}
