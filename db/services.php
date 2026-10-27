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
 * External web service definitions for local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_membershipbridge_get_completions_since' => [
        'classname'   => 'local_membershipbridge\\external\\get_completions_since',
        'methodname'  => 'execute',
        'description' => 'Return course completions and final course grades changed since a given time, '
            . 'for bulk pull synchronisation to iMIS.',
        'type'        => 'read',
        'capabilities' => 'local/membershipbridge:readcompletions',
        'ajax'        => false,
    ],
    'local_membershipbridge_cancel_enrolment' => [
        'classname'   => 'local_membershipbridge\\external\\cancel_enrolment',
        'methodname'  => 'execute',
        'description' => 'Cancel a user\'s enrolment in a course by suspending it and removing their role, '
            . 'without unenrolling (so grades and completion are preserved). For the inbound '
            . 'order-cancellation path; point the ATS unenrol function mapping at this.',
        'type'        => 'write',
        'capabilities' => 'local/membershipbridge:cancelenrolment',
        'ajax'        => false,
    ],
];

// A pre-built, restricted service so iMIS can be issued a token for these
// functions. Enabled, but each function reaches nothing until its capability is
// granted to the token's user (readcompletions / cancelenrolment). The cancel
// function may instead be added to the inbound core-functions service if the ATS
// uses a separate inbound token (see docs/CHANGEOVER-CHECKLIST.md).
$services = [
    'Membership bridge' => [
        'functions'       => [
            'local_membershipbridge_get_completions_since',
            'local_membershipbridge_cancel_enrolment',
        ],
        'restrictedusers' => 1,
        'enabled'         => 1,
        'shortname'       => 'local_membershipbridge',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
