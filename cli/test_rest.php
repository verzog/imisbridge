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
 * Read-only connection test for the direct iMIS REST transport.
 *
 * Exercises authentication only (the OAuth password grant), and optionally a
 * read of a contact's activity rows when an iMIS ID is supplied. It writes
 * nothing to iMIS, so it is safe to run as a first check against any instance.
 *
 * Usage:
 *   php local/imisbridge/cli/test_rest.php
 *   php local/imisbridge/cli/test_rest.php --imisid=jsmith
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['imisid' => '', 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Read-only iMIS REST connection test.

Authenticates (OAuth password grant) and, when --imisid is given, reads that
contact's activity rows. No data is written to iMIS or Moodle.

Options:
  --imisid=ID   Also read the activity rows for this iMIS contact ID.
  -h, --help    Print this help.

Example:
  php local/imisbridge/cli/test_rest.php --imisid=jsmith
");
    exit(0);
}

try {
    $client = new \local_imisbridge\rest_client();

    cli_heading('Authentication (OAuth password grant)');
    $client->get_token();
    cli_writeln('Token acquired. Connectivity and service-account credentials verified.');

    if ($options['imisid'] !== '') {
        cli_heading('Activity rows for ' . $options['imisid'] . ' (read-only)');
        $rows = $client->get_activities($options['imisid']);
        cli_writeln(count($rows) . ' activity row(s) returned.');
        foreach ($rows as $row) {
            $type = \local_imisbridge\rest_client::property($row, 'ACTIVITY_TYPE');
            $uf1  = \local_imisbridge\rest_client::property($row, 'UF_1');
            $uf3  = \local_imisbridge\rest_client::property($row, 'UF_3');
            $seqn = \local_imisbridge\rest_client::property($row, 'SEQN');
            cli_writeln(sprintf('  SEQN=%s  type=%s  UF_1(course)=%s  UF_3(status)=%s',
                (string)$seqn, (string)$type, (string)$uf1, (string)$uf3));
        }
    } else {
        cli_writeln("\nFor a read check, re-run with --imisid=<valid iMIS ID> to list that "
            . "contact's activity rows.");
    }

    exit(0);
} catch (\Throwable $e) {
    cli_error('REST connection test failed: ' . $e->getMessage());
}
