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
 * One tutorial-group membership row read from the external system.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

/**
 * A single "this person is in this group for this course" assignment.
 *
 * Mirrors one row of the iMIS MoodleTutorialGroup panel: the iMIS contact id, the
 * event/product code the group belongs to, and the group name. It is the transport-
 * agnostic unit a {@see reader} returns and {@see group_sync} consumes, so the sync
 * engine never sees iMIS REST (or any other source) shapes directly.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class membership_row {
    /** @var string The iMIS contact id (resolved to a Moodle user by the configured id source). */
    public string $imisid;

    /** @var string The event/product code; must map to a Moodle course via the course resolver. */
    public string $code;

    /** @var string The group name; becomes the Moodle group name (trimmed, matched case-insensitively). */
    public string $groupname;

    /**
     * @param string $imisid    The iMIS contact id.
     * @param string $code      The event/product code.
     * @param string $groupname The group name.
     */
    public function __construct(string $imisid, string $code, string $groupname) {
        $this->imisid    = trim($imisid);
        $this->code      = trim($code);
        $this->groupname = trim($groupname);
    }
}
