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
 * Chooses how event codes are mapped to Moodle courses.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use local_membershipbridge\rest_client;

/**
 * Builds the {@see course_resolver} chosen by the *Course mapping* setting.
 *
 * The tutorial group sync and the REST registration sync both map codes through
 * this, so they always agree on which course an event belongs to.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_mapping {
    /** @var string Map through a lookup IQA in iMIS (the default). */
    public const SOURCE_IQA = 'iqa';

    /** @var string Map to the Moodle course whose ID number is the code. */
    public const SOURCE_IDNUMBER = 'idnumber';

    /**
     * The configured mapping source; anything unrecognised means the iMIS lookup.
     *
     * @return string One of the SOURCE_* constants.
     */
    public static function source(): string {
        $source = (string)get_config('local_membershipbridge', 'course_map_source');
        return $source === self::SOURCE_IDNUMBER ? self::SOURCE_IDNUMBER : self::SOURCE_IQA;
    }

    /**
     * The resolver for the configured source.
     *
     * @param rest_client $client The iMIS REST client the lookup IQA is read with.
     * @return course_resolver
     */
    public static function resolver(rest_client $client): course_resolver {
        if (self::source() === self::SOURCE_IDNUMBER) {
            return new idnumber_course_resolver();
        }
        return new rest_course_resolver($client);
    }

    /**
     * The choices for the *Course mapping* setting.
     *
     * @return array<string,string>
     */
    public static function menu(): array {
        return [
            self::SOURCE_IQA      => get_string('course_map_source_iqa', 'local_membershipbridge'),
            self::SOURCE_IDNUMBER => get_string('course_map_source_idnumber', 'local_membershipbridge'),
        ];
    }
}
