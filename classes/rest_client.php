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
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * iMIS REST client wrapper class.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_client implements \local_imisbridge\provider\provider_interface {
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

    /**
     * Constructor — reads REST settings from plugin configuration.
     *
     * @throws \moodle_exception If no REST base URL is configured.
     */
    public function __construct() {
        $base = (string)get_config('local_imisbridge', 'rest_base_url');
        if ($base === '') {
            throw new \moodle_exception('norestbaseurl', 'local_imisbridge');
        }
        $this->baseurl  = rtrim($base, '/');
        $this->username = (string)get_config('local_imisbridge', 'rest_username');
        $this->password = (string)get_config('local_imisbridge', 'rest_password');

        $timeout = (int)get_config('local_imisbridge', 'ws_timeout');
        $this->timeout = ($timeout > 0) ? $timeout : 30;
    }

    /**
     * A short human-readable name for this provider.
     *
     * @return string
     */
    public static function label(): string {
        return get_string('transport_rest', 'local_imisbridge');
    }

    /**
     * Whether the REST provider has the minimum configuration to connect (a base URL).
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return !empty(get_config('local_imisbridge', 'rest_base_url'));
    }

    /**
     * The direct REST provider pushes records; it has no inbound order/group sync.
     *
     * @param string $capability One of the provider CAP_* constants.
     * @return bool
     */
    public static function supports(string $capability): bool {
        return $capability === \local_imisbridge\provider\provider_interface::CAP_PUSH;
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
            self::extra_columns($data)
        );
    }

    /**
     * Push a supplementary module-completion note as a MOODLENOTE activity.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_module_note(\stdClass $data): void {
        $this->add_note(
            (string)$data->imisid,
            (string)($data->coursenum ?? ''),
            (string)($data->status ?? ''),
            (float)($data->score ?? 0),
            (string)($data->date ?? date('Y-m-d')),
            'Module completion: ' . (string)($data->cmname ?? ''),
            ['UF_5' => (int)($data->cmid ?? 0)]
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
            $column = trim((string)get_config('local_imisbridge', $setting));
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
            logger::record_call('token', null, null, logger::STATUS_ERROR, 'HTTP ' . $code);
            throw new \moodle_exception('resttokenfailed', 'local_imisbridge', '', $code);
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
     * @param string      $method One of GET, POST, PUT, DELETE.
     * @param string      $path   Path beginning with '/', appended to the base URL.
     * @param array|null  $json   Body to send as JSON, or null for no body.
     * @param string|null $imisid The affected contact iMIS ID, for the audit log.
     * @return object The decoded response: {code:int, body:mixed, raw:string}.
     * @throws \moodle_exception On a non-2xx response or transport failure.
     */
    public function request(string $method, string $path, ?array $json = null, ?string $imisid = null): object {
        $result = $this->raw_request($method, $path, $json, $this->get_token());

        // A 401 means the token expired or was revoked — refresh once and retry.
        if ($result->code === 401) {
            $result = $this->raw_request($method, $path, $json, $this->get_token(true));
        }

        $ok = ($result->code >= 200 && $result->code < 300);
        logger::record_call(
            $method . ' ' . $path,
            $imisid,
            null,
            $ok ? logger::STATUS_SUCCESS : logger::STATUS_ERROR,
            $ok ? null : 'HTTP ' . $result->code,
            ($json !== null) ? json_encode($json) : null,
            $result->raw
        );

        if (!$ok) {
            // Throw so the adhoc task retries with backoff, and so a failed read is
            // never mistaken for "no activity" (which would cause a duplicate POST).
            $detail = $method . ' ' . $path . ' returned HTTP ' . $result->code;
            throw new \moodle_exception('restrequestfailed', 'local_imisbridge', '', $detail);
        }

        return $result;
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
        $factory = \core\lock\lock_config::get_lock_factory('local_imisbridge_rest');
        $lock = $factory->get_lock('completion_' . $partyid . '_' . $courseid, $this->timeout);
        if (!$lock) {
            // Another worker holds this contact/course; let the adhoc task retry.
            throw new \moodle_exception('restlockfailed', 'local_imisbridge');
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
     * @param string $courseid    The numeric Moodle course id (UF_1).
     * @param string $status      The status (UF_3), e.g. PASS/FAIL.
     * @param float  $score       The score (UF_4).
     * @param string $date        Activity date (TRANSACTION_DATE), local Y-m-d.
     * @param string $description Free-text description.
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
        array $extra = []
    ): object {
        $body = self::build_activity_body(
            self::ACTIVITY_MOODLENOTE,
            $partyid,
            $courseid,
            $status,
            $score,
            $date,
            $description,
            $extra
        );
        return $this->request('POST', '/api/Activity', $body, $partyid);
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
