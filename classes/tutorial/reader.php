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
 * Contract for a source of tutorial-group membership rows.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

/**
 * A source the group sync reads its desired membership from.
 *
 * Keeping this an interface makes {@see group_sync} transport-agnostic: the direct
 * REST reader ({@see rest_reader}) is the one shipped today, but a SOAP-sourced or
 * any other reader can be dropped in without touching the sync engine, and tests
 * inject a fake reader with no network.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface reader {
    /**
     * Return every current tutorial-group membership row from the source.
     *
     * The source is authoritative: the sync mirrors exactly these rows, adding and
     * removing Moodle group members to match. An implementation must therefore read
     * the complete set (paging to the end), and must throw rather than return an
     * empty array on a read failure — an empty result is treated by the engine as
     * "the source really has no rows", which suppresses all removals.
     *
     * @return membership_row[] Every membership row, in no particular order.
     */
    public function fetch_rows(): array;
}
