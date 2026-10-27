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
 * Read-only connection test for local_imisbridge.
 *
 * Calls only non-mutating operations (GetBridgeSettings, and optionally
 * MoodleGetUserProfile for a supplied iMIS ID) so connectivity, credentials
 * and the WSDL can be verified against any environment — including production —
 * without writing any data.
 *
 * Usage:
 *   php local/imisbridge/cli/test_connection.php
 *   php local/imisbridge/cli/test_connection.php --imisid=12345
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
    cli_writeln("Read-only Membership Bridge connection test.

Runs GetBridgeSettings, and MoodleGetUserProfile when --imisid is given.
No data is written to iMIS or Moodle.

Options:
  --imisid=ID   Also look up the iMIS contact with this ID.
  -h, --help    Print this help.

Example:
  php local/imisbridge/cli/test_connection.php --imisid=12345
");
    exit(0);
}

try {
    $client = new \local_imisbridge\imis_client();

    // Show where requests will actually be sent before making any call, so a
    // WSDL that advertises the wrong environment is caught before testing.
    $override = \local_imisbridge\imis_client::get_effective_endpoint();
    if ($override !== null) {
        cli_writeln(get_string('endpoint_override_active', 'local_imisbridge', $override));
    } else {
        $wsdladdr = \local_imisbridge\imis_client::read_wsdl_endpoint();
        if ($wsdladdr !== null) {
            cli_writeln(get_string('endpoint_from_wsdl', 'local_imisbridge', $wsdladdr));
        } else {
            cli_writeln(get_string('endpoint_from_wsdl_unknown', 'local_imisbridge'));
        }
    }
    cli_writeln('');

    // GetBridgeSettings verifies connectivity and the WSDL, and includes the
    // AuthToken when one is configured. The definitive credential check is the
    // MoodleGetUserProfile lookup below.
    cli_heading('GetBridgeSettings (connectivity; AuthToken sent if configured)');
    cli_writeln(var_export($client->get_bridge_settings(), true));

    if ($options['imisid'] !== '') {
        // MoodleGetUserProfile is a secured call, so this exercises the AuthToken.
        cli_heading('MoodleGetUserProfile (' . $options['imisid'] . ')');
        cli_writeln(var_export($client->get_contact_by_id($options['imisid']), true));
        cli_writeln("\nConnectivity and credentials (AuthToken) verified.");
    } else {
        cli_writeln("\nConnectivity and WSDL verified (the AuthToken was included if configured). "
            . "For a definitive credential check, re-run with --imisid=<valid iMIS ID> to exercise "
            . "an authenticated profile lookup.");
    }

    exit(0);
} catch (\Throwable $e) {
    cli_error('Connection test failed: ' . $e->getMessage());
}
