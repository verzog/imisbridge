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
 * Direct-REST {@see course_resolver}: reads the MOODLE_PRODUCTS lookup IQA.
 *
 * The IQA has a **required `Code` prompt**, supplied over REST as `&Parameter=<value>`
 * (confirmed on sccadev 22/10/2026: the prompt matches the full product code, so
 * `Parameter=ATS_BRIDGE/REG` returns `Description = 7` while the bare code returns no
 * rows). So this resolves one code at a time — sending the registration product code
 * `{code}/REG` (the panel stores only the bare event code) — and reads the Description
 * column from the returned row. Results are cached per code for the run. The query
 * name and suffix are settings so this can follow the Causeis program rework without
 * code changes.
 *
 * Resolution is deliberately conservative: a product is kept only when its Description
 * is a positive integer naming a course that exists; an unmapped code returns null and
 * the sync skips it, while a failed lookup read throws (in run_iqa) and the whole sync
 * aborts, so a transient outage never makes codes look unmapped.
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

    /** @var string Default registration suffix on the product code in the result. */
    public const DEFAULT_SUFFIX = '/REG';

    /** @var rest_client The authenticated iMIS REST client. */
    private rest_client $client;

    /** @var string The IQA document path. */
    private string $queryname;

    /** @var string The result column holding the course id. */
    private string $column;

    /** @var string The registration suffix appended to the bare code for the lookup. */
    private string $suffix;

    /** @var array<string,int|null> Per-run cache of bare code => resolved course id (or null). */
    private array $cache = [];

    /**
     * Build the resolver, reading the IQA query name and code suffix from settings.
     *
     * @param rest_client|null $client An iMIS REST client, or null to build one from settings.
     */
    public function __construct(?rest_client $client = null) {
        $this->client = $client ?? new rest_client();

        $query = (string)get_config('local_imisbridge', 'tutorial_course_iqa');
        $this->queryname = $query !== '' ? $query : self::DEFAULT_QUERY;
        $this->column    = self::DEFAULT_COLUMN;

        $suffix = get_config('local_imisbridge', 'tutorial_course_code_suffix');
        $this->suffix = ($suffix === false) ? self::DEFAULT_SUFFIX : (string)$suffix;
    }

    /**
     * Resolve a bare event code to a Moodle course id via the lookup IQA.
     *
     * Sends the registration product code `{code}{suffix}` (e.g. `ATS_BRIDGE/REG`) as
     * the IQA's required `Code` prompt and reads the Description from the returned row.
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

        // The IQA's required Code prompt matches the full product code, so look up
        // {code}/REG (the panel stores only the bare event code).
        $lookupcode = $code . $this->suffix;

        $courseid = null;
        foreach ($this->client->run_iqa($this->queryname, [$lookupcode]) as $row) {
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
