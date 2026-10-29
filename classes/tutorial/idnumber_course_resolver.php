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
 * Resolves a code to the Moodle course whose ID number is that code.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

/**
 * Moodle-side {@see course_resolver}: a code maps to the course whose *Course ID
 * number* is that code, so no lookup has to be built in the membership system.
 *
 * Chosen with the *Course mapping* setting. As with {@see rest_course_resolver}, the
 * *Course mapping overrides* setting is checked first, and a code resolves only to a
 * course that exists. The ID number is matched exactly; if more than one course has
 * it, the code is left unmapped rather than guessed.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class idnumber_course_resolver implements course_resolver {
    /** @var array<string,int|null> Per-run cache of code => resolved course id (or null). */
    private array $cache = [];

    /** @var array<string,int> Course mapping overrides: lower-case code => course id. */
    private array $overrides;

    /**
     * Build the resolver, reading the course mapping overrides from settings.
     */
    public function __construct() {
        $this->overrides = rest_course_resolver::parse_overrides(
            (string)get_config('local_membershipbridge', 'course_map_overrides')
        );
    }

    /**
     * Resolve a code to the course with that ID number.
     *
     * @param string $code The event/product code.
     * @return int|null The course id, or null when unmapped.
     */
    public function resolve_courseid(string $code): ?int {
        global $DB;

        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (array_key_exists($code, $this->cache)) {
            return $this->cache[$code];
        }

        $override = $this->overrides[\core_text::strtolower($code)] ?? null;
        if ($override !== null) {
            $this->cache[$code] = $DB->record_exists('course', ['id' => $override]) ? $override : null;
            return $this->cache[$code];
        }

        $ids = $DB->get_fieldset_select('course', 'id', 'idnumber = :idnumber AND id <> :siteid', [
            'idnumber' => $code,
            'siteid'   => SITEID,
        ]);
        $this->cache[$code] = count($ids) === 1 ? (int)reset($ids) : null;
        return $this->cache[$code];
    }
}
