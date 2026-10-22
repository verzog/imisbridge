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
 * The iMIS MOODLE_PRODUCTS general lookup is keyed by the full product code — e.g.
 * `ATS_BRIDGE/REG` — with the numeric Moodle course id in its Description column
 * (confirmed 22/10/2026: ATS_BRIDGE/REG -> 7). The IQA takes the **bare** event
 * code (`ATS_BRIDGE`) as its required prompt and returns that product row; the query
 * name is a setting so this can follow the Causeis program rework without code
 * changes.
 *
 * Resolution is deliberately conservative: among the returned rows it accepts only
 * one whose Code is the bare code or the registration product code (`{code}/REG` by
 * the configured suffix), so a loose IQA filter cannot steer it onto a neighbouring
 * product such as `.../REGNZ` or `ATS_Bridgetest/REG`, and only when the Description
 * is a positive integer naming a course that exists. Anything else returns null and
 * the sync skips that code rather than guessing.
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

    /** @var string The registration suffix on the product code to recognise in the result. */
    private string $suffix;

    /** @var array<string,int|null> Per-run cache so repeated codes hit the IQA once. */
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

        // The IQA takes the bare event code as its prompt; the matching row's Code is
        // the full product code (e.g. ATS_BRIDGE/REG). Accept a row only when its Code
        // is the bare code or the suffixed product code, so a loose filter cannot
        // return a neighbour such as .../REGNZ or ATS_Bridgetest/REG.
        $accept = [\core_text::strtolower($code)];
        if ($this->suffix !== '') {
            $accept[] = \core_text::strtolower($code . $this->suffix);
        }

        $courseid = null;
        foreach ($this->client->run_iqa($this->queryname, [$code]) as $row) {
            $rowcode = (string)rest_client::property($row, 'Code');
            if ($rowcode !== '' && !in_array(\core_text::strtolower($rowcode), $accept, true)) {
                continue;
            }
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
