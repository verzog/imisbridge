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
 * Upgrade steps for local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply the local_membershipbridge upgrade steps.
 *
 * The component was renamed from local_imisbridge in 2.0.0 and installs fresh from
 * db/install.xml (db/install.php carries an existing local_imisbridge site over). The
 * upgrade history before the rename belongs to local_imisbridge.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_membershipbridge_upgrade($oldversion) {
    if ($oldversion < 2026102901) {
        // 2.2.0 drops the built-in SCCA course lookup query and /REG suffix; keep
        // this site on the values it was using.
        \local_membershipbridge\former_defaults::pin();
        upgrade_plugin_savepoint(true, 2026102901, 'local', 'membershipbridge');
    }

    return true;
}
