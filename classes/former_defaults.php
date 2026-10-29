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
 * Keeps an existing site on the setting values it relied on before 2.2.0.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Writes the pre-2.2.0 built-in values into settings an existing site left unset.
 *
 * Before 2.2.0 the course lookup fell back to SCCA's query and `/REG` suffix when
 * those settings were blank or unset. 2.2.0 drops the built-in values so a new site
 * is not pointed at SCCA's iMIS; this keeps an upgraded (or migrated) site behaving
 * exactly as before by storing the value it was using. A value the site set itself is
 * never changed.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class former_defaults {
    /** @var string The course lookup IQA used when the setting was blank or unset. */
    public const COURSE_IQA = '$/_SCCA/Events/MoodleCourseLookup';

    /** @var string The course code suffix used when the setting was unset. */
    public const COURSE_SUFFIX = '/REG';

    /**
     * Store the former built-in values where the site was relying on them.
     *
     * The query fell back when blank or unset; the suffix only when unset (a blank
     * suffix meant "no suffix" and is kept).
     *
     * @return string[] The names of the settings written.
     */
    public static function pin(): array {
        $written = [];

        $query = get_config('local_membershipbridge', 'tutorial_course_iqa');
        if ($query === false || trim((string)$query) === '') {
            set_config('tutorial_course_iqa', self::COURSE_IQA, 'local_membershipbridge');
            $written[] = 'tutorial_course_iqa';
        }

        if (get_config('local_membershipbridge', 'tutorial_course_code_suffix') === false) {
            set_config('tutorial_course_code_suffix', self::COURSE_SUFFIX, 'local_membershipbridge');
            $written[] = 'tutorial_course_code_suffix';
        }

        return $written;
    }
}
