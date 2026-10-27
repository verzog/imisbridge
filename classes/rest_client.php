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
 * Direct iMIS REST client (no ATS middleware).
 *
 * Implements the outbound completion/score writeback against the iMIS REST API,
 * as an alternative transport to {@see imis_client} (SOAP). It is selected when
 * the plugin's `transport` setting is `rest`. See docs/DIRECT-REST-ARCHITECTURE.md
 * for the model: a canonical `MOODLE` activity per course (upserted on completion)
 * and append-only `MOODLENOTE` rows for per-attempt scores.
 *
 * Verified against a dev iMIS instance for auth, read and activity create/delete.
 * The exact raw columns backing credit and the grant/completion dates were not
 * observed, so those are sent only when an operator maps them via the optional
 * column settings (default: not sent). The POST entity shape and the PUT update
 * contract should be confirmed against the target iMIS before production use.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * iMIS REST client wrapper class.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_client implements \local_membershipbridge\provider\org_sync_provider {
    /** @var string Canonical activity type for the per-course completion record. */
    public const ACTIVITY_MOODLE = 'MOODLE';

    /** @var string Activity type for append-only supplementary note records. */
    public const ACTIVITY_MOODLENOTE = 'MOODLENOTE';

    /** @var string The iMIS base URL with any trailing slash removed. */
    private string $baseurl;

    /** @var string The service-account username for the OAuth password grant. */
    private string $username;

    /** @var string The service-account password for the OAuth password grant. */
    private string $password;

    /** @var int Connection/read timeout in seconds. */
    private int $timeout;

    /** @var string|null Cached bearer token for this instance. */
    private ?string $token = null;

    /** @var int Unix time after which the cached token must be refreshed. */
    private int $tokenexpiry = 0;

    /** @var registration\registration_sync|null The registration sync, built once per client. */
    private ?registration\registration_sync $regsync = null;

    /** @var bool Apply registrations whatever their event's end date (one-member manual override). */
    private bool $ignorelookback = false;

    /**
     * Constructor — reads REST settings from plugin configuration.
     *
     * @throws \moodle_exception If no REST base URL is configured.
     */
    public function __construct() {
        $base = (string)get_config('local_membershipbridge', 'rest_base_url');
        if ($base === '') {
            throw new \moodle_exception('norestbaseurl', 'local_membershipbridge');
        }
        $this->baseurl  = rtrim($base, '/');
        $this->username = (string)get_config('local_membershipbridge', 'rest_username');
        $this->password = (string)get_config('local_membershipbridge', 'rest_password');

        $timeout = (int)get_config('local_membershipbridge', 'ws_timeout');
        $this->timeout = ($timeout > 0) ? $timeout : 30;
    }

    /**
     * A short human-readable name for this provider.
     *
     * @return string
     */
    public static function label(): string {
        return get_string('transport_rest', 'local_membershipbridge');
    }

    /**
     * Whether the REST provider has the minimum configuration to connect (a base URL).
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return !empty(get_config('local_membershipbridge', 'rest_base_url'));
    }

    /**
     * The direct REST provider pushes records and syncs enrolments from a
     * registrations IQA.
     *
     * @param string $capability One of the provider CAP_* constants.
     * @return bool
     */
    public static function supports(string $capability): bool {
        return in_array($capability, [
            \local_membershipbridge\provider\provider_interface::CAP_PUSH,
            \local_membershipbridge\provider\provider_interface::CAP_ORG_SYNC,
        ], true);
    }

    /**
     * Syncing needs the REST connection and a registrations IQA, so a site that only
     * pushes over REST (no IQA set) never starts enrolling on upgrade.
     *
     * @return bool
     */
    public static function is_sync_configured(): bool {
        return self::is_configured() && trim((string)get_config('local_membershipbridge', 'reg_iqa')) !== '';
    }

    /**
     * Enrol members with an active registration (and create missing accounts).
     *
     * @param string|null $contactid One member's iMIS ID, or null for all members.
     * @return array The sync report.
     */
    public function sync_orders(?string $contactid = null): mixed {
        return $this->registration_sync()->run(registration\registration_sync::MODE_ENROL, $contactid);
    }

    /**
     * Suspend members whose registrations for a course are all cancelled.
     *
     * @param string|null $contactid One member's iMIS ID, or null for all members.
     * @return array The sync report.
     */
    public function sync_cancelled_orders(?string $contactid = null): mixed {
        return $this->registration_sync()->run(registration\registration_sync::MODE_CANCEL, $contactid);
    }

    /**
     * Apply registrations for events that ended before the look-back window, for the
     * rest of this client's life. Only the one-member manual sync on the admin page
     * uses this, so an administrator can apply a late cancellation or enrolment; the
     * scheduled tasks always keep the window.
     *
     * @return void
     */
    public function ignore_lookback(): void {
        $this->ignorelookback = true;
        $this->regsync = null;
    }

    /**
     * Groups come from the tutorial group sync task, which already reads iMIS over
     * REST; running it here (on every login) would re-read the whole panel each time.
     *
     * @param string|null $contactid   Unused.
     * @param string|null $courseid    Unused.
     * @param string|null $lastupdated Unused.
     * @return string A note saying where groups are synced.
     */
    public function update_groups(?string $contactid = null, ?string $courseid = null, ?string $lastupdated = null): mixed {
        return get_string('regsync_groupsnote', 'local_membershipbridge');
    }

    /**
     * Read-only check: authenticate and read the registrations IQA, listing one
     * member's registrations when an iMIS ID is given.
     *
     * @param string|null $contactid Optional member iMIS ID.
     * @return string The diagnostic report.
     */
    public function diagnostics(?string $contactid): string {
        $query = trim((string)get_config('local_membershipbridge', 'reg_iqa'));
        $out   = 'REST base URL: ' . $this->baseurl . PHP_EOL;
        $this->get_token();
        $out  .= 'Token: OK' . PHP_EOL;

        $rows = (new registration\rest_reader($this, $query))->fetch_rows();
        $out .= 'Registrations IQA ' . $query . ': ' . count($rows) . ' row(s)' . PHP_EOL;
        if (!empty($contactid)) {
            foreach ($rows as $row) {
                if (strcasecmp($row->imisid, trim($contactid)) === 0) {
                    $out .= sprintf(
                        '  %s  event %s  status "%s"  active %s  ends %s%s',
                        $row->key,
                        $row->eventcode,
                        $row->status,
                        var_export($row->isactive, true),
                        $row->enddate === null ? '-' : userdate($row->enddate, '%Y-%m-%d'),
                        PHP_EOL
                    );
                }
            }
        }
        return $out;
    }

    /**
     * An integer setting, or the default when it is unset or blank (an explicit 0 is kept).
     *
     * @param string $name    The setting name.
     * @param int    $default The default.
     * @return int
     */
    private static function int_setting(string $name, int $default): int {
        $value = get_config('local_membershipbridge', $name);
        return ($value === false || trim((string)$value) === '') ? $default : (int)$value;
    }

    /**
     * The registration sync engine, built from the plugin settings once per client.
     * Its reader keeps the rows it read, so an enrolment pass followed by a
     * cancellation pass (as a login sync runs them) reads the IQA only once.
     *
     * @return registration\registration_sync
     */
    private function registration_sync(): registration\registration_sync {
        if ($this->regsync !== null) {
            return $this->regsync;
        }
        $codes = array_filter(array_map('trim', explode(',', (string)get_config('local_membershipbridge', 'reg_cancelled_codes'))));
        return $this->regsync = new registration\registration_sync(
            new registration\rest_reader($this, trim((string)get_config('local_membershipbridge', 'reg_iqa'))),
            new tutorial\rest_course_resolver($this),
            [
                'dryrun'         => util::is_enabled('reg_dry_run'),
                'createusers'    => util::is_enabled('reg_create_users'),
                'cancelledcodes' => $codes ?: ['C'],
                'lookbackdays'   => $this->ignorelookback ? 0 : self::int_setting('reg_lookback_days', 7),
                'guardpct'       => self::int_setting('reg_guard_pct', 50),
            ]
        );
    }

    /**
     * Push a course completion: upsert the canonical MOODLE activity (status COMPLETE).
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_completion(\stdClass $data): void {
        $courseid = (string)($data->coursenum ?? '');
        $this->upsert_completion(
            (string)$data->imisid,
            $courseid,
            'COMPLETE',
            (float)($data->score ?? 0),
            (string)($data->completiondate ?? date('Y-m-d')),
            'Moodle course ' . $courseid . ' completion',
            self::extra_columns($data)
        );
    }

    /**
     * Push an assessment score: append a MOODLENOTE row so the course-level record is untouched.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_score(\stdClass $data): void {
        $courseid = (string)($data->coursenum ?? '');
        $this->add_note(
            (string)$data->imisid,
            $courseid,
            strtoupper((string)($data->status ?? '')),
            (float)($data->score ?? 0),
            (string)($data->completiondate ?? date('Y-m-d')),
            'Moodle course ' . $courseid . ' score',
            'Score : Moodle course ' . $courseid,
            'score',
            ['SOURCE_CODE' => 'quiz'] + self::extra_columns($data)
        );
    }

    /**
     * Push a supplementary module note — a module completion, or an activity score
     * when `notekind` is 'score' — as a MOODLENOTE activity.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_module_note(\stdClass $data): void {
        $isscore = (($data->notekind ?? '') === 'score');
        $cmname  = (string)($data->cmname ?? '');
        $this->add_note(
            (string)$data->imisid,
            (string)($data->coursenum ?? ''),
            (string)($data->status ?? ''),
            (float)($data->score ?? 0),
            (string)($data->date ?? date('Y-m-d')),
            $cmname,
            ($isscore ? 'Score : ' : 'Module completed : ') . $cmname,
            $isscore ? 'score' : 'modulecompletion',
            ['UF_5' => (int)($data->cmid ?? 0), 'SOURCE_CODE' => (string)($data->modname ?? '')]
        );
    }

    /**
     * Map the push data onto the optional, operator-configured iMIS columns.
     *
     * Each mapping is applied only when its column-name setting is non-empty, so
     * columns whose raw names are not yet confirmed against iMIS are simply not
     * sent rather than guessed.
     *
     * @param \stdClass $data The queued push data.
     * @return array Column name => value pairs to set on the activity.
     */
    private static function extra_columns(\stdClass $data): array {
        $map = [
            'rest_col_credittype'     => $data->credittype ?? '',
            'rest_col_creditvalue'    => (float)($data->creditvalue ?? 0),
            'rest_col_grantdate'      => $data->grantdate ?? '',
            'rest_col_completiondate' => $data->completiondate ?? '',
        ];

        $extra = [];
        foreach ($map as $setting => $value) {
            $column = trim((string)get_config('local_membershipbridge', $setting));
            if ($column !== '') {
                $extra[$column] = $value;
            }
        }
        return $extra;
    }

    /**
     * Obtain a bearer token via the OAuth password grant, caching it per instance.
     *
     * Tokens are short-lived; the cached token is reused until shortly before its
     * stated expiry, then re-requested. Pass true to force a refresh (used after a
     * 401, in case the token was revoked before its stated expiry).
     *
     * @param bool $force Request a new token even if the cached one looks valid.
     * @return string The bearer token.
     * @throws \moodle_exception If the token endpoint does not return a token.
     */
    public function get_token(bool $force = false): string {
        if (!$force && $this->token !== null && time() < $this->tokenexpiry) {
            return $this->token;
        }

        $body = http_build_query([
            'grant_type' => 'password',
            'username'   => $this->username,
            'password'   => $this->password,
        ]);

        $curl = $this->new_curl();
        $curl->setHeader('Content-Type: application/x-www-form-urlencoded');
        $response = $curl->post($this->baseurl . '/token', $body);
        $code = (int)($curl->get_info()['http_code'] ?? 0);

        $decoded = json_decode((string)$response);
        if ($code !== 200 || !is_object($decoded) || empty($decoded->access_token)) {
            $reason = self::error_reason((string)$response);
            $error  = 'HTTP ' . $code . ($reason !== '' ? ': ' . $reason : '');
            logger::record_call('token', null, null, logger::STATUS_ERROR, $error);
            throw new \moodle_exception('resttokenfailed', 'local_membershipbridge', '', $code);
        }

        $this->token = (string)$decoded->access_token;
        // Refresh a minute before the stated expiry to avoid racing the cut-off.
        $expiresin = (int)($decoded->expires_in ?? 3600);
        $this->tokenexpiry = time() + max(0, $expiresin - 60);

        return $this->token;
    }

    /**
     * Perform an authenticated REST request, refreshing the token once on a 401.
     *
     * @param string      $method      One of GET, POST, PUT, DELETE.
     * @param string      $path        Path beginning with '/', appended to the base URL.
     * @param array|null  $json        Body to send as JSON, or null for no body.
     * @param string|null $imisid      The affected contact iMIS ID, for the audit log.
     * @param bool        $capturebody Whether to store a successful response body in the
     *                                 audit log. False for bulk reads whose body holds many
     *                                 contacts' data that could not be attributed to a single
     *                                 user. An error response carries no such data, so its
     *                                 body is always eligible.
     * @return object The decoded response: {code:int, body:mixed, raw:string}.
     * @throws \moodle_exception On a non-2xx response or transport failure.
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        ?string $imisid = null,
        bool $capturebody = true
    ): object {
        $result = $this->raw_request($method, $path, $json, $this->get_token());

        // A 401 means the token expired or was revoked — refresh once and retry.
        if ($result->code === 401) {
            $result = $this->raw_request($method, $path, $json, $this->get_token(true));
        }

        $ok = ($result->code >= 200 && $result->code < 300);
        // On failure, iMIS says why in the body (a missing prompt, an unknown query);
        // keep that short reason with the error, whatever the payload-capture setting.
        $reason = $ok ? '' : self::error_reason($result->raw);
        $error  = 'HTTP ' . $result->code . ($reason !== '' ? ': ' . $reason : '');
        logger::record_call(
            $method . ' ' . $path,
            $imisid,
            null,
            $ok ? logger::STATUS_SUCCESS : logger::STATUS_ERROR,
            $ok ? null : $error,
            ($json !== null) ? json_encode($json) : null,
            ($capturebody || !$ok) ? $result->raw : null
        );

        if (!$ok) {
            // Throw so the adhoc task retries with backoff, and so a failed read is
            // never mistaken for "no activity" (which would cause a duplicate POST).
            $detail = $method . ' ' . $path . ' returned ' . $error;
            throw new \moodle_exception('restrequestfailed', 'local_membershipbridge', '', $detail);
        }

        return $result;
    }

    /**
     * A short, single-line reason from an iMIS error response body: the message from a
     * JSON error (the detailed ExceptionMessage or error_description before a generic
     * Message or error), a JSON string, or else the body's text without its scripts and
     * styles. Markup is removed, and it is at most 300 characters; empty
     * when the body says nothing.
     *
     * @param string $raw The raw response body.
     * @return string
     */
    public static function error_reason(string $raw): string {
        $decoded = json_decode($raw);
        $text = '';
        if (is_string($decoded)) {
            $text = $decoded;
        } else if (is_object($decoded)) {
            foreach (['ExceptionMessage', 'error_description', 'Message', 'message', 'error'] as $key) {
                if (isset($decoded->$key) && is_scalar($decoded->$key) && trim((string)$decoded->$key) !== '') {
                    $text = (string)$decoded->$key;
                    break;
                }
            }
        }
        if ($text === '' && $decoded === null) {
            // An HTML error page: drop scripts and styles, whose text would crowd out the reason.
            $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $raw) ?? $raw;
        }
        // Markup is removed from every reason: it is shown on admin pages as-is.
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        return \core_text::strlen($text) > 300 ? \core_text::substr($text, 0, 297) . '...' : $text;
    }

    /**
     * Issue a single HTTP request with the given bearer token (no retry).
     *
     * @param string      $method One of GET, POST, PUT, DELETE.
     * @param string      $path   Path beginning with '/'.
     * @param array|null  $json   Body to send as JSON, or null.
     * @param string      $token  The bearer token.
     * @return object {code:int, body:mixed, raw:string}.
     */
    private function raw_request(string $method, string $path, ?array $json, string $token): object {
        $curl = $this->new_curl();
        $curl->setHeader('Authorization: Bearer ' . $token);
        $curl->setHeader('Accept: application/json');

        $url = $this->baseurl . $path;
        $payload = null;
        if ($json !== null) {
            $curl->setHeader('Content-Type: application/json');
            $payload = json_encode($json);
        }

        switch (strtoupper($method)) {
            case 'POST':
                $raw = $curl->post($url, (string)$payload);
                break;
            case 'PUT':
                $raw = $curl->put($url, (string)$payload);
                break;
            case 'DELETE':
                $raw = $curl->delete($url);
                break;
            default:
                $raw = $curl->get($url);
                break;
        }

        $code = (int)($curl->get_info()['http_code'] ?? 0);
        return (object)['code' => $code, 'body' => json_decode((string)$raw), 'raw' => (string)$raw];
    }

    /**
     * Build a configured Moodle curl client with the request timeout applied.
     *
     * @return \curl
     */
    private function new_curl(): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
        ]);
        return $curl;
    }

    /**
     * Read the activity rows for a contact, following paging to the end.
     *
     * The iMIS REST API returns ~100 rows per page and exposes HasNext/NextOffset,
     * so a contact with many activities would otherwise have its matching row
     * missed. This aggregates every page.
     *
     * @param string $partyid The contact iMIS ID.
     * @param int    $limit   Page size.
     * @return array The aggregated row entities (objects), in API order.
     */
    public function get_activities(string $partyid, int $limit = 100): array {
        $rows = [];
        $offset = 0;
        do {
            $path = '/api/Activity?PartyId=' . rawurlencode($partyid)
                . '&Limit=' . $limit . '&Offset=' . $offset;
            $result = $this->request('GET', $path, null, $partyid);
            if (!is_object($result->body)) {
                break;
            }
            $page = self::items($result->body);
            foreach ($page as $row) {
                $rows[] = $row;
            }
            [$hasnext, $offset] = self::next_page($result->body, $offset, count($page));
        } while ($hasnext);

        return $rows;
    }

    /**
     * Read every row of a GenericEntity list endpoint, following paging to the end.
     *
     * The same envelope as {@see get_activities()} (Items.$values plus
     * HasNext/NextOffset), but for an arbitrary entity path such as
     * `/api/MoodleTutorialGroup`. Any existing query string on the path is kept and
     * the Limit/Offset paging parameters are appended.
     *
     * @param string $path  The entity path, optionally with a query string.
     * @param int    $limit Page size.
     * @return array The aggregated row entities (objects), in API order.
     */
    public function get_all_entities(string $path, int $limit = 100): array {
        $rows = [];
        $offset = 0;
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        do {
            $pagepath = $path . $sep . 'Limit=' . $limit . '&Offset=' . $offset;
            // Do not capture the response body in the audit log: a bulk list holds
            // many contacts' data that cannot be attributed to a single user.
            $result = $this->request('GET', $pagepath, null, null, false);
            if (!is_object($result->body)) {
                // A 2xx with an unreadable body means we cannot trust this page. Abort
                // rather than return a partial set: callers treat the result as the
                // complete, authoritative state, so a short read could drive removals.
                $detail = 'GET ' . $pagepath . ' returned an unreadable body';
                throw new \moodle_exception('restrequestfailed', 'local_membershipbridge', '', $detail);
            }
            $page = self::items($result->body);
            foreach ($page as $row) {
                $rows[] = $row;
            }
            [$hasnext, $offset] = self::next_page($result->body, $offset, count($page));
        } while ($hasnext);

        return $rows;
    }

    /**
     * Run an iMIS IQA (saved query) over REST and return all its result rows.
     *
     * Any prompts are passed as repeated `Parameter` query entries in prompt order,
     * per the iMIS IQA API. The envelope (Items.$values plus HasNext/NextOffset) is
     * the standard GenericEntityData one, so this pages to the end and reads the rows
     * with {@see items()} and {@see property()}. A page with an unreadable body aborts
     * (throws) rather than returning a partial set: callers rely on completeness, and
     * a short read of a lookup could otherwise make valid codes look unmapped.
     *
     * @param string $queryname  The IQA document path, e.g. '$/_SCCA/Events/MoodleCourseLookup'.
     * @param array  $parameters Prompt values in prompt order (empty for an unprompted query).
     * @return array The result row entities (objects).
     */
    public function run_iqa(string $queryname, array $parameters = []): array {
        $base = '/api/iqa?QueryName=' . rawurlencode($queryname);
        foreach ($parameters as $value) {
            $base .= '&Parameter=' . rawurlencode((string)$value);
        }

        $rows = [];
        $offset = 0;
        do {
            $path = $base . '&Limit=500&Offset=' . $offset;
            $result = $this->request('GET', $path, null, null, false);
            if (!is_object($result->body)) {
                $detail = 'GET ' . $path . ' returned an unreadable body';
                throw new \moodle_exception('restrequestfailed', 'local_membershipbridge', '', $detail);
            }
            $page = self::items($result->body);
            foreach ($page as $row) {
                $rows[] = $row;
            }
            [$hasnext, $offset] = self::next_page($result->body, $offset, count($page));
        } while ($hasnext);

        return $rows;
    }

    /**
     * Find the contact's canonical MOODLE activity for a course, if one exists.
     *
     * Filters the contact's activities to ACTIVITY_TYPE = MOODLE and UF_1 (course
     * id) = the given course, so a completion updates the existing row rather than
     * creating a duplicate.
     *
     * @param string $partyid  The contact iMIS ID.
     * @param string $courseid The numeric Moodle course id (UF_1).
     * @return object|null The matching activity entity, or null if none.
     */
    public function find_moodle_activity(string $partyid, string $courseid): ?object {
        foreach ($this->get_activities($partyid) as $row) {
            $type = self::property($row, 'ACTIVITY_TYPE');
            $uf1  = (string)self::property($row, 'UF_1');
            if ($type === self::ACTIVITY_MOODLE && $uf1 === $courseid) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Upsert the canonical MOODLE completion record for a contact and course.
     *
     * Serialised with a per-(contact, course) lock so two overlapping adhoc
     * workers cannot both read "absent" and create duplicate MOODLE rows. When a
     * row already exists it is updated (PUT); otherwise a new one is created
     * (POST). Only a completion event drives this path — per-attempt scores use
     * {@see add_note()} so they never overwrite the course-level status/grade.
     *
     * @param string      $partyid     The contact iMIS ID.
     * @param string      $courseid    The numeric Moodle course id (UF_1).
     * @param string      $status      The completion status (UF_3), e.g. COMPLETE.
     * @param float       $score       The final course grade percentage (UF_4).
     * @param string      $date        Activity date (TRANSACTION_DATE), local Y-m-d.
     * @param string      $description Free-text description.
     * @param array       $extra       Optional extra column => value pairs to set.
     * @return object The request result for the create or update.
     */
    public function upsert_completion(
        string $partyid,
        string $courseid,
        string $status,
        float $score,
        string $date,
        string $description,
        array $extra = []
    ): object {
        $factory = \core\lock\lock_config::get_lock_factory('local_membershipbridge_rest');
        $lock = $factory->get_lock('completion_' . $partyid . '_' . $courseid, $this->timeout);
        if (!$lock) {
            // Another worker holds this contact/course; let the adhoc task retry.
            throw new \moodle_exception('restlockfailed', 'local_membershipbridge');
        }

        try {
            $existing = $this->find_moodle_activity($partyid, $courseid);
            if ($existing !== null) {
                $seqn = (string)self::property($existing, 'SEQN');
                $body = self::clone_for_update($existing, $status, $score, $date, $description, $extra);
                return $this->request('PUT', '/api/Activity/' . rawurlencode($seqn), $body, $partyid);
            }

            $body = self::build_activity_body(
                self::ACTIVITY_MOODLE,
                $partyid,
                $courseid,
                $status,
                $score,
                $date,
                $description,
                $extra
            );
            return $this->request('POST', '/api/Activity', $body, $partyid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Append a supplementary MOODLENOTE activity (e.g. a per-attempt quiz score).
     *
     * Always a create, never a locate-and-update, so it cannot overwrite the
     * canonical MOODLE row's completion status or final grade.
     *
     * @param string $partyid     The contact iMIS ID.
     * @param string $courseid    The Moodle course number (PRODUCT_CODE on a note).
     * @param string $status      The status (UF_3), e.g. PASS/FAIL.
     * @param float  $score       The score (UF_4).
     * @param string $date        Activity date (TRANSACTION_DATE and THRU_DATE), local Y-m-d.
     * @param string $description The description, e.g. the activity name.
     * @param string $note        The note text (NOTE), e.g. "Module completed : Exam".
     * @param string $note2       The note category (NOTE_2), e.g. modulecompletion or score.
     * @param array  $extra       Optional extra column => value pairs to set.
     * @return object The request result.
     */
    public function add_note(
        string $partyid,
        string $courseid,
        string $status,
        float $score,
        string $date,
        string $description,
        string $note,
        string $note2,
        array $extra = []
    ): object {
        $body = self::build_note_body($partyid, $courseid, $status, $score, $date, $description, $note, $note2, $extra);
        return $this->request('POST', '/api/Activity', $body, $partyid);
    }

    /**
     * Build a MOODLENOTE activity laid out as the vendor bridge writes them, which is
     * what its Course Notes panel shows: the course number in PRODUCT_CODE with UF_1
     * left empty, the activity name in DESCRIPTION, the note text in NOTE, its category
     * in NOTE_2, and the date in both TRANSACTION_DATE and THRU_DATE. Every other
     * column is sent too, with its .NET type, as on the vendor's rows: iMIS rejects a
     * note that carries only the set columns (HTTP 500) and accepts the full record.
     *
     * @param string $partyid     The contact iMIS ID.
     * @param string $coursenum   The Moodle course number (PRODUCT_CODE).
     * @param string $status      The status (UF_3).
     * @param float  $score       The score (UF_4).
     * @param string $date        The date, local Y-m-d.
     * @param string $description The description (DESCRIPTION).
     * @param string $note        The note text (NOTE).
     * @param string $note2       The note category (NOTE_2).
     * @param array  $extra       Extra column => value pairs (e.g. UF_5, SOURCE_CODE); empty values are skipped.
     * @return array The entity ready for json_encode.
     */
    public static function build_note_body(
        string $partyid,
        string $coursenum,
        string $status,
        float $score,
        string $date,
        string $description,
        string $note,
        string $note2,
        array $extra = []
    ): array {
        // Every column of a vendor-written note, in iMIS's order, with its .NET type:
        // iMIS rejected (HTTP 500) a note carrying only the set columns, and accepted
        // a full copy of a vendor note. Null means the column is sent without a value.
        $values = [
            'ACTION_CODES'          => null,
            'ACTIVITY_TYPE'         => self::ACTIVITY_MOODLENOTE,
            'AMOUNT'                => self::typed('System.Decimal', 0),
            'ATTACH_SEQN'           => self::typed('System.Int32', 0),
            'ATTACH_TOTAL'          => self::typed('System.Int32', 0),
            'BATCH_NUM'             => null,
            'CAMPAIGN_CODE'         => null,
            'CATEGORY'              => null,
            'CO_ID'                 => null,
            'DESCRIPTION'           => \core_text::substr($description, 0, 255),
            'EFFECTIVE_DATE'        => null,
            'GRACE_PERIOD'          => self::typed('System.Int32', 0),
            'PartyId'               => $partyid,
            'INTENT_TO_EDIT'        => null,
            'MEM_TRIB_CODE'         => null,
            'MEMBER_TYPE'           => null,
            'NEXT_INSTALL_DATE'     => null,
            'NOTE'                  => $note,
            'NOTE_2'                => $note2,
            'OBJECT'                => null,
            'ORG_CODE'              => null,
            'ORIGINATING_TRANS_NUM' => self::typed('System.Int32', 0),
            'OTHER_CODE'            => null,
            'OTHER_ID'              => null,
            'PAY_METHOD'            => null,
            'PRODUCT_CODE'          => $coursenum,
            'QUANTITY'              => self::typed('System.Decimal', 0),
            'RECURRING_REQUEST'     => self::typed('System.Boolean', false),
            'SOLICITOR_ID'          => null,
            'SOURCE_CODE'           => null,
            'SOURCE_SYSTEM'         => null,
            'STATUS_CODE'           => null,
            'TAXABLE_VALUE'         => self::typed('System.Decimal', 0),
            'THRU_DATE'             => $date,
            'TICKLER_DATE'          => null,
            'TRANSACTION_DATE'      => $date,
            'UF_1'                  => null,
            'UF_2'                  => null,
            'UF_3'                  => $status,
            'UF_4'                  => self::typed('System.Decimal', $score),
            'UF_5'                  => self::typed('System.Decimal', 0),
            'UF_6'                  => null,
            'UF_7'                  => null,
            'UNITS'                 => self::typed('System.Decimal', 0),
        ];
        $decimals = ['AMOUNT', 'QUANTITY', 'TAXABLE_VALUE', 'UNITS', 'UF_4', 'UF_5'];
        foreach ($extra as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $values[(string)$name] = in_array($name, $decimals, true) ? self::typed('System.Decimal', (float)$value) : $value;
        }

        $props = [];
        foreach ($values as $name => $value) {
            $prop = ['$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts', 'Name' => $name];
            if ($value !== null && $value !== '') {
                $prop['Value'] = $value;
            }
            $props[] = $prop;
        }

        return [
            '$type'                       => 'Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts',
            'EntityTypeName'              => 'Activity',
            'PrimaryParentEntityTypeName' => 'Party',
            'PrimaryParentIdentity'       => self::identity_block($partyid),
            'Properties'                  => [
                '$type'   => 'Asi.Soa.Core.DataContracts.GenericPropertyDataCollection, Asi.Contracts',
                '$values' => $props,
            ],
        ];
    }

    /**
     * A typed scalar as iMIS writes one: `{"$type": "System.Decimal", "$value": 0}`.
     *
     * @param string         $type  The .NET type name, e.g. System.Decimal.
     * @param int|float|bool $value The value.
     * @return array
     */
    private static function typed(string $type, $value): array {
        return ['$type' => $type, '$value' => $value];
    }

    /**
     * Delete an activity by its SEQN. Used for test cleanup and cancellations.
     *
     * @param string $seqn The activity primary key.
     * @return object The request result.
     */
    public function delete_activity(string $seqn): object {
        return $this->request('DELETE', '/api/Activity/' . rawurlencode($seqn));
    }

    /**
     * Build the JSON body for a new activity entity.
     *
     * Sends the verified columns (PartyId, ACTIVITY_TYPE, UF_1 course id, UF_3
     * status, UF_4 score, TRANSACTION_DATE, DESCRIPTION) plus the parent-contact
     * identity block iMIS requires on create. SEQN and the read-only audit columns
     * are deliberately absent. Extra columns (e.g. mapped credit/date columns) are
     * added only when supplied, so unconfirmed mappings are simply not sent.
     *
     * @param string $activitytype The iMIS activity type (MOODLE or MOODLENOTE).
     * @param string $partyid      The contact iMIS ID.
     * @param string $courseid     The numeric Moodle course id (UF_1).
     * @param string $status       The status value (UF_3).
     * @param float  $score        The score value (UF_4).
     * @param string $date         The activity date (TRANSACTION_DATE).
     * @param string $description  Free-text description.
     * @param array  $extra        Extra column => value pairs (string keys).
     * @return array The entity ready for json_encode.
     */
    public static function build_activity_body(
        string $activitytype,
        string $partyid,
        string $courseid,
        string $status,
        float $score,
        string $date,
        string $description,
        array $extra = []
    ): array {
        $props = [];
        self::put($props, 'PartyId', $partyid);
        self::put($props, 'ACTIVITY_TYPE', $activitytype);
        self::put($props, 'UF_1', $courseid);
        self::put($props, 'UF_3', $status);
        self::put($props, 'UF_4', $score);
        self::put($props, 'TRANSACTION_DATE', $date);
        self::put($props, 'DESCRIPTION', $description);
        foreach ($extra as $name => $value) {
            if ($value !== null && $value !== '') {
                self::put($props, (string)$name, $value);
            }
        }

        return [
            'PrimaryParentEntityTypeName' => 'Party',
            'PrimaryParentIdentity'       => self::identity_block($partyid),
            'Properties'                  => ['$values' => $props],
        ];
    }

    /**
     * Clone an existing activity entity into a create-free update body.
     *
     * Keeps every zero-default column iMIS returned (so none is lost), strips the
     * read-only audit columns, and overrides only the fields a completion changes.
     *
     * @param object $template    The existing activity entity read from iMIS.
     * @param string $status      The new status (UF_3).
     * @param float  $score       The new score (UF_4).
     * @param string $date        The new activity date (TRANSACTION_DATE).
     * @param string $description The new description.
     * @param array  $extra       Extra column => value pairs to override/set.
     * @return array The entity ready for json_encode.
     */
    public static function clone_for_update(
        object $template,
        string $status,
        float $score,
        string $date,
        string $description,
        array $extra = []
    ): array {
        $entity = json_decode(json_encode($template), true);
        $props = $entity['Properties']['$values'] ?? [];

        foreach (['CREATED_ON', 'UPDATED_ON'] as $readonly) {
            self::drop($props, $readonly);
        }
        self::put($props, 'UF_3', $status);
        self::put($props, 'UF_4', $score);
        self::put($props, 'TRANSACTION_DATE', $date);
        self::put($props, 'DESCRIPTION', $description);
        foreach ($extra as $name => $value) {
            if ($value !== null && $value !== '') {
                self::put($props, (string)$name, $value);
            }
        }

        $entity['Properties']['$values'] = $props;
        return $entity;
    }

    /**
     * The PrimaryParentIdentity block iMIS requires to attach an activity.
     *
     * @param string $partyid The contact iMIS ID.
     * @return array The identity block.
     */
    public static function identity_block(string $partyid): array {
        return [
            '$type'          => 'Asi.Soa.Core.DataContracts.IdentityData, Asi.Contracts',
            'EntityTypeName' => 'Party',
            'IdentityElements' => [
                // The backtick is part of the .NET generic type name; build it via
                // chr(96) so the string literal carries no backtick (Moodle forbids them).
                '$type'   => 'System.Collections.ObjectModel.Collection' . chr(96) . '1[[System.String, mscorlib]], mscorlib',
                '$values' => [$partyid],
            ],
        ];
    }

    /**
     * Read a property value from an activity entity, unwrapping typed scalars.
     *
     * Handles both the `{"$type":...,"$value":X}` scalar wrapper and a bare value,
     * and treats a missing Value key (or missing property) as null.
     *
     * @param object $entity The activity entity.
     * @param string $name   The property Name to read.
     * @return mixed The unwrapped value, or null when absent.
     */
    public static function property(object $entity, string $name) {
        $values = self::values($entity->Properties ?? null);
        foreach ($values as $prop) {
            if (isset($prop->Name) && $prop->Name === $name) {
                if (!isset($prop->Value)) {
                    return null;
                }
                $value = $prop->Value;
                return (is_object($value) && isset($value->{'$value'})) ? $value->{'$value'} : $value;
            }
        }
        return null;
    }

    /**
     * Set or replace a property in a Properties `$values` list (array form).
     *
     * @param array  $props The Properties `$values` list, by reference.
     * @param string $name  The property Name.
     * @param mixed  $value The property Value.
     * @return void
     */
    private static function put(array &$props, string $name, $value): void {
        foreach ($props as &$prop) {
            if (($prop['Name'] ?? null) === $name) {
                $prop['Value'] = $value;
                return;
            }
        }
        unset($prop);
        $props[] = ['Name' => $name, 'Value' => $value];
    }

    /**
     * Remove a property from a Properties `$values` list (array form).
     *
     * @param array  $props The Properties `$values` list, by reference.
     * @param string $name  The property Name to remove.
     * @return void
     */
    private static function drop(array &$props, string $name): void {
        $props = array_values(array_filter($props, static function ($prop) use ($name) {
            return ($prop['Name'] ?? null) !== $name;
        }));
    }

    /**
     * Extract the `Items.$values` rows from a paged activity response.
     *
     * @param object $body The decoded response body.
     * @return array The row entities (objects).
     */
    public static function items(object $body): array {
        return self::values($body->Items ?? null);
    }

    /**
     * Resolve the next page from a PagedResult, falling back when fields are absent.
     *
     * Uses HasNext/NextOffset when present; otherwise advances by the page size and
     * stops on a short page, so a response without explicit paging still terminates.
     *
     * @param object $body       The decoded response body.
     * @param int    $offset     The current offset.
     * @param int    $pagecount  The number of rows returned in the current page.
     * @return array [bool $hasnext, int $nextoffset].
     */
    public static function next_page(object $body, int $offset, int $pagecount): array {
        if (isset($body->HasNext)) {
            $hasnext = (bool)(is_object($body->HasNext) ? ($body->HasNext->{'$value'} ?? false) : $body->HasNext);
            $nextoffset = isset($body->NextOffset) ? (int)(is_object($body->NextOffset)
                ? ($body->NextOffset->{'$value'} ?? 0) : $body->NextOffset) : ($offset + $pagecount);
            return [$hasnext, $nextoffset];
        }
        // No explicit paging metadata: the response was not paged, so stop here.
        return [false, $offset + $pagecount];
    }

    /**
     * All of a GenericEntityData row's properties as name => value, with type-wrapped
     * scalars unwrapped (a missing value is null).
     *
     * @param object $entity The row.
     * @return array<string,mixed>
     */
    public static function properties(object $entity): array {
        $out = [];
        foreach (self::values($entity->Properties ?? null) as $prop) {
            if (!isset($prop->Name)) {
                continue;
            }
            $value = $prop->Value ?? null;
            $out[(string)$prop->Name] = (is_object($value) && isset($value->{'$value'})) ? $value->{'$value'} : $value;
        }
        return $out;
    }

    /**
     * Return the `$values` array of a typed-collection object, or an empty array.
     *
     * @param mixed $collection A decoded `{"$values":[...]}` object, or null.
     * @return array The values list.
     */
    private static function values($collection): array {
        if (is_object($collection) && isset($collection->{'$values'}) && is_array($collection->{'$values'})) {
            return $collection->{'$values'};
        }
        return [];
    }
}
