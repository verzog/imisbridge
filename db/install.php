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
 * Post-install: carry a site over from the plugin's former name, local_imisbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Copy settings, records and permissions from local_imisbridge, when it is installed.
 *
 * A site carried over also keeps the course lookup values local_imisbridge used when
 * its settings were blank ({@see \local_membershipbridge\former_defaults}). A new
 * site gets no built-in lookup.
 *
 * @return bool
 */
function xmldb_local_membershipbridge_install() {
    if (\local_membershipbridge\migration::from_imisbridge()) {
        \local_membershipbridge\former_defaults::pin();
    }
    return true;
}
