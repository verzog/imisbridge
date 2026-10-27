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
 * Contract for turning an event/product code into a Moodle course id.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

/**
 * Resolves an event/product code to the Moodle course it maps to.
 *
 * This is deliberately a one-method interface so the code→course mapping is a
 * single replaceable function. Today it reads the iMIS moodle_products lookup
 * ({@see rest_course_resolver}); when the Causeis program rework changes where
 * program-to-course links live, only a new implementation is needed, not changes
 * to {@see group_sync}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface course_resolver {
    /**
     * Resolve one code to a Moodle course id.
     *
     * Must return null (not throw, not guess) when the code has no mapping or the
     * mapped course does not exist, so the sync can skip-and-log that code and carry
     * on with the rest. A non-null result must be a course that exists.
     *
     * @param string $code The event/product code.
     * @return int|null The Moodle course id, or null when unmapped/not found.
     */
    public function resolve_courseid(string $code): ?int;
}
