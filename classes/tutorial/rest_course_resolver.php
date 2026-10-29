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
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use local_membershipbridge\rest_client;

/**
 * Direct-REST {@see course_resolver}: reads the MOODLE_PRODUCTS lookup IQA.
 *
 * The IQA has a **required `Code` prompt**, supplied over REST as `&Parameter=<value>`
 * (confirmed on sccadev 22/10/2026: the prompt matches the full product code, so
 * `Parameter=ATS_BRIDGE/REG` returns `Description = 7` while the bare code returns no
 * rows). So this resolves one code at a time — sending the registration product code
 * `{code}/REG` (the panel stores only the bare event code) — and reads the Description
 * column from the returned row. Results are cached per code for the run. The query
 * name and suffix are settings (*Tutorial course IQA* and *Course code suffix*), so
 * this can follow the Causeis program rework without code changes. There is no
 * built-in query: with none set, building the resolver fails, so a sync stops
 * rather than treating every code as unmapped.
 *
 * Resolution is deliberately conservative: a product is kept only when its Description
 * is a positive integer naming a course that exists; an unmapped code returns null and
 * the sync skips it, while a failed lookup read throws (in run_iqa) and the whole sync
 * aborts, so a transient outage never makes codes look unmapped.
 *
 * The *Course mapping overrides* setting (`CODE=courseid` lines) is checked first: a
 * listed code resolves to its course without reading iMIS, so a staging site can point
 * an event at a test course without editing MOODLE_PRODUCTS.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_course_resolver implements course_resolver {
    /** @var string Default result column holding the Moodle course id. */
    public const DEFAULT_COLUMN = 'Description';

    /** @var string Default registration suffix on the product code in the result (none). */
    public const DEFAULT_SUFFIX = '';

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

    /** @var array<string,int> Course mapping overrides: lower-case code => course id. */
    private array $overrides;

    /**
     * Build the resolver, reading the IQA query name and code suffix from settings.
     *
     * @param rest_client|null $client An iMIS REST client, or null to build one from settings.
     * @throws \moodle_exception When the *Tutorial course IQA* setting is blank.
     */
    public function __construct(?rest_client $client = null) {
        $query = trim((string)get_config('local_membershipbridge', 'tutorial_course_iqa'));
        if ($query === '') {
            throw new \moodle_exception('tutorial_course_iqa_missing', 'local_membershipbridge');
        }
        $this->client    = $client ?? new rest_client();
        $this->queryname = $query;
        $this->column    = self::DEFAULT_COLUMN;

        $suffix = get_config('local_membershipbridge', 'tutorial_course_code_suffix');
        $this->suffix = ($suffix === false) ? self::DEFAULT_SUFFIX : (string)$suffix;

        $this->overrides = self::parse_overrides((string)get_config('local_membershipbridge', 'course_map_overrides'));
    }

    /**
     * Parse the course mapping overrides: one `CODE=courseid` per line (the code bare,
     * e.g. ACCD_26, or with its product suffix, e.g. ACCD_26/REG). Blank lines, lines
     * starting with # and lines without a positive course id are ignored.
     *
     * @param string $text The setting value.
     * @return array<string,int> Lower-case code => course id.
     */
    public static function parse_overrides(string $text): array {
        $overrides = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$code, $id] = array_map('trim', explode('=', $line, 2));
            if ($code !== '' && ctype_digit($id) && (int)$id > 0) {
                $overrides[\core_text::strtolower($code)] = (int)$id;
            }
        }
        return $overrides;
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

        // A local override wins and iMIS is not read; it must still name a real course.
        foreach ([$code, $lookupcode] as $candidate) {
            $override = $this->overrides[\core_text::strtolower($candidate)] ?? null;
            if ($override !== null) {
                $this->cache[$code] = $DB->record_exists('course', ['id' => $override]) ? $override : null;
                return $this->cache[$code];
            }
        }

        $courseid = null;
        foreach ($this->client->run_iqa($this->queryname, [$lookupcode]) as $row) {
            // Guard against a loosely-filtered or misconfigured IQA returning a
            // neighbouring product (e.g. .../REGNZ): when the row carries a Code,
            // require it to match the looked-up product code exactly.
            $rowcode = (string)rest_client::property($row, 'Code');
            if ($rowcode !== '' && strcasecmp($rowcode, $lookupcode) !== 0) {
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
