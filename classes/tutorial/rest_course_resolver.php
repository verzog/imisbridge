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
 * The IQA returns the whole MOODLE_PRODUCTS lookup — Code (the full product code,
 * e.g. `ATS_BRIDGE/REG`), Description (the numeric Moodle course id) and
 * SubstituteValue — with no prompt (confirmed 22/10/2026: ATS_BRIDGE/REG -> 7). So
 * this loads the table once and builds a code => course id map, serving every code
 * in a run from one REST call. The query name and suffix are settings so this can
 * follow the Causeis program rework without code changes.
 *
 * Resolution is deliberately conservative: a bare event code resolves via its
 * registration product code (`{code}/REG` by the configured suffix), so a neighbour
 * such as `.../REGNZ` or `ATS_Bridgetest/REG` is never matched, and a product is kept
 * only when its Description is a positive integer naming a course that exists. An
 * unmapped code returns null and the sync skips it; a failed lookup read throws and
 * the whole sync aborts, so a transient outage never makes codes look unmapped.
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

    /** @var string The registration suffix on the product code to match in the lookup. */
    private string $suffix;

    /** @var array<string,int>|null The product-code (lowercased) => course id map, loaded once. */
    private ?array $map = null;

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
     * Resolve a bare event code to a Moodle course id from the MOODLE_PRODUCTS map.
     *
     * Looks up the registration product code (`{code}/REG`) first, then falls back to
     * the bare code, so neighbouring products (`.../REGNZ`, `ATS_Bridgetest/REG`) are
     * never matched.
     *
     * @param string $code The event/product code.
     * @return int|null The course id, or null when unmapped.
     */
    public function resolve_courseid(string $code): ?int {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $map = $this->load_map();

        if ($this->suffix !== '') {
            $key = \core_text::strtolower($code . $this->suffix);
            if (isset($map[$key])) {
                return $map[$key];
            }
        }
        return $map[\core_text::strtolower($code)] ?? null;
    }

    /**
     * Load the whole MOODLE_PRODUCTS lookup once and build a code => course id map.
     *
     * The IQA returns the full table (Code, Description = course id), so one call
     * serves every code in a run. Only rows whose Description is a positive integer
     * naming an existing course are kept; a failed/short read throws (in run_iqa),
     * which aborts the sync rather than leaving codes falsely unmapped.
     *
     * @return array<string,int> Lowercased product code => course id.
     */
    private function load_map(): array {
        global $DB;

        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        foreach ($this->client->run_iqa($this->queryname, []) as $row) {
            $rowcode = trim((string)rest_client::property($row, 'Code'));
            if ($rowcode === '') {
                continue;
            }
            $value = (int)(string)rest_client::property($row, $this->column);
            if ($value > 0 && $DB->record_exists('course', ['id' => $value])) {
                $map[\core_text::strtolower($rowcode)] = $value;
            }
        }

        $this->map = $map;
        return $map;
    }
}
