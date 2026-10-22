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
 * Resolves a code to a Moodle course via the iMIS moodle_products lookup IQA.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

use local_imisbridge\rest_client;

/**
 * Direct-REST {@see course_resolver}: reads the moodle_products lookup IQA.
 *
 * The IQA (default '$/_SCCA/Events/MoodleCourseLookup') takes the event/product
 * code as its one required prompt and returns the `{code}/REG` lookup row, whose
 * Description column holds the numeric Moodle course id (confirmed on sccadev,
 * 22/10/2026: ATS_BRIDGE/REG -> Description 7). The query name and the result
 * column are settings so this can follow the Causeis program rework without code
 * changes. Resolution is deliberately conservative: it returns a course id only
 * when the lookup yields a positive integer AND that course exists, so a surprising
 * response can never steer the sync into the wrong course — it skips instead.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_course_resolver implements course_resolver {
    /** @var string Default IQA document path for the lookup. */
    public const DEFAULT_QUERY = '$/_SCCA/Events/MoodleCourseLookup';

    /** @var string Default result column holding the Moodle course id. */
    public const DEFAULT_COLUMN = 'Description';

    /** @var rest_client The authenticated iMIS REST client. */
    private rest_client $client;

    /** @var string The IQA document path. */
    private string $queryname;

    /** @var string The result column holding the course id. */
    private string $column;

    /** @var array<string,int|null> Per-run cache so repeated codes hit the IQA once. */
    private array $cache = [];

    /**
     * Build the resolver, reading the IQA query name from settings.
     *
     * @param rest_client|null $client An iMIS REST client, or null to build one from settings.
     */
    public function __construct(?rest_client $client = null) {
        $this->client = $client ?? new rest_client();

        $query = (string)get_config('local_imisbridge', 'tutorial_course_iqa');
        $this->queryname = $query !== '' ? $query : self::DEFAULT_QUERY;
        $this->column    = self::DEFAULT_COLUMN;
    }

    /**
     * Resolve one code to a Moodle course id via the lookup IQA.
     *
     * @param string $code The event/product code.
     * @return int|null The course id, or null when unmapped or the course is missing.
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

        $courseid = null;
        $rows = $this->client->run_iqa($this->queryname, [$code]);
        foreach ($rows as $row) {
            $value = (int)(string)rest_client::property($row, $this->column);
            if ($value > 0 && $DB->record_exists('course', ['id' => $value])) {
                $courseid = $value;
                break;
            }
        }

        $this->cache[$code] = $courseid;
        return $courseid;
    }
}
