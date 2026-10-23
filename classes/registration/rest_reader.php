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
 * Reads registrations from an iMIS IQA over the REST API.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\registration;

use local_imisbridge\rest_client;

/**
 * {@see reader} over a registrations IQA, run with no prompts.
 *
 * Columns are found by name ignoring case, spaces and underscores, so an IQA can use
 * either aliases or display captions ("EventCode" or "Event Code"):
 *
 * - `ID`: the registrant's iMIS ID (required);
 * - `EventCode`: the event code (required);
 * - `Status`: the registration status (blank when active);
 * - `RegistrationKey`: a stable label for the registration;
 * - `IsActiveRegistration` (or `IsActive`): the source's active flag;
 * - `EndDate` (or `EndDateTime`): the event's end date;
 * - `FirstName`, `LastName`, `Email`: used when an account is created.
 *
 * A failed read throws (from {@see rest_client::run_iqa()}), so an outage can never
 * look like an empty registration list.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_reader implements reader {
    /** @var rest_client The authenticated iMIS REST client. */
    private rest_client $client;

    /** @var string The IQA document path. */
    private string $queryname;

    /**
     * Build the reader.
     *
     * @param rest_client $client    An iMIS REST client.
     * @param string      $queryname The registrations IQA document path.
     */
    public function __construct(rest_client $client, string $queryname) {
        $this->client    = $client;
        $this->queryname = $queryname;
    }

    /**
     * Read every row of the registrations IQA.
     *
     * @return registration_row[]
     */
    public function fetch_rows(): array {
        $rows = [];
        foreach ($this->client->run_iqa($this->queryname) as $entity) {
            $rows[] = self::to_row(rest_client::properties($entity));
        }
        return $rows;
    }

    /**
     * Map one IQA row's properties to a registration row.
     *
     * @param array<string,mixed> $properties Property name => value.
     * @return registration_row
     */
    public static function to_row(array $properties): registration_row {
        $byname = [];
        foreach ($properties as $name => $value) {
            $byname[self::normalise((string)$name)] = $value;
        }

        return new registration_row(
            (string)self::first($byname, ['id']),
            (string)self::first($byname, ['eventcode']),
            (string)self::first($byname, ['status']),
            (string)self::first($byname, ['registrationkey']),
            self::to_bool(self::first($byname, ['isactiveregistration', 'isactive'])),
            self::to_time(self::first($byname, ['enddate', 'enddatetime'])),
            (string)self::first($byname, ['firstname']),
            (string)self::first($byname, ['lastname']),
            (string)self::first($byname, ['email'])
        );
    }

    /**
     * The value of the first of the given (normalised) column names that is present.
     *
     * @param array<string,mixed> $byname Normalised column name => value.
     * @param string[]            $names  Candidate column names, in order of preference.
     * @return mixed The value, or null when none is present.
     */
    private static function first(array $byname, array $names) {
        foreach ($names as $name) {
            if (array_key_exists($name, $byname) && $byname[$name] !== null) {
                return $byname[$name];
            }
        }
        return null;
    }

    /**
     * A column name lower-cased with spaces and underscores removed.
     *
     * @param string $name The column name.
     * @return string
     */
    private static function normalise(string $name): string {
        return str_replace([' ', '_'], '', \core_text::strtolower(trim($name)));
    }

    /**
     * Read a boolean flag ("True"/"False", 1/0, true/false); null when blank.
     *
     * @param mixed $value The raw value.
     * @return bool|null
     */
    private static function to_bool($value): ?bool {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $text = \core_text::strtolower(trim((string)$value));
        if (in_array($text, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($text, ['false', '0', 'no'], true)) {
            return false;
        }
        return null;
    }

    /**
     * Read a date (e.g. "2026-10-30T00:00:00") as Unix time; null when blank or unparseable.
     *
     * @param mixed $value The raw value.
     * @return int|null
     */
    private static function to_time($value): ?int {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $time = strtotime((string)$value);
        return ($time === false) ? null : $time;
    }
}
