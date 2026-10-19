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
 * Adhoc task: push a single Moodle activity record to iMIS off the request path.
 *
 * Queued on course completion and quiz submission so the external SOAP call
 * does not block the learner's request.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\task;

/**
 * Pushes one completion/assessment record to iMIS via MoodleUpdate.
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
            mtrace('iMIS Bridge: no iMIS WSDL configured; skipping ' . $data->imisid . '.');
            return;
        }

        mtrace('iMIS Bridge: pushing activity record for ' . $data->imisid . '...');

        if (\local_imisbridge\util::transport() === 'rest') {
            $this->push_rest($data);
        } else {
            $this->push_soap($data);
        }

        mtrace('iMIS Bridge: activity record pushed for ' . $data->imisid . '.');
    }

    /**
     * Push the record via the SOAP transport (ATS MoodleUpdate).
     *
     * @param \stdClass $data The task's custom data.
     * @return void
     */
    private function push_soap(\stdClass $data): void {
        $client = new \local_imisbridge\imis_client();
        $client->update_activity_record(
            imisid:          $data->imisid,
            moodlecoursenum: $data->coursenum ?? '',
            credittype:      $data->credittype ?? 'CEU',
            creditvalue:     (float)($data->creditvalue ?? 0),
            startdate:       $data->startdate ?? '',
            completiondate:  $data->completiondate ?? '',
            grantdate:       $data->grantdate ?? '',
            status:          $data->status ?? '',
            score:           (float)($data->score ?? 0)
        );
    }

    /**
     * Push the record via the direct iMIS REST transport.
     *
     * A completion upserts the canonical MOODLE activity (status COMPLETE); a quiz
     * score appends a MOODLENOTE row, so per-attempt results never overwrite the
     * course-level completion. Extra iMIS columns (credit, grant/completion dates)
     * are sent only when the operator has mapped their column names in settings.
     *
     * @param \stdClass $data The task's custom data.
     * @return void
     */
    private function push_rest(\stdClass $data): void {
        $client   = new \local_imisbridge\rest_client();
        $partyid  = (string)$data->imisid;
        $courseid = (string)($data->coursenum ?? '');
        $score    = (float)($data->score ?? 0);
        $date     = (string)($data->completiondate ?? date('Y-m-d'));
        $extra    = self::rest_extra_columns($data);

        if (($data->toggle ?? '') === 'push_completions') {
            // A course-completion event always means complete.
            $client->upsert_completion($partyid, $courseid, 'COMPLETE', $score, $date,
                'Moodle course ' . $courseid . ' completion', $extra);
        } else {
            // Per-attempt score: append a note, never touch the course-level row.
            $status = strtoupper((string)($data->status ?? ''));
            $client->add_note($partyid, $courseid, $status, $score, $date,
                'Moodle course ' . $courseid . ' score', $extra);
        }
    }

    /**
     * Map the push data onto the optional, operator-configured iMIS columns.
     *
     * Each mapping is applied only when its column-name setting is non-empty, so
     * columns whose raw names are not yet confirmed against iMIS are simply not
     * sent rather than guessed.
     *
     * @param \stdClass $data The task's custom data.
     * @return array Column name => value pairs to set on the activity.
     */
    private static function rest_extra_columns(\stdClass $data): array {
        $map = [
            'rest_col_credittype'     => $data->credittype ?? '',
            'rest_col_creditvalue'    => (float)($data->creditvalue ?? 0),
            'rest_col_grantdate'      => $data->grantdate ?? '',
            'rest_col_completiondate' => $data->completiondate ?? '',
        ];

        $extra = [];
        foreach ($map as $setting => $value) {
            $column = trim((string)get_config('local_imisbridge', $setting));
            if ($column !== '') {
                $extra[$column] = $value;
            }
        }
        return $extra;
    }
}
