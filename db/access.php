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
 * Capability definitions for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Read course completions and grades through the bulk export web service.
    // This exposes a site-wide export of learner identifiers, completion history
    // and grades, so it carries the personal-data risk flag.
    //
    // Granted to no role by default. The check runs in the system context, so to
    // authorise the dedicated web service user, assign them a role that holds
    // this capability AT THE SYSTEM CONTEXT (Site administration > Users >
    // Permissions > Assign system roles) - a role granted in the user's own
    // context does not satisfy a system-context capability check. Adding the user
    // to the restricted service's allowed-users list controls service access but
    // does not, on its own, grant this capability.
    'local/imisbridge:readcompletions' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Cancel a user's enrolment through the inbound web service: suspend the
    // enrolment and remove their role in the course. The check runs in the course
    // context, so a system-context service role (as the changeover recipe assigns)
    // satisfies it for every course. It is non-destructive - nothing is unenrolled
    // or deleted - so it carries no data-loss risk flag, unlike core unenrol.
    //
    // Granted to no role by default. Assign it to the inbound service-account role.
    'local/imisbridge:cancelenrolment' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [],
    ],
];
