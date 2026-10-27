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
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use context_course;
use local_membershipbridge\util;

/**
 * Reconciles Moodle course groups against the external tutorial-group rows.
 *
 * The source (a {@see reader}) is authoritative and the sync is one-way: it ensures
 * a "Tutorials (iMIS)" grouping per course, creates one group per group name, adds
 * people in the rows and removes people no longer in them. It only ever touches its
 * own groups and its own memberships:
 *
 * - Groups it creates carry an idnumber like `imis:ISC_26:tutorial-a-<hash>`, where
 *   the hash is derived from the case-insensitive, trimmed group name so two spellings
 *   of the same name collapse to one group while genuinely different names never do.
 *   Groups without the `imis:` prefix (teacher-made, say) are never read or changed.
 * - Members are added with this plugin as the owning component, and only members it
 *   owns are considered for removal, so a manual addition is left alone.
 *
 * Reconciliation is global, keyed by (course, idnumber): every group the plugin has
 * ever created is discovered site-wide, not just those for codes in the current read.
 * So a group whose rows have all disappeared — or whose code now maps to a different
 * course — is still visited and emptied (never deleted), rather than left stale.
 *
 * Safety is built in: an empty read suppresses all removals (never empty every group
 * at once), a per-group removal guard with an absolute floor aborts removals that
 * exceed a configured share, and dry-run reports every change without writing. The
 * engine depends only on the two injected interfaces and Moodle's group API, so it is
 * fully unit-testable with no network.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_sync {
    /** @var string The component recorded on memberships this plugin owns. */
    public const COMPONENT = 'local_membershipbridge';

    /** @var string idnumber of the per-course grouping the plugin's groups live in. */
    public const GROUPING_IDNUMBER = 'imis:tutorials';

    /** @var string The idnumber prefix that marks a group as managed by this sync. */
    public const IDNUMBER_PREFIX = 'imis:';

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

    /** @var array<int,int|null> Per-course grouping id cache. */
    private array $groupingids = [];

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
            mtrace('Membership Bridge: ' . $line);
        };
    }

    /**
     * Run one reconciliation pass.
     *
     * @return array A report: counts (coursesprocessed, groupscreated, membersadded,
     *               membersremoved, rowsskipped, guardskipped), an aborted flag, and a
     *               messages list.
     */
    public function run(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

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

        $desired = $this->build_desired($rows, $report);

        // Discover every group this plugin manages, across all courses, so a group
        // whose rows have all disappeared (or whose code now maps to a different
        // course) is still visited and emptied rather than left stale.
        $managed = [];
        $select = $DB->sql_like('idnumber', ':pat');
        foreach ($DB->get_records_select('groups', $select, ['pat' => self::IDNUMBER_PREFIX . '%']) as $group) {
            $managed[$this->key((int)$group->courseid, (string)$group->idnumber)] = $group;
        }

        $courses = [];
        foreach ($desired as $entry) {
            $courses[$entry['courseid']] = true;
        }
        $report['coursesprocessed'] = count($courses);

        foreach (array_unique(array_merge(array_keys($desired), array_keys($managed))) as $key) {
            $this->reconcile_key($desired[$key] ?? null, $managed[$key] ?? null, $report);
        }

        return $report;
    }

    /**
     * Build the desired state keyed by (course, idnumber) from the source rows.
     *
     * @param membership_row[] $rows   The source rows.
     * @param array            $report The running report, by reference.
     * @return array key => ['courseid','idnumber','name','members'=>[userid=>imisid]].
     */
    private function build_desired(array $rows, array &$report): array {
        $desired = [];
        foreach ($rows as $row) {
            if ($row->code === '' || $row->groupname === '' || $row->imisid === '') {
                $report['rowsskipped']++;
                continue;
            }
            $courseid = $this->resolver->resolve_courseid($row->code);
            if ($courseid === null) {
                $this->log($report, "code {$row->code}: no Moodle course mapped; row skipped");
                $report['rowsskipped']++;
                continue;
            }
            $idnumber = self::make_idnumber($row->code, $row->groupname);
            $key = $this->key($courseid, $idnumber);
            if (!isset($desired[$key])) {
                $desired[$key] = [
                    'courseid' => $courseid,
                    'idnumber' => $idnumber,
                    'name'     => trim($row->groupname),
                    'members'  => [],
                ];
            }

            $user = util::find_user_by_imis_id($row->imisid);
            if ($user === null) {
                $this->log($report, "course {$courseid}: no unique Moodle account for iMIS id {$row->imisid}; skipped");
                $report['rowsskipped']++;
                continue;
            }
            if (!is_enrolled(context_course::instance($courseid), $user, '', true)) {
                $this->log($report, "course {$courseid}: user {$user->id} (iMIS {$row->imisid}) not enrolled; skipped");
                $report['rowsskipped']++;
                continue;
            }
            // Keep the iMIS id so a dry run can name each member it would change.
            $desired[$key]['members'][$user->id] = $row->imisid;
        }
        return $desired;
    }

    /**
     * Reconcile one (course, idnumber) key: create/update a desired group, or empty a
     * managed group that is no longer desired.
     *
     * @param array|null     $entry  The desired entry, or null when only managed.
     * @param \stdClass|null $group  The existing managed group, or null when only desired.
     * @param array          $report The running report, by reference.
     * @return void
     */
    private function reconcile_key(?array $entry, ?\stdClass $group, array &$report): void {
        if ($entry === null) {
            // Managed but no longer desired: empty it, leaving the group in place.
            $current = $this->owned_member_ids((int)$group->id);
            if (!empty($current)) {
                $this->reconcile_members($group, (string)$group->idnumber, [], $current, $report);
            }
            return;
        }

        $courseid = (int)$entry['courseid'];
        $idnumber = (string)$entry['idnumber'];
        $want = array_keys($entry['members']);

        if ($group === null) {
            if (empty($want)) {
                return;
            }
            $report['groupscreated']++;
            if ($this->dryrun) {
                $this->log(
                    $report,
                    "course {$courseid}: would create group '{$entry['name']}' ({$idnumber}) and add "
                        . count($want) . ' member(s)'
                );
                foreach ($want as $userid) {
                    $this->log_dry_member($report, 'add', $entry['name'], $idnumber, $userid, $entry['members']);
                }
                $report['membersadded'] += count($want);
                return;
            }
            $group = $this->create_group($courseid, $idnumber, $entry['name'], $report);
            if ($group === null) {
                return;
            }
            $this->assign_grouping($this->grouping_for($courseid, $report), (int)$group->id);
            $current = [];
        } else {
            if (!$this->dryrun) {
                $this->assign_grouping($this->grouping_for($courseid, $report), (int)$group->id);
            }
            $current = $this->owned_member_ids((int)$group->id);
        }

        $this->reconcile_members($group, $idnumber, $want, $current, $report, $entry['members']);
    }

    /**
     * Add missing members and remove stale ones for a single group.
     *
     * @param \stdClass $group    The group record (needs id).
     * @param string    $idnumber The group's idnumber (for logging).
     * @param int[]     $want     Desired member user ids.
     * @param int[]     $current  Current plugin-owned member user ids.
     * @param array     $report   The running report, by reference.
     * @param array     $imisids  Desired members' iMIS ids (userid => imisid), used to
     *                            name members in dry-run output.
     * @return void
     */
    private function reconcile_members(
        \stdClass $group,
        string $idnumber,
        array $want,
        array $current,
        array &$report,
        array $imisids = []
    ): void {
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

        $groupname = (string)($group->name ?? $idnumber);
        foreach ($toadd as $userid) {
            if ($this->dryrun) {
                $this->log_dry_member($report, 'add', $groupname, $idnumber, $userid, $imisids);
            } else {
                groups_add_member($group->id, $userid, self::COMPONENT);
            }
            $report['membersadded']++;
        }
        foreach ($toremove as $userid) {
            if ($this->dryrun) {
                $this->log_dry_member($report, 'remove', $groupname, $idnumber, $userid, $imisids);
            } else {
                groups_remove_member($group->id, $userid);
            }
            $report['membersremoved']++;
        }
    }

    /**
     * Log one membership change a dry run would make, naming the member.
     *
     * A member being added comes from the source rows, so its iMIS id is in $imisids;
     * one being removed is no longer in the source, so its iMIS id is read from the
     * user's configured iMIS-ID field instead.
     *
     * @param array  $report    The running report, by reference.
     * @param string $action    'add' or 'remove'.
     * @param string $groupname The group's name.
     * @param string $idnumber  The group's idnumber.
     * @param int    $userid    The Moodle user id.
     * @param array  $imisids   Known iMIS ids (userid => imisid).
     * @return void
     */
    private function log_dry_member(
        array &$report,
        string $action,
        string $groupname,
        string $idnumber,
        int $userid,
        array $imisids
    ): void {
        global $DB;

        $imisid = (string)($imisids[$userid] ?? '');
        if ($imisid === '') {
            $user = $DB->get_record('user', ['id' => $userid], 'id, ' . util::imis_id_source());
            $imisid = $user ? util::get_imis_id($user) : '';
        }
        $this->log(
            $report,
            "group '{$groupname}' ({$idnumber}): would {$action} user {$userid}"
                . ($imisid !== '' ? " (iMIS {$imisid})" : '')
        );
    }

    /**
     * The user ids of a group's members that this plugin owns (component = local_membershipbridge).
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
     * Resolve (and cache) the per-course "Tutorials (iMIS)" grouping id.
     *
     * @param int   $courseid The course id.
     * @param array $report   The running report, by reference.
     * @return int|null The grouping id, or null when dry-run would have created it.
     */
    private function grouping_for(int $courseid, array &$report): ?int {
        if (!array_key_exists($courseid, $this->groupingids)) {
            $this->groupingids[$courseid] = $this->ensure_grouping($courseid, $report);
        }
        return $this->groupingids[$courseid];
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
            'name'     => get_string('tutorial_grouping_name', 'local_membershipbridge'),
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
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        if ($groupingid === null) {
            return;
        }
        if (!$DB->record_exists('groupings_groups', ['groupingid' => $groupingid, 'groupid' => $groupid])) {
            groups_assign_grouping($groupingid, $groupid);
        }
    }

    /**
     * The composite reconciliation key for a (course, idnumber) pair.
     *
     * @param int    $courseid The course id.
     * @param string $idnumber The group idnumber.
     * @return string
     */
    private function key(int $courseid, string $idnumber): string {
        return $courseid . "\x1f" . $idnumber;
    }

    /**
     * Build a stable, collision-resistant, length-bounded group idnumber.
     *
     * The name is reduced to a readable slug plus a hash of its canonical form
     * (lowercased and trimmed), so two spellings that differ only in case or spacing
     * collapse to one group while genuinely different names (including ones differing
     * only by punctuation, or non-ASCII names that slug to nothing) stay distinct. A
     * long code is itself hashed, keeping the whole identifier within Moodle's
     * 100-character `groups.idnumber` column.
     *
     * @param string $code      The event/product code.
     * @param string $groupname The group name.
     * @return string The idnumber (at most 100 characters).
     */
    public static function make_idnumber(string $code, string $groupname): string {
        $canonical = \core_text::strtolower(trim($groupname));
        $namehash  = substr(sha1($canonical), 0, 12);

        $code = trim($code);
        if (\core_text::strlen($code) > 40) {
            $code = \core_text::substr($code, 0, 20) . '-' . substr(sha1($code), 0, 8);
        }

        $readable = \core_text::substr(self::slugify($groupname), 0, 24);
        $suffix = ($readable !== '') ? $readable . '-' . $namehash : $namehash;

        return self::IDNUMBER_PREFIX . $code . ':' . $suffix;
    }

    /**
     * Lowercase, trim and hyphenate a value for the readable part of an idnumber.
     *
     * @param string $value The raw value.
     * @return string
     */
    private static function slugify(string $value): string {
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
