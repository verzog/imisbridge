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
 * Scheduled task definitions for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [

    // Nightly full enrollment sync — all users.
    [
        'classname' => '\local_imisbridge\task\sync_enrollments_task',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Nightly cancellation sync — all users.
    [
        'classname' => '\local_imisbridge\task\sync_cancellations_task',
        'blocking'  => 0,
        'minute'    => '15',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Nightly group sync — only records changed since last run.
    [
        'classname' => '\local_imisbridge\task\sync_groups_task',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Nightly tutorial-group sync — mirrors the iMIS MoodleTutorialGroup panel.
    // Admins can change the schedule in Site admin > Server > Scheduled tasks.
    [
        'classname' => '\local_imisbridge\task\sync_tutorial_groups',
        'blocking'  => 0,
        'minute'    => '45',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Daily audit-log cleanup — prunes rows past the retention period.
    [
        'classname' => '\local_imisbridge\task\cleanup_log_task',
        'blocking'  => 0,
        'minute'    => '45',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
