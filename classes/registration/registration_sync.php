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
 * Enrolment and cancellation sync from registration rows.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\registration;

use local_imisbridge\enrolment;
use local_imisbridge\tutorial\course_resolver;
use local_imisbridge\util;

/**
 * Enrols active registrants and suspends cancelled ones, deciding per member and course.
 *
 * The registrations source is read in full and grouped by (member, course):
 *
 * - **enrol** mode: a member with **any** active registration for a course gets an
 *   active enrolment (enrolled, or a suspended enrolment reactivated). A member with
 *   no Moodle account gets one created first, when account creation is on.
 * - **cancel** mode: a member whose registrations for a course are **all** cancelled
 *   has their enrolment suspended (grades and completion kept; nothing is deleted).
 *
 * A blank status is active and a status containing a cancelled code is cancelled.
 * Any other status is unrecognised: the row grants nothing, and a member and course
 * with one is never suspended either, so a new iMIS state (pending, waitlisted) can
 * neither give access nor take it away until it is mapped.
 *
 * A member and course with no rows at all is left alone, so a registration that
 * ages out of the source, or a partial read, never removes anyone. Rows flagged
 * inactive by the source, rows with no member or event, and rows for events that
 * ended before the look-back window are ignored. Safety:
 *
 * - a read that returns no rows at all makes no changes;
 * - when a run would suspend more than the guard percentage of a course's active
 *   enrolments (and at least the guard floor), that course's suspensions are
 *   skipped and logged;
 * - a dry run reports every change without making any.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration_sync {
    /** @var string Enrol active registrants. */
    public const MODE_ENROL = 'enrol';

    /** @var string Suspend members whose registrations are all cancelled. */
    public const MODE_CANCEL = 'cancel';

    /** @var reader The registrations source. */
    private reader $reader;

    /** @var course_resolver Resolves an event code to a Moodle course. */
    private course_resolver $resolver;

    /** @var bool Report changes without making them. */
    private bool $dryrun;

    /** @var string[] Status codes meaning cancelled (lower-case; matched as substrings). */
    private array $cancelledcodes;

    /** @var int Ignore events that ended more than this many days ago (0 = no limit). */
    private int $lookbackdays;

    /** @var bool Create a Moodle account for a registrant who has none. */
    private bool $createusers;

    /** @var int Skip a course's suspensions above this share (%) of its active enrolments. */
    private int $guardpct;

    /** @var int The suspension guard applies only from this many suspensions in one course. */
    private int $guardfloor;

    /** @var int The current time (overridable for tests). */
    private int $now;

    /** @var callable Receives each log line. */
    private $trace;

    /**
     * Build the engine.
     *
     * @param reader          $reader   The registrations source.
     * @param course_resolver $resolver Resolves an event code to a Moodle course.
     * @param array           $options  dryrun (bool), cancelledcodes (string[]), lookbackdays (int),
     *                                  createusers (bool), guardpct (int), guardfloor (int), now (int),
     *                                  trace (callable).
     */
    public function __construct(reader $reader, course_resolver $resolver, array $options = []) {
        $this->reader   = $reader;
        $this->resolver = $resolver;
        $this->dryrun   = !empty($options['dryrun']);
        $codes = array_map(
            static fn($code) => \core_text::strtolower(trim((string)$code)),
            (array)($options['cancelledcodes'] ?? ['C'])
        );
        $this->cancelledcodes = array_values(array_filter($codes, static fn($code) => $code !== ''));
        $this->lookbackdays   = max(0, (int)($options['lookbackdays'] ?? 7));
        $this->createusers    = !empty($options['createusers']);
        $this->guardpct       = max(0, min(100, (int)($options['guardpct'] ?? 50)));
        $this->guardfloor     = max(1, (int)($options['guardfloor'] ?? 10));
        $this->now            = (int)($options['now'] ?? time());
        $this->trace          = $options['trace'] ?? static function (string $line): void {
            // Cron runs on the CLI; on a web page the report carries the messages instead.
            if (CLI_SCRIPT) {
                mtrace('iMIS Bridge: ' . $line);
            }
        };
    }

    /**
     * Run one sync pass.
     *
     * @param string      $mode      {@see MODE_ENROL} or {@see MODE_CANCEL}.
     * @param string|null $contactid Limit the pass to one member (iMIS ID), or null for all.
     * @return array The report: counts plus a `messages` list.
     */
    public function run(string $mode, ?string $contactid = null): array {
        $report = [
            'mode'            => $mode,
            'dryrun'          => $this->dryrun,
            'lookbackdays'    => $this->lookbackdays,
            'rowsread'        => 0,
            'rowsskipped'     => 0,
            'rowsoutofwindow' => 0,
            'accountscreated' => 0,
            'enrolled'        => 0,
            'reactivated'     => 0,
            'alreadyactive'   => 0,
            'suspended'       => 0,
            'guardskipped'    => 0,
            'failed'          => 0,
            'aborted'         => false,
            'messages'        => [],
        ];

        $rows = $this->reader->fetch_rows();
        if (empty($rows)) {
            // An empty read cannot be told apart from a silent failure, and acting on
            // it could suspend everyone, so make no changes at all.
            $this->log($report, 'no registration rows returned; skipping (no changes made)');
            $report['aborted'] = true;
            return $report;
        }

        $pairs = $this->build_pairs($rows, $contactid === null ? null : trim($contactid), $report);
        if ($contactid !== null && empty($pairs)) {
            $this->log($report, "no current registrations for member {$contactid}");
        }

        if ($mode === self::MODE_ENROL) {
            $this->enrol($pairs, $report);
        } else if ($mode === self::MODE_CANCEL) {
            $this->cancel($pairs, $report);
        }
        return $report;
    }

    /**
     * Group the usable rows by (member, course).
     *
     * @param registration_row[] $rows      The source rows.
     * @param string|null        $contactid Keep only this member's rows, or null for all.
     * @param array              $report    The running report, by reference.
     * @return array key => ['imisid', 'courseid', 'active', 'cancelled', 'unknown' (bools), 'row', 'keys' => string[]]
     */
    private function build_pairs(array $rows, ?string $contactid, array &$report): array {
        $cutoff   = ($this->lookbackdays > 0) ? $this->now - $this->lookbackdays * DAYSECS : null;
        $unmapped = [];
        $unknown  = [];
        $pairs    = [];

        foreach ($rows as $row) {
            if ($contactid !== null && strcasecmp($row->imisid, $contactid) !== 0) {
                continue;
            }
            $report['rowsread']++;

            if ($row->imisid === '' || $row->eventcode === '') {
                $report['rowsskipped']++;
                continue;
            }
            if ($row->isactive === false) {
                $this->log($report, "registration {$row->key} (iMIS {$row->imisid}): marked inactive by the source; skipped");
                $report['rowsskipped']++;
                continue;
            }
            if ($cutoff !== null && $row->enddate !== null && $row->enddate < $cutoff) {
                $report['rowsoutofwindow']++;
                continue;
            }

            $courseid = $this->resolver->resolve_courseid($row->eventcode);
            if ($courseid === null) {
                if (!isset($unmapped[$row->eventcode])) {
                    $this->log($report, "event {$row->eventcode}: no Moodle course mapped; its registrations are skipped");
                    $unmapped[$row->eventcode] = true;
                }
                $report['rowsskipped']++;
                continue;
            }

            $key = \core_text::strtolower($row->imisid) . '|' . $courseid;
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'imisid'    => $row->imisid,
                    'courseid'  => $courseid,
                    'active'    => false,
                    'cancelled' => false,
                    'unknown'   => false,
                    'row'       => $row,
                    'keys'      => [],
                ];
            }
            if ($row->status === '') {
                $pairs[$key]['active'] = true;
                // Prefer an active row's contact details for account creation.
                $pairs[$key]['row'] = $row;
            } else if ($this->is_cancelled($row->status)) {
                $pairs[$key]['cancelled'] = true;
            } else {
                $pairs[$key]['unknown'] = true;
                if (!isset($unknown[$row->status])) {
                    $this->log($report, "status \"{$row->status}\" is not recognised (not blank and no cancelled code); "
                        . "its registrations neither enrol nor suspend");
                    $unknown[$row->status] = true;
                }
                $report['rowsskipped']++;
            }
            if ($row->key !== '') {
                $pairs[$key]['keys'][] = $row->key;
            }
        }
        return $pairs;
    }

    /**
     * Whether a (non-blank) registration status means cancelled (contains a cancelled code).
     *
     * @param string $status The raw status.
     * @return bool
     */
    private function is_cancelled(string $status): bool {
        $status = \core_text::strtolower($status);
        foreach ($this->cancelledcodes as $code) {
            if (str_contains($status, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Give every member with an active registration an active enrolment.
     *
     * @param array $pairs  The (member, course) pairs.
     * @param array $report The running report, by reference.
     * @return void
     */
    private function enrol(array $pairs, array &$report): void {
        foreach ($pairs as $pair) {
            if (!$pair['active']) {
                continue;
            }
            $courseid = (int)$pair['courseid'];
            $label    = "course {$courseid}: iMIS {$pair['imisid']}";

            $user = util::find_user_by_imis_id($pair['imisid']);
            if ($user === null) {
                if (!$this->createusers) {
                    $this->log($report, "{$label}: no Moodle account (account creation is off); not enrolled");
                    $report['rowsskipped']++;
                    continue;
                }
                $reason = $this->cannot_create($pair['row']);
                if ($reason === null && enrolment::manual_instance($courseid) === null) {
                    // Checked before creating, so a live run never leaves an account it cannot enrol.
                    $reason = 'the course has no enabled manual enrolment method';
                }
                if ($reason !== null) {
                    $this->log($report, "{$label}: cannot create an account ({$reason}); not enrolled");
                    $report['rowsskipped']++;
                    continue;
                }
                if ($this->dryrun) {
                    $this->log($report, "{$label}: would create an account and enrol");
                    $report['accountscreated']++;
                    $report['enrolled']++;
                    continue;
                }
                try {
                    $userid = $this->create_account($pair['row']);
                } catch (\Throwable $e) {
                    // One bad record must not stop every later registrant being processed.
                    $this->log($report, "{$label}: account creation failed ({$e->getMessage()}); not enrolled");
                    $report['failed']++;
                    continue;
                }
                $report['accountscreated']++;
                $this->log($report, "{$label}: created account (user {$userid})");
            } else {
                $userid = (int)$user->id;
            }

            $result = enrolment::ensure_active($userid, $courseid, $this->dryrun);
            $would  = $this->dryrun ? 'would be ' : '';
            switch ($result) {
                case enrolment::ALREADY:
                    $report['alreadyactive']++;
                    break;
                case enrolment::ENROLLED:
                    $report['enrolled']++;
                    $this->log($report, "{$label} (user {$userid}): {$would}enrolled");
                    break;
                case enrolment::REACTIVATED:
                    $report['reactivated']++;
                    $this->log($report, "{$label} (user {$userid}): suspended enrolment {$would}reactivated");
                    break;
                default:
                    $this->log($report, "course {$courseid}: no enabled manual enrolment method; "
                        . "iMIS {$pair['imisid']} not enrolled");
                    $report['rowsskipped']++;
            }
        }
    }

    /**
     * Suspend members whose registrations for a course are all cancelled.
     *
     * @param array $pairs  The (member, course) pairs.
     * @param array $report The running report, by reference.
     * @return void
     */
    private function cancel(array $pairs, array &$report): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        // Collect, per course, the members who still hold an unsuspended enrolment.
        // That includes future-dated ones and ones owned by a disabled plugin, which
        // would otherwise grant access later despite the cancellation.
        $unsuspended = [];
        $bycourse    = [];
        foreach ($pairs as $pair) {
            if ($pair['active'] || $pair['unknown'] || !$pair['cancelled']) {
                continue;
            }
            $user = util::find_user_by_imis_id($pair['imisid']);
            if ($user === null) {
                continue;
            }
            $courseid = (int)$pair['courseid'];
            $unsuspended[$courseid] ??= array_flip(enrolment::unsuspended_userids($courseid));
            if (!isset($unsuspended[$courseid][(int)$user->id])) {
                continue;
            }
            $bycourse[$courseid][(int)$user->id] = $pair['imisid'];
        }

        foreach ($bycourse as $courseid => $members) {
            $count  = count($members);
            $active = count($unsuspended[$courseid]);
            if ($count >= $this->guardfloor && $active > 0 && ($count / $active) * 100 > $this->guardpct) {
                $this->log($report, "course {$courseid}: suspension guard tripped (would suspend {$count} of "
                    . "{$active} active enrolments); skipping its suspensions");
                $report['guardskipped']++;
                continue;
            }

            foreach ($members as $userid => $imisid) {
                if ($this->dryrun) {
                    $this->log($report, "course {$courseid}: iMIS {$imisid} (user {$userid}): would be suspended");
                    $report['suspended']++;
                    continue;
                }
                // All of a member's enrolments in the course change together or not at all.
                $transaction = $DB->start_delegated_transaction();
                try {
                    enrolment::suspend($userid, $courseid);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    // Rolls back and rethrows, so the task fails and is retried.
                    $transaction->rollback($e);
                }
                $this->log($report, "course {$courseid}: iMIS {$imisid} (user {$userid}): suspended");
                $report['suspended']++;
            }
        }
    }

    /**
     * Why an account cannot be created for a registrant, or null when it can.
     *
     * @param registration_row $row The registrant's row.
     * @return string|null
     */
    private function cannot_create(registration_row $row): ?string {
        global $CFG, $DB;

        $source = util::imis_id_source();
        if ($source === 'email') {
            return 'accounts are only created when the iMIS ID source is username or ID number';
        }
        if ($row->firstname === '' || $row->lastname === '') {
            return 'no first or last name';
        }
        if ($row->email === '' || !validate_email($row->email)) {
            return 'no valid email address';
        }
        $username = \core_text::strtolower($row->imisid);
        if (clean_param($username, PARAM_USERNAME) !== $username) {
            return 'the iMIS ID is not a valid username';
        }
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
            return "an existing account already uses username {$username}";
        }
        if ($DB->record_exists('user', [$source => $row->imisid, 'deleted' => 0])) {
            // More than one account matches the iMIS ID, so it could not be resolved.
            return 'more than one account matches the iMIS ID';
        }
        return null;
    }

    /**
     * Create an account for a registrant: username and ID number set to the iMIS ID,
     * SAML2 sign-in when that plugin is enabled, and a long random password (never
     * used with SAML2, and never derived from the iMIS ID).
     *
     * @param registration_row $row The registrant's row.
     * @return int The new user's ID.
     */
    private function create_account(registration_row $row): int {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $user = (object)[
            'username'   => \core_text::strtolower($row->imisid),
            'idnumber'   => $row->imisid,
            'firstname'  => $row->firstname,
            'lastname'   => $row->lastname,
            'email'      => $row->email,
            'auth'       => is_enabled_auth('saml2') ? 'saml2' : 'manual',
            'confirmed'  => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'password'   => generate_password(32),
        ];
        return (int)user_create_user($user, true, true);
    }

    /**
     * Record a line in the report and pass it to the trace.
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
