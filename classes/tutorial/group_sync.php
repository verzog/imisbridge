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
 * Transport-agnostic engine that makes Moodle course groups match the source rows.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

use local_imisbridge\util;

/**
 * Reconciles Moodle course groups against the external tutorial-group rows.
 *
 * The source (a {@see reader}) is authoritative and the sync is one-way: for each
 * course it ensures a "Tutorials (iMIS)" grouping, creates one group per group name,
 * adds people who are in the rows but not the group, and removes people who are in
 * the group but no longer in the rows. It only ever touches its own groups and its
 * own memberships:
 *
 * - Groups it creates carry an idnumber like `imis:ISC_26:tutorial-a`; groups
 *   without that prefix (teacher-made, say) are never read or changed.
 * - Members are added with this plugin as the owning component, and only members it
 *   owns are considered for removal, so a manual addition is left alone.
 *
 * Safety is built in: an empty read suppresses all removals (never empty every
 * group at once), a per-group removal guard aborts removals that exceed a configured
 * share, and dry-run reports every change without writing. The engine depends only
 * on the two injected interfaces and Moodle's group API, so it is fully unit-testable
 * with no network.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_sync {
    /** @var string The component recorded on memberships this plugin owns. */
    public const COMPONENT = 'local_imisbridge';

    /** @var string idnumber of the per-course grouping the plugin's groups live in. */
    public const GROUPING_IDNUMBER = 'imis:tutorials';

    /** @var reader The authoritative source of membership rows. */
    private reader $reader;

    /** @var course_resolver Maps an event/product code to a Moodle course id. */
    private course_resolver $resolver;

    /** @var bool When true, report every change but write nothing. */
    private bool $dryrun;

    /** @var int Skip a group's removals when they would exceed this share (percent) of its members. */
    private int $removalguardpct;

    /** @var int Never guard removals below this absolute count, so small legitimate moves are not blocked. */
    private int $removalguardfloor;

    /** @var callable Sink for human-readable progress lines (defaults to mtrace). */
    private $trace;

    /**
     * Build the engine with its source, course mapping and run options.
     *
     * @param reader          $reader   The membership-row source.
     * @param course_resolver $resolver The code→course mapping.
     * @param array           $options  dryrun (bool), removalguardpct (int 0-100),
     *                                  removalguardfloor (int), trace (callable).
     */
    public function __construct(reader $reader, course_resolver $resolver, array $options = []) {
        $this->reader            = $reader;
        $this->resolver          = $resolver;
        $this->dryrun            = !empty($options['dryrun']);
        $guard                   = (int)($options['removalguardpct'] ?? 50);
        $this->removalguardpct   = max(0, min(100, $guard));
        $this->removalguardfloor = max(1, (int)($options['removalguardfloor'] ?? 10));
        $this->trace             = $options['trace'] ?? function (string $line): void {
            mtrace('iMIS Bridge: ' . $line);
        };
    }

    /**
     * Run one reconciliation pass over every code present in the source rows.
     *
     * @return array A report: counts (groupscreated, membersadded, membersremoved,
     *               rowsskipped, guardskipped, coursesprocessed), an aborted flag, and
     *               a messages list.
     */
    public function run(): array {
        $report = [
            'coursesprocessed' => 0,
            'groupscreated'    => 0,
            'membersadded'     => 0,
            'membersremoved'   => 0,
            'rowsskipped'      => 0,
            'guardskipped'     => 0,
            'aborted'          => false,
            'messages'         => [],
        ];

        $rows = $this->reader->fetch_rows();

        // A completely empty read is treated as "could not establish the desired
        // state", not "remove everyone": make no changes at all. A genuine empty
        // source is indistinguishable from a silent read failure, and the cost of
        // guessing wrong is wiping every group, so we never do.
        if (empty($rows)) {
            $this->log($report, 'no tutorial-group rows returned; skipping (no changes made)');
            $report['aborted'] = true;
            return $report;
        }

        // Group the rows by code; each code maps to one Moodle course.
        $bycode = [];
        foreach ($rows as $row) {
            if ($row->code === '' || $row->groupname === '' || $row->imisid === '') {
                $report['rowsskipped']++;
                continue;
            }
            $bycode[$row->code][] = $row;
        }

        foreach ($bycode as $code => $coderows) {
            $courseid = $this->resolver->resolve_courseid((string)$code);
            if ($courseid === null) {
                $this->log(
                    $report,
                    "code {$code}: no Moodle course mapped; skipped " . count($coderows) . ' row(s)'
                );
                $report['rowsskipped'] += count($coderows);
                continue;
            }
            $this->sync_code((string)$code, $courseid, $coderows, $report);
            $report['coursesprocessed']++;
        }

        return $report;
    }

    /**
     * Reconcile every tutorial group for one code within its course.
     *
     * @param string $code     The event/product code.
     * @param int    $courseid The resolved Moodle course id.
     * @param array  $coderows The membership rows for this code.
     * @param array  $report   The running report, by reference.
     * @return void
     */
    private function sync_code(string $code, int $courseid, array $coderows, array &$report): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $context = \context_course::instance($courseid);

        // Build the desired state for this code: idnumber => display name, and
        // idnumber => set of Moodle user ids (only accounts that exist and are enrolled).
        $desirednames   = [];
        $desiredmembers = [];
        foreach ($coderows as $row) {
            $idnumber = $this->group_idnumber($code, $row->groupname);
            $desirednames[$idnumber] = $row->groupname;

            $user = util::find_user_by_imis_id($row->imisid);
            if ($user === null) {
                $this->log($report, "course {$courseid}: no unique Moodle account for iMIS id {$row->imisid}; skipped");
                $report['rowsskipped']++;
                continue;
            }
            if (!is_enrolled($context, $user, '', true)) {
                $this->log(
                    $report,
                    "course {$courseid}: user {$user->id} (iMIS {$row->imisid}) not actively enrolled; skipped"
                );
                $report['rowsskipped']++;
                continue;
            }
            $desiredmembers[$idnumber][$user->id] = true;
        }

        $groupingid = $this->ensure_grouping($courseid, $report);

        // Existing plugin-owned groups for THIS code only (idnumber prefix imis:{code}:),
        // so two codes that happen to map to the same course never disturb each other.
        $prefix = $this->code_prefix($code);
        $existing = $DB->get_records_select(
            'groups',
            'courseid = :courseid AND ' . $DB->sql_like('idnumber', ':prefix'),
            ['courseid' => $courseid, 'prefix' => $DB->sql_like_escape($prefix) . '%']
        );
        $existingbyidnumber = [];
        foreach ($existing as $group) {
            $existingbyidnumber[$group->idnumber] = $group;
        }

        // Process the union of desired and existing idnumbers: a group that has
        // vanished from the rows (everyone moved or removed) is visited so it can be
        // emptied, but it is left in place, never deleted.
        $allidnumbers = array_unique(array_merge(array_keys($desirednames), array_keys($existingbyidnumber)));
        foreach ($allidnumbers as $idnumber) {
            $want  = array_keys($desiredmembers[$idnumber] ?? []);
            $group = $existingbyidnumber[$idnumber] ?? null;

            if ($group === null) {
                // Nothing to create for an absent group with no desired members.
                if (empty($want)) {
                    continue;
                }
                $report['groupscreated']++;
                if ($this->dryrun) {
                    $this->log(
                        $report,
                        "course {$courseid}: would create group '{$desirednames[$idnumber]}' ({$idnumber})"
                            . ' and add ' . count($want) . ' member(s)'
                    );
                    $report['membersadded'] += count($want);
                    continue;
                }
                $group = $this->create_group($courseid, $idnumber, $desirednames[$idnumber], $report);
                if ($group === null) {
                    continue;
                }
                $this->assign_grouping($groupingid, $group->id);
                $current = [];
            } else {
                if (!$this->dryrun) {
                    $this->assign_grouping($groupingid, $group->id);
                }
                $current = $this->owned_member_ids((int)$group->id);
            }

            $this->reconcile_members($group, $idnumber, $want, $current, $report);
        }
    }

    /**
     * Add missing members and remove stale ones for a single group.
     *
     * @param \stdClass $group    The group record (needs id and name).
     * @param string    $idnumber The group's idnumber (for logging).
     * @param int[]     $want     Desired member user ids.
     * @param int[]     $current  Current plugin-owned member user ids.
     * @param array     $report   The running report, by reference.
     * @return void
     */
    private function reconcile_members(\stdClass $group, string $idnumber, array $want, array $current, array &$report): void {
        $toadd    = array_diff($want, $current);
        $toremove = array_diff($current, $want);

        // Per-group removal guard: if a single run would remove more than the
        // configured share of a group's current members, do the adds but skip the
        // removals and log it, so a bad or partial source read cannot gut a group.
        // The floor exempts small removals (a routine move or an emptied small group),
        // which are always a high percentage but never a mass wipe.
        if (count($toremove) >= $this->removalguardfloor && count($current) > 0) {
            $share = (count($toremove) / count($current)) * 100;
            if ($share > $this->removalguardpct) {
                $this->log(
                    $report,
                    "group {$idnumber}: removal guard tripped (would remove " . count($toremove) . ' of '
                        . count($current) . '); skipping removals'
                );
                $report['guardskipped']++;
                $toremove = [];
            }
        }

        foreach ($toadd as $userid) {
            if (!$this->dryrun) {
                groups_add_member($group->id, $userid, self::COMPONENT);
            }
            $report['membersadded']++;
        }
        foreach ($toremove as $userid) {
            if (!$this->dryrun) {
                groups_remove_member($group->id, $userid);
            }
            $report['membersremoved']++;
        }
    }

    /**
     * The user ids of a group's members that this plugin owns (component = local_imisbridge).
     *
     * @param int $groupid The group id.
     * @return int[] The owned member user ids.
     */
    private function owned_member_ids(int $groupid): array {
        global $DB;
        $records = $DB->get_records(
            'groups_members',
            ['groupid' => $groupid, 'component' => self::COMPONENT],
            '',
            'userid'
        );
        return array_map('intval', array_keys($records));
    }

    /**
     * Find or create the per-course "Tutorials (iMIS)" grouping.
     *
     * @param int   $courseid The course id.
     * @param array $report   The running report, by reference.
     * @return int|null The grouping id, or null when dry-run would have created it.
     */
    private function ensure_grouping(int $courseid, array &$report): ?int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $existing = $DB->get_record('groupings', ['courseid' => $courseid, 'idnumber' => self::GROUPING_IDNUMBER]);
        if ($existing) {
            return (int)$existing->id;
        }
        if ($this->dryrun) {
            $this->log($report, "course {$courseid}: would create grouping 'Tutorials (iMIS)'");
            return null;
        }

        $grouping = (object)[
            'courseid' => $courseid,
            'name'     => get_string('tutorial_grouping_name', 'local_imisbridge'),
            'idnumber' => self::GROUPING_IDNUMBER,
        ];
        return (int)groups_create_grouping($grouping);
    }

    /**
     * Create one plugin-owned group, returning null (and logging) on a name clash.
     *
     * @param int    $courseid The course id.
     * @param string $idnumber The group idnumber.
     * @param string $name     The group display name.
     * @param array  $report   The running report, by reference.
     * @return \stdClass|null The created group record, or null on failure.
     */
    private function create_group(int $courseid, string $idnumber, string $name, array &$report): ?\stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Moodle requires a unique group name within a course. A clash with a group
        // the plugin does not own is reported and skipped rather than fatal.
        if ($DB->record_exists('groups', ['courseid' => $courseid, 'name' => $name])) {
            $this->log(
                $report,
                "course {$courseid}: a group named '{$name}' already exists with a different idnumber; skipped"
            );
            return null;
        }

        $group = (object)[
            'courseid' => $courseid,
            'name'     => \core_text::substr($name, 0, 254),
            'idnumber' => $idnumber,
        ];
        $group->id = groups_create_group($group);
        return $group;
    }

    /**
     * Assign a group to the grouping, unless dry-run suppressed grouping creation.
     *
     * @param int|null $groupingid The grouping id, or null in dry-run.
     * @param int      $groupid    The group id.
     * @return void
     */
    private function assign_grouping(?int $groupingid, int $groupid): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        if ($groupingid === null) {
            return;
        }
        if (!$this->group_in_grouping($groupingid, $groupid)) {
            groups_assign_grouping($groupingid, $groupid);
        }
    }

    /**
     * Whether a group is already assigned to a grouping.
     *
     * @param int $groupingid The grouping id.
     * @param int $groupid    The group id.
     * @return bool
     */
    private function group_in_grouping(int $groupingid, int $groupid): bool {
        global $DB;
        return $DB->record_exists('groupings_groups', ['groupingid' => $groupingid, 'groupid' => $groupid]);
    }

    /**
     * The idnumber prefix shared by every plugin group for a code, e.g. `imis:ISC_26:`.
     *
     * The code is kept verbatim (event keys such as ISC_26 are already safe idnumber
     * text) so the prefix stays human-readable and matches the documented scheme;
     * only the group name is slugified.
     *
     * @param string $code The event/product code.
     * @return string
     */
    private function code_prefix(string $code): string {
        return 'imis:' . trim($code) . ':';
    }

    /**
     * The full idnumber for a group, e.g. `imis:ISC_26:tutorial-a`.
     *
     * The group name is slugified so case and spacing differences ("Tutorial A" vs
     * "tutorial a") collapse to the same group, matching the builder's case-
     * insensitive naming.
     *
     * @param string $code      The event/product code.
     * @param string $groupname The group name.
     * @return string
     */
    private function group_idnumber(string $code, string $groupname): string {
        return $this->code_prefix($code) . $this->slug($groupname);
    }

    /**
     * Lowercase, trim and hyphenate a value for use in an idnumber.
     *
     * @param string $value The raw value.
     * @return string
     */
    private function slug(string $value): string {
        $value = \core_text::strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim((string)$value, '-');
    }

    /**
     * Record a progress line both in the report and through the trace sink.
     *
     * @param array  $report The running report, by reference.
     * @param string $line   The message.
     * @return void
     */
    private function log(array &$report, string $line): void {
        $report['messages'][] = $line;
        ($this->trace)($line);
    }
}
