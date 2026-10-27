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
 * External web service: bulk course completions and grades for iMIS.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * Returns course completions and final course grades changed since a given time.
 *
 * Designed for iMIS/ATS to poll on a schedule: it returns every course
 * completion (with the matching course-total grade) whose completion time or
 * grade changed at or after {@see $since}, in stable id order with a paging
 * cursor, so a consumer can drain all changes in bounded pages.
 *
 * Polling contract (important): the `afterid` cursor paginates WITHIN a single
 * drain of one fixed `since` value — it is not a durable checkpoint. A consumer
 * should: (1) record the current time before starting; (2) call repeatedly with
 * that same `since`, passing the returned `nextafterid` back as `afterid`, until
 * `hasmore` is false; (3) only then store the time recorded in step 1 as the new
 * `since` for the next poll — never the newest `timemodified` returned. Each call
 * re-scans from the start of the id order filtered by `since`, so a completion or
 * grade that changes during a drain (even on an already-paged, lower id) is
 * returned on the next poll rather than being skipped.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_completions_since extends external_api {
    /** @var int Default page size when none/invalid is supplied. */
    private const DEFAULT_LIMIT = 500;

    /** @var int Largest page size a caller may request. */
    private const MAX_LIMIT = 5000;

    /**
     * Describe the function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(
                PARAM_INT,
                'Return records whose completion or course grade changed at or after this Unix timestamp.'
            ),
            'limit' => new external_value(
                PARAM_INT,
                'Maximum number of records to return (1-' . self::MAX_LIMIT . ').',
                VALUE_DEFAULT,
                self::DEFAULT_LIMIT
            ),
            'afterid' => new external_value(
                PARAM_INT,
                'Paging cursor: return only records with a completion id greater than this. Use 0 to start.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Return completions and grades changed since the given time.
     *
     * @param int $since   Unix timestamp; records changed at or after this are returned.
     * @param int $limit   Page size (clamped to 1-5000).
     * @param int $afterid Paging cursor (completion id to read after).
     * @return array The records plus paging metadata and warnings.
     */
    public static function execute(int $since, int $limit = self::DEFAULT_LIMIT, int $afterid = 0): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'since'   => $since,
            'limit'   => $limit,
            'afterid' => $afterid,
        ]);
        $since   = max(0, (int)$params['since']);
        $limit   = (int)$params['limit'];
        $afterid = max(0, (int)$params['afterid']);

        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        // Security: system-level, token user must hold the read capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/membershipbridge:readcompletions', $context);

        // Completions are the spine; the course-total grade (itemtype = 'course')
        // is joined in. A record qualifies when the completion OR the course
        // grade changed at or after $since. Ordered and paged by completion id
        // for stable, gap-free paging. Deleted and suspended users are excluded.
        $sql = "SELECT cc.id AS ccid,
                       u.id AS userid, u.username, u.idnumber AS useridnumber, u.email,
                       c.id AS courseid, c.shortname, c.idnumber AS courseidnumber,
                       cc.timecompleted,
                       gg.finalgrade, gg.timemodified AS gradetimemodified,
                       gi.id AS gradeitemid, gi.grademax, gi.grademin
                  FROM {course_completions} cc
                  JOIN {user} u ON u.id = cc.userid
                  JOIN {course} c ON c.id = cc.course
             LEFT JOIN {grade_items} gi ON gi.courseid = cc.course AND gi.itemtype = :itemtype
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cc.userid
                 WHERE cc.timecompleted IS NOT NULL
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND cc.id > :afterid
                   AND (cc.timecompleted >= :since1
                        OR (gg.timemodified IS NOT NULL AND gg.timemodified >= :since2))
              ORDER BY cc.id ASC";

        $sqlparams = [
            'itemtype' => 'course',
            'afterid'  => $afterid,
            'since1'   => $since,
            'since2'   => $since,
        ];

        // Fetch one extra row to detect whether more pages follow.
        $rows = $DB->get_records_sql($sql, $sqlparams, 0, $limit + 1);

        $hasmore = (count($rows) > $limit);
        if ($hasmore) {
            $rows = array_slice($rows, 0, $limit, true);
        }

        $records     = [];
        $nextafterid = $afterid;
        $gradeitems  = [];

        foreach ($rows as $row) {
            $nextafterid = (int)$row->ccid;

            $graderaw   = null;
            $grademax   = null;
            $percentage = null;
            $letter     = null;

            if ($row->gradeitemid !== null && $row->finalgrade !== null) {
                $graderaw = (float)$row->finalgrade;
                $grademax = (float)$row->grademax;
                $grademin = (float)$row->grademin;

                if (($grademax - $grademin) > 0) {
                    $percentage = round((($graderaw - $grademin) / ($grademax - $grademin)) * 100, 2);
                }

                // Reuse one grade_item per course for letter formatting (grade
                // letters can be overridden per course context).
                if (!array_key_exists((int)$row->courseid, $gradeitems)) {
                    $gradeitems[(int)$row->courseid] = \grade_item::fetch(['id' => (int)$row->gradeitemid]);
                }
                $gradeitem = $gradeitems[(int)$row->courseid];
                if ($gradeitem) {
                    $formatted = grade_format_gradevalue($graderaw, $gradeitem, true, GRADE_DISPLAY_TYPE_LETTER);
                    $letter = ($formatted === '' || $formatted === '-') ? null : $formatted;
                }
            }

            $timemodified = (int)$row->timecompleted;
            if ($row->gradetimemodified !== null && (int)$row->gradetimemodified > $timemodified) {
                $timemodified = (int)$row->gradetimemodified;
            }

            // Resolve the iMIS contact ID from the configured source field so an
            // email-based (or ID-number-based) deployment gets a usable match,
            // consistent with the observers and tasks.
            $imisid = \local_membershipbridge\util::get_imis_id((object)[
                'username' => $row->username,
                'idnumber' => $row->useridnumber,
                'email'    => $row->email,
            ]);

            $records[] = [
                'userid'          => (int)$row->userid,
                'imisid'          => $imisid,
                'username'        => (string)$row->username,
                'useridnumber'    => (string)$row->useridnumber,
                'courseid'        => (int)$row->courseid,
                'courseshortname' => (string)$row->shortname,
                'courseidnumber'  => (string)$row->courseidnumber,
                'timecompleted'   => $row->timecompleted !== null ? (int)$row->timecompleted : null,
                'graderaw'        => $graderaw,
                'grademax'        => $grademax,
                'gradepercentage' => $percentage,
                'gradeletter'     => $letter,
                'timemodified'    => $timemodified,
            ];
        }

        return [
            'records'     => $records,
            'nextafterid' => $nextafterid,
            'hasmore'     => $hasmore,
            'warnings'    => [],
        ];
    }

    /**
     * Describe the function return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'records' => new external_multiple_structure(
                new external_single_structure([
                    'userid'          => new external_value(PARAM_INT, 'Moodle user id.'),
                    'imisid'          => new external_value(
                        PARAM_RAW,
                        'The iMIS contact ID resolved from the configured source field '
                            . '(username, ID number or email).'
                    ),
                    'username'        => new external_value(PARAM_RAW, 'Moodle username (the iMIS contact ID at SCCA).'),
                    'useridnumber'    => new external_value(PARAM_RAW, 'Moodle user ID number.'),
                    'courseid'        => new external_value(PARAM_INT, 'Moodle course id.'),
                    'courseshortname' => new external_value(PARAM_RAW, 'Course short name.'),
                    'courseidnumber'  => new external_value(PARAM_RAW, 'Course ID number.'),
                    'timecompleted'   => new external_value(
                        PARAM_INT,
                        'Course completion time (Unix), or null.',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'graderaw' => new external_value(
                        PARAM_FLOAT,
                        'Final course grade raw value, or null if ungraded.',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'grademax' => new external_value(
                        PARAM_FLOAT,
                        'Maximum possible course grade, or null if ungraded.',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'gradepercentage' => new external_value(
                        PARAM_FLOAT,
                        'Final course grade as a percentage, or null if ungraded.',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'gradeletter' => new external_value(
                        PARAM_RAW,
                        'Final course grade as a letter, or null if ungraded.',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                    'timemodified' => new external_value(
                        PARAM_INT,
                        'The later of the completion time and the course grade modified time (Unix).'
                    ),
                ])
            ),
            'nextafterid' => new external_value(
                PARAM_INT,
                'Pass this as afterid on the next call to continue paging.'
            ),
            'hasmore' => new external_value(
                PARAM_BOOL,
                'True if more records are available beyond this page.'
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
