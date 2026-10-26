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
 * Back-fill one course's existing results to iMIS.
 *
 * Queues a push for each existing course completion (with its real completion
 * date) and/or each activity score of a type ticked in Push activity scores, as if
 * it had just happened. A dry run by default; --execute queues the pushes, which
 * cron then sends (or run admin/cli/adhoc_task.php --execute to send them now).
 *
 * Usage:
 *   php local/imisbridge/cli/backfill.php --course=19
 *   php local/imisbridge/cli/backfill.php --course=19 --type=all --execute
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['course' => 0, 'type' => 'completions', 'member' => '', 'execute' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

$types = ['completions', 'scores', 'all'];
if ($options['help'] || (int)$options['course'] <= 0 || !in_array($options['type'], $types, true)) {
    cli_writeln("Back-fill one course's existing results to iMIS.

Queues a push for each existing course completion (with its real completion date)
and/or each activity score of a type ticked in Push activity scores, as if it had
just happened. Pushes honour the plugin's push toggles and push course id overrides,
and a score already sent and unchanged is not sent again.

Without --execute this is a dry run: it lists what would be queued and changes
nothing. With --execute the pushes are queued; cron sends them, or run
admin/cli/adhoc_task.php --execute to send them now.

Options:
  --course=ID        The Moodle course ID (required).
  --type=TYPE        completions (default), scores or all.
  --member=IMISID    Only this member.
  --execute          Queue the pushes (default: dry run).
  -h, --help         Print this help.

Example:
  php local/imisbridge/cli/backfill.php --course=19
  php local/imisbridge/cli/backfill.php --course=19 --type=all --execute
");
    exit(0);
}

$backfill = new \local_imisbridge\backfill(
    (int)$options['course'],
    (bool)$options['execute'],
    $options['member'] !== '' ? (string)$options['member'] : null,
    static function (string $line): void {
        cli_writeln('  ' . $line);
    }
);

cli_writeln(($options['execute'] ? 'Back-fill' : 'Dry run (nothing queued)') . ' for course ' . (int)$options['course']);
foreach (['completions', 'scores'] as $type) {
    if ($options['type'] !== 'all' && $options['type'] !== $type) {
        continue;
    }
    cli_writeln(ucfirst($type) . ':');
    $report = ($type === 'completions') ? $backfill->completions() : $backfill->scores();
    cli_writeln(sprintf(
        '  %s: %d found, %d %s, %d skipped%s',
        $type,
        $report['found'],
        $report['queued'],
        $options['execute'] ? 'queued' : 'would be queued',
        $report['skipped'],
        $report['aborted'] ? ' (aborted)' : ''
    ));
}
if ($options['execute']) {
    cli_writeln('Queued pushes are sent by cron; run admin/cli/adhoc_task.php --execute to send them now.');
}
