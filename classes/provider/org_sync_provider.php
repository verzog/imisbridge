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
 * Contract for a provider that also offers inbound order/group sync.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\provider;

/**
 * A provider that can also pull orders/cancellations/groups into Moodle.
 *
 * This is the middleware-style capability (the ATS SOAP bridge offers it): the
 * external system tells Moodle who to enrol, unenrol and group. A provider that
 * only receives pushed records (such as a direct REST API) implements
 * {@see provider_interface} alone, and {@see provider_interface::supports()}
 * returns false for CAP_ORG_SYNC.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface org_sync_provider extends provider_interface {
    /**
     * Pull new orders from the external system and enrol users in Moodle.
     *
     * @param string|null $contactid A single member ID, or null for all users.
     * @return mixed The provider's raw result.
     */
    public function sync_orders(?string $contactid = null): mixed;

    /**
     * Pull cancelled orders from the external system and unenrol users in Moodle.
     *
     * @param string|null $contactid A single member ID, or null for all users.
     * @return mixed The provider's raw result.
     */
    public function sync_cancelled_orders(?string $contactid = null): mixed;

    /**
     * Update Moodle group membership from the external system.
     *
     * @param string|null $contactid   A single member ID (optional).
     * @param string|null $courseid    A single Moodle course ID (optional).
     * @param string|null $lastupdated UTC datetime lower bound (optional).
     * @return mixed The provider's raw result.
     */
    public function update_groups(?string $contactid = null, ?string $courseid = null, ?string $lastupdated = null): mixed;

    /**
     * Run read-only connection diagnostics and return a human-readable report.
     *
     * @param string|null $contactid Optional member ID to exercise an authenticated lookup.
     * @return string The diagnostic report.
     */
    public function diagnostics(?string $contactid): string;
}
