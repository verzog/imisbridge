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
 * Event observer definitions for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // Fire on every user login — syncs enrollments, cancellations, and groups.
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => '\local_imisbridge\observer::user_loggedin',
        'priority'  => 200,
        'internal'  => false,
    ],

    // Fire when a course is fully completed.
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_imisbridge\observer::course_completed',
        'priority'  => 200,
        'internal'  => false,
    ],

    // Fire when a quiz attempt is submitted.
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_imisbridge\observer::quiz_attempt_submitted',
        'priority'  => 200,
        'internal'  => false,
    ],

    // Fire when a course-module completion state changes (opt-in; graded modules only).
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback'  => '\local_imisbridge\observer::course_module_completion_updated',
        'priority'  => 200,
        'internal'  => false,
    ],
];
