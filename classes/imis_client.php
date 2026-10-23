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
 * iMIS SOAP client wrapper.
 *
 * All public methods map directly to wsmoodle.asmx operations.
 *
 * Updated 2025: ATS now requires an AuthToken parameter on the following methods:
 *   MoodleGetUserProfile, MoodleUpdate, createActivity,
 *   getActivityByIDAndType, getIQARows.
 * The AuthToken is stored in plugin settings (local_imisbridge/auth_token)
 * and injected automatically by get_api_auth_token().
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * iMIS SOAP client wrapper class.
 *
 * Public methods map directly to wsmoodle.asmx operations. They form an
 * intentional, reusable API surface: some are used by the plugin's observers
 * and tasks today, and the rest are provided for reporting and other
 * integrations to build on. Methods marked below inject the ATS AuthToken
 * automatically (see get_api_auth_token()); the exact SOAP argument names for
 * each operation come from the live WSDL, so confirm with __getFunctions() /
 * __getTypes() against the target endpoint when wiring up a new call.
 *
 * Exposed by wsmoodle.asmx (confirmed against the published operation contract):
 *   Bridge config:  get_bridge_settings (GetBridgeSettings; accepts AuthToken),
 *                   setup_bridge* (SetupBridge)
 *   Contact lookup: get_contact_by_id* (MoodleGetUserProfile),
 *                   get_contact_by_token (MoodleGetUserProfileByToken; takes a
 *                   lowercase 'token' only, no AuthToken)
 *   Enrolment sync: sync_orders* (SendNewOrdersToMoodle),
 *                   sync_cancelled_orders* (SendCancelledOrdersToMoodle)
 *   Group sync:     update_groups* (UpdateMoodleGroups; returns a ResultsBase)
 *   Activities:     get_activities* (getActivityByIDAndType),
 *                   create_activity* (createActivity),
 *                   update_activity_record* (MoodleUpdate; returns a ResultsBase)
 *   Reporting:      get_iqa_rows* (getIQARows; single jsonParams object)
 *   (* injects the ATS API AuthToken automatically.)
 *
 * The SSO/token flow (getToken, checkSSOFilter, asiEncrypt/asiDecrypt) lives on
 * the separate ATS SSO application, not on wsmoodle.asmx, so no wrappers for it
 * are provided here; add them against that endpoint if an SSO path is built.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imis_client implements \local_imisbridge\provider\org_sync_provider {
    /** @var \SoapClient */
    private $soap;

    /** @var string Cached ATS API AuthToken (MO-xxxxxx). */
    private $apiauthtoken;

    /**
     * A short human-readable name for this provider.
     *
     * @return string
     */
    public static function label(): string {
        return get_string('transport_soap', 'local_imisbridge');
    }

    /**
     * Whether the SOAP bridge has the minimum configuration to connect (a WSDL URL).
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return !empty(get_config('local_imisbridge', 'wsdl_url'));
    }

    /**
     * The ATS SOAP bridge syncs with the same configuration it pushes with.
     *
     * @return bool
     */
    public static function is_sync_configured(): bool {
        return self::is_configured();
    }

    /**
     * The ATS SOAP bridge both pushes records and offers inbound order/group sync.
     *
     * @param string $capability One of the provider CAP_* constants.
     * @return bool
     */
    public static function supports(string $capability): bool {
        return in_array($capability, [
            \local_imisbridge\provider\provider_interface::CAP_PUSH,
            \local_imisbridge\provider\provider_interface::CAP_ORG_SYNC,
        ], true);
    }

    /**
     * Push a course completion record via MoodleUpdate.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_completion(\stdClass $data): void {
        $this->push_activity($data);
    }

    /**
     * Push an assessment score as an append-only MOODLENOTE (createActivity).
     *
     * A score must NEVER be a MoodleUpdate: that owns the canonical completion
     * record, and a quiz re-attempt after completion would otherwise overwrite
     * the course's COMPLETE status and final grade with a per-attempt PASS/FAIL.
     * Only a course-completion event may write the completion record - see the
     * invariant in docs/DIRECT-REST-ARCHITECTURE.md. This mirrors the REST
     * transport (rest_client::push_score) and the vendor's own behaviour.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_score(\stdClass $data): void {
        $courseid = (string)($data->coursenum ?? '');
        $this->create_activity(self::build_note_params(
            (string)$data->imisid,
            $courseid,
            'Moodle course ' . $courseid . ' score',
            (string)($data->status ?? ''),
            (float)($data->score ?? 0),
            (string)($data->completiondate ?? date('Y-m-d')),
            ['Note2' => 'score']
        ));
    }

    /**
     * Send the course-completion record to iMIS via MoodleUpdate.
     *
     * Used by push_completion() only. Scores and module notes are append-only
     * MOODLENOTE records and must not route here (see push_score()).
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    private function push_activity(\stdClass $data): void {
        $this->update_activity_record(
            imisid:          (string)$data->imisid,
            moodlecoursenum: (string)($data->coursenum ?? ''),
            credittype:      (string)($data->credittype ?? 'CEU'),
            creditvalue:     (float)($data->creditvalue ?? 0),
            startdate:       (string)($data->startdate ?? ''),
            completiondate:  (string)($data->completiondate ?? ''),
            grantdate:       (string)($data->grantdate ?? ''),
            status:          (string)($data->status ?? ''),
            score:           (float)($data->score ?? 0)
        );
    }

    /**
     * Push a supplementary module note via createActivity (MOODLENOTE): a module
     * completion (`Note2 = modulecompletion`), or an activity score when `notekind`
     * is 'score' (`Note2 = score`, "Score: <activity>").
     *
     * Field names mirror the vendor's proven call against the same ATS service;
     * confirm against the live WSDL if a field is rejected.
     *
     * @param \stdClass $data The queued push data.
     * @return void
     */
    public function push_module_note(\stdClass $data): void {
        $cmname = (string)($data->cmname ?? '');
        $isscore = (($data->notekind ?? '') === 'score');
        $this->create_activity(self::build_note_params(
            (string)$data->imisid,
            (string)($data->coursenum ?? ''),
            ($isscore ? 'Score: ' : 'Module completion: ') . $cmname,
            (string)($data->status ?? ''),
            (float)($data->score ?? 0),
            (string)($data->date ?? date('Y-m-d')),
            [
                'UserField5' => (int)($data->cmid ?? 0),
                'Note2'      => $isscore ? 'score' : 'modulecompletion',
                'SourceCode' => (string)($data->modname ?? ''),
                'Description' => $cmname,
            ]
        ));
    }

    /**
     * Build an append-only MOODLENOTE activity record (createActivity payload).
     *
     * Kept pure and static so the append-only contract is unit-testable: these
     * records carry their own status/score in UserField3/UserField4 and never
     * the completion discriminator, so they can never overwrite the canonical
     * completion record (see the invariant in docs/DIRECT-REST-ARCHITECTURE.md).
     *
     * @param string $imisid   iMIS contact ID.
     * @param string $coursenum Moodle course number (maps to iMIS product code).
     * @param string $note     The note text.
     * @param string $status   Record status (upper-cased to the ATS vocabulary).
     * @param float  $score    The score / grade.
     * @param string $date     Transaction (ThruDate) in Y-m-d.
     * @param array  $extra     Extra fields (e.g. UserField5, Note2) merged in.
     * @return array The createActivity parameters (without the AuthToken).
     */
    public static function build_note_params(
        string $imisid,
        string $coursenum,
        string $note,
        string $status,
        float $score,
        string $date,
        array $extra = []
    ): array {
        return array_merge([
            'ContactID'    => $imisid,
            'ActivityType' => 'MOODLENOTE',
            'Note'         => $note,
            'ProductCode'  => $coursenum,
            'UserField3'   => strtoupper($status),
            'UserField4'   => $score,
            'ThruDate'     => $date,
        ], $extra);
    }

    /**
     * Run read-only connection diagnostics (endpoint, GetBridgeSettings, optional profile).
     *
     * @param string|null $contactid Optional member ID to exercise an authenticated lookup.
     * @return string The diagnostic report.
     */
    public function diagnostics(?string $contactid): string {
        // Show where requests will actually be sent (override vs WSDL address) so a
        // WSDL that advertises the wrong environment is caught before writing.
        $override = self::get_effective_endpoint();
        if ($override !== null) {
            $endpointline = get_string('endpoint_override_active', 'local_imisbridge', $override);
        } else {
            $wsdladdr     = self::read_wsdl_endpoint();
            $endpointline = ($wsdladdr !== null)
                ? get_string('endpoint_from_wsdl', 'local_imisbridge', $wsdladdr)
                : get_string('endpoint_from_wsdl_unknown', 'local_imisbridge');
        }
        $out  = $endpointline . PHP_EOL . PHP_EOL;
        $out .= 'GetBridgeSettings:' . PHP_EOL . var_export($this->get_bridge_settings(), true);
        if (!empty($contactid)) {
            $out .= PHP_EOL . PHP_EOL . 'MoodleGetUserProfile(' . $contactid . '):' . PHP_EOL
                . var_export($this->get_contact_by_id($contactid), true);
            $out .= PHP_EOL . PHP_EOL . get_string('testcredentialsok', 'local_imisbridge');
        } else {
            $out .= PHP_EOL . PHP_EOL . get_string('testcredentialsnote', 'local_imisbridge');
        }
        return $out;
    }

    /**
     * Constructor — initialises the SOAP client from plugin settings.
     *
     * @throws \moodle_exception If no WSDL URL is configured.
     */
    public function __construct() {
        $wsdl = get_config('local_imisbridge', 'wsdl_url');

        if (empty($wsdl)) {
            throw new \moodle_exception('nowsdlconfigured', 'local_imisbridge');
        }

        // Bound how long a request may block on iMIS so a slow or down endpoint
        // cannot hang a cron worker. Applies to both connect and read.
        $timeout = (int)get_config('local_imisbridge', 'ws_timeout');
        if ($timeout <= 0) {
            $timeout = 30;
        }

        // Optional endpoint override. When set, requests are sent here instead of
        // the service address embedded in the WSDL. This guards against a WSDL
        // that advertises a different environment than intended (for example a
        // test WSDL whose soap:address points at production).
        $endpoint = get_config('local_imisbridge', 'service_endpoint');

        $this->soap = new \SoapClient($wsdl, self::build_soap_options($timeout, $endpoint ?: null));
    }

    /**
     * Build the SoapClient options array.
     *
     * Extracted so the endpoint-override behaviour can be unit tested without a
     * live WSDL. When $endpoint is a non-empty string it is passed as the SOAP
     * `location`, which overrides the address embedded in the WSDL for every call.
     *
     * @param int         $timeout  Connection/read timeout in seconds.
     * @param string|null $endpoint Optional service endpoint URL override.
     * @return array The options array for the SoapClient constructor.
     */
    public static function build_soap_options(int $timeout, ?string $endpoint): array {
        $options = [
            'trace'              => true,
            'exceptions'         => true,
            'cache_wsdl'         => WSDL_CACHE_DISK,
            'soap_version'       => SOAP_1_1,
            'connection_timeout' => $timeout,
            'stream_context'     => stream_context_create([
                'http' => ['timeout' => $timeout],
            ]),
        ];
        if (!empty($endpoint)) {
            $options['location'] = $endpoint;
        }
        return $options;
    }

    /**
     * The endpoint override that requests are sent to, if one is configured.
     *
     * Returns the configured service endpoint override, or null when calls use
     * the address embedded in the WSDL. Surfaced in the connection test so an
     * operator can confirm where writes will actually go.
     *
     * @return string|null The endpoint override, or null if not set.
     */
    public static function get_effective_endpoint(): ?string {
        $endpoint = get_config('local_imisbridge', 'service_endpoint');
        return !empty($endpoint) ? $endpoint : null;
    }

    /**
     * Best-effort: the service address embedded in the configured WSDL.
     *
     * SoapClient sends requests to the `<soap:address location>` in the WSDL, not
     * to the host the WSDL was fetched from, so a staging WSDL can embed a
     * production address. When no override is set this fetches the WSDL and
     * returns that address, so the connection test can show the real destination.
     * Returns null if the WSDL is not set or cannot be read/parsed (the caller
     * then shows a warning instead).
     *
     * @return string|null The embedded service address, or null.
     */
    public static function read_wsdl_endpoint(): ?string {
        global $CFG;
        $wsdl = get_config('local_imisbridge', 'wsdl_url');
        if (empty($wsdl)) {
            return null;
        }
        $timeout = (int)get_config('local_imisbridge', 'ws_timeout');
        if ($timeout <= 0) {
            $timeout = 30;
        }
        try {
            require_once($CFG->libdir . '/filelib.php');
            $xml = download_file_content($wsdl, null, null, false, $timeout, $timeout);
            if (!is_string($xml) || $xml === '') {
                return null;
            }
            return self::extract_wsdl_endpoint($xml);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract the first SOAP service address from a WSDL document.
     *
     * Matches the `location` of the first `soap:address` / `soap12:address`
     * element. Extracted from the fetch so it can be unit tested.
     *
     * @param string $wsdlxml The WSDL XML.
     * @return string|null The location URL, or null if none is found.
     */
    public static function extract_wsdl_endpoint(string $wsdlxml): ?string {
        if (preg_match('/<(?:[a-z0-9]+:)?address\b[^>]*\blocation\s*=\s*"([^"]+)"/i', $wsdlxml, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // AUTH.

    /**
     * Returns the ATS API AuthToken (MO-xxxxxx) from plugin settings.
     *
     * This token is required by ATS from 2025 onward on methods:
     * MoodleGetUserProfile, MoodleUpdate, createActivity,
     * getActivityByIDAndType, and getIQARows.
     *
     * @return string The API AuthToken.
     * @throws \moodle_exception If no AuthToken is configured.
     */
    private function get_api_auth_token(): string {
        if (empty($this->apiauthtoken)) {
            $token = get_config('local_imisbridge', 'auth_token');
            if (empty($token)) {
                throw new \moodle_exception('noauthtoken', 'local_imisbridge');
            }
            $this->apiauthtoken = $token;
        }
        return $this->apiauthtoken;
    }

    /**
     * Perform a SOAP call and record it to the audit log.
     *
     * Wraps the raw SOAP call so every request/response is logged exactly once,
     * whether it succeeds or faults. Behaves identically to calling the operation
     * on the SOAP client directly.
     *
     * @param string      $operation   The SOAP operation name.
     * @param array       $params      The single parameter structure for the operation.
     * @param string|null $imisid      The iMIS contact ID for the log, if any.
     * @param string|null $productcode The iMIS product code for the log, if any.
     * @return mixed The raw SOAP result object.
     */
    private function call(string $operation, array $params, ?string $imisid = null, ?string $productcode = null): mixed {
        try {
            $result = $this->soap->__soapCall($operation, [$params]);
        } catch (\Throwable $e) {
            logger::record($operation, $imisid, $productcode, logger::STATUS_ERROR, $this->soap, $e->getMessage());
            throw $e;
        }

        // The SOAP call returned, but ATS can report an application-level failure in
        // a valid response (a populated ResultsBase Exception, or a non-success
        // ResultCode). Surface it as an error and throw, so a rejected write is not
        // logged as success and the adhoc task retries with backoff.
        $apperror = self::result_error($result);
        if ($apperror !== null) {
            logger::record($operation, $imisid, $productcode, logger::STATUS_ERROR, $this->soap, $apperror);
            throw new \moodle_exception('soapresulterror', 'local_imisbridge', '', $operation . ': ' . $apperror);
        }

        logger::record($operation, $imisid, $productcode, logger::STATUS_SUCCESS, $this->soap, null);
        return $result;
    }

    /**
     * Inspect a SOAP result for an application-level failure, independent of transport.
     *
     * Operations that return a ResultsBase (e.g. MoodleUpdate, UpdateMoodleGroups)
     * can report a failure in a valid SOAP response. A populated `Exception` is an
     * unambiguous failure. A `ResultCode` is judged only when success codes are
     * configured (see {@see success_result_codes()}), so the default behaviour
     * changes nothing unless a code explicitly carries an exception.
     *
     * @param mixed $result The raw SOAP result object.
     * @return string|null An error message when the result indicates failure, else null.
     */
    public static function result_error($result): ?string {
        $rb = self::results_base($result);
        if ($rb === null) {
            return null;
        }

        $exception = self::exception_message($rb);
        if ($exception !== '') {
            return $exception;
        }

        $successcodes = self::success_result_codes();
        if (!empty($successcodes) && isset($rb->ResultCode) && is_scalar($rb->ResultCode)) {
            $code = trim((string)$rb->ResultCode);
            if (!in_array($code, $successcodes, true)) {
                $message = (isset($rb->ResultMessage) && is_scalar($rb->ResultMessage))
                    ? trim((string)$rb->ResultMessage) : '';
                return 'ResultCode ' . $code . ($message !== '' ? ': ' . $message : '');
            }
        }

        return null;
    }

    /**
     * Extract a failure message from a ResultsBase Exception, if one is populated.
     *
     * ATS returns Exception as an object ({Message, Source, StackTrace, …}) that is
     * present but all-empty on success, so only a non-empty `Exception->Message`
     * (or a non-empty scalar Exception) counts as a failure. An empty exception
     * object is a success.
     *
     * @param object $rb The ResultsBase (or equivalent status object).
     * @return string The exception message, or '' when there is no failure.
     */
    public static function exception_message(object $rb): string {
        if (!isset($rb->Exception)) {
            return '';
        }
        $exception = $rb->Exception;
        if (is_scalar($exception)) {
            return trim((string)$exception);
        }
        if (is_object($exception) && isset($exception->Message) && is_scalar($exception->Message)) {
            return trim((string)$exception->Message);
        }
        return '';
    }

    /**
     * Locate the ResultsBase (or an equivalent status object) within a SOAP result.
     *
     * @param mixed $result The raw SOAP result object.
     * @return object|null The status object carrying Exception/ResultCode, or null.
     */
    public static function results_base($result): ?object {
        if (!is_object($result)) {
            return null;
        }
        // Some operations return the ResultsBase at the top level (e.g. UpdateMoodleGroups).
        if (isset($result->ResultsBase) && is_object($result->ResultsBase)) {
            return $result->ResultsBase;
        }
        // Otherwise the status sits on the operation's *Result wrapper, either as a
        // nested ResultsBase or as ResultCode/Exception fields on the wrapper itself.
        // This covers SendNewOrdersToMoodle, SendCancelledOrdersToMoodle, createActivity
        // and similar, whose wrappers carry the status directly rather than in a
        // ResultsBase property. A plain data wrapper (e.g. MoodleGetUserProfileResult)
        // has neither field and is skipped, so a read with no status stays a success.
        foreach (get_object_vars($result) as $value) {
            if (!is_object($value)) {
                continue;
            }
            if (isset($value->ResultsBase) && is_object($value->ResultsBase)) {
                return $value->ResultsBase;
            }
            if (isset($value->Exception) || isset($value->ResultCode)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * The ResultCodes treated as success, from plugin configuration.
     *
     * Blank (the default) means the ResultCode is not judged — only a populated
     * Exception marks a failure. Configure the real ATS success code(s) here to
     * also treat an unexpected ResultCode as a failure.
     *
     * @return string[] The configured success codes (trimmed, empty when unset).
     */
    public static function success_result_codes(): array {
        $raw = (string)get_config('local_imisbridge', 'soap_success_result_codes');
        if (trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($code) {
            return $code !== '';
        }));
    }

    // CONTACT LOOKUP.

    /**
     * Return iMIS contact info by iMIS ID (MoodleGetUserProfile).
     *
     * Requires AuthToken as of 2025 ATS security update.
     *
     * @param string $imisid The iMIS contact ID.
     * @return object|null The contact record, or null if not found.
     */
    public function get_contact_by_id(string $imisid): ?object {
        $result = $this->call('MoodleGetUserProfile', [
            'ID'        => $imisid,
            'AuthToken' => $this->get_api_auth_token(),
        ], $imisid);
        return $result->MoodleGetUserProfileResult ?? null;
    }

    /**
     * Return iMIS contact info by session token (MoodleGetUserProfileByToken).
     *
     * Per the wsmoodle.asmx contract this operation takes a single lowercase
     * 'token' parameter and no AuthToken. The token is one created by asiEncrypt
     * or getToken (a REST client must use a getToken-encrypted token).
     *
     * @param string $token The SSO session token.
     * @return object|null The contact record, or null if not found.
     */
    public function get_contact_by_token(string $token): ?object {
        $result = $this->call('MoodleGetUserProfileByToken', [
            'token' => $token,
        ]);
        return $result->MoodleGetUserProfileByTokenResult ?? null;
    }

    // ENROLLMENT SYNC.

    /**
     * Find orders missing activity records and create them, then notify Moodle.
     *
     * Pass null $contactid to sync ALL users.
     *
     * @param string|null $contactid iMIS contact ID, or null for all users.
     * @return mixed The SOAP result.
     */
    public function sync_orders(?string $contactid = null): mixed {
        $params = [
            'AuthToken' => $this->get_api_auth_token(),
            'contactId' => $contactid ?? '',
        ];
        $result = $this->call('SendNewOrdersToMoodle', $params, $contactid);
        return $result->SendNewOrdersToMoodleResult ?? null;
    }

    /**
     * Find cancelled orders and send unsubscribe notice to Moodle.
     *
     * Pass null $contactid to sync ALL users.
     *
     * @param string|null $contactid iMIS contact ID, or null for all users.
     * @return mixed The SOAP result.
     */
    public function sync_cancelled_orders(?string $contactid = null): mixed {
        $params = [
            'AuthToken' => $this->get_api_auth_token(),
            'contactId' => $contactid ?? '',
        ];
        $result = $this->call('SendCancelledOrdersToMoodle', $params, $contactid);
        return $result->SendCancelledOrdersToMoodleResult ?? null;
    }

    // GROUP SYNC.

    /**
     * Update Moodle groups based on iMIS group membership.
     *
     * @param string|null $contactid   iMIS contact ID (optional).
     * @param string|null $courseid    Moodle course ID (optional).
     * @param string|null $lastupdated UTC datetime string e.g. '2024-03-04T00:00:00' (optional).
     * @return mixed The SOAP result.
     */
    public function update_groups(
        ?string $contactid = null,
        ?string $courseid = null,
        ?string $lastupdated = null
    ): mixed {
        $params = [
            'AuthToken'   => $this->get_api_auth_token(),
            'ContactID'   => $contactid ?? '',
            'CourseId'    => $courseid ?? '',
            'LastUpdated' => $lastupdated ?? '',
        ];
        $result = $this->call('UpdateMoodleGroups', $params, $contactid);
        // The response body is a ResultsBase (ResultCode/ResultMessage/Exception),
        // not an UpdateMoodleGroupsResult wrapper.
        return $result->ResultsBase ?? null;
    }

    // ACTIVITY RECORDS.

    /**
     * Retrieve activity records for a contact and activity type (getActivityByIDAndType).
     *
     * Requires AuthToken as of 2025 ATS security update.
     *
     * @param string $contactid    The iMIS contact ID.
     * @param string $activitytype The activity type to filter by.
     * @param string $productcode  Optional product code filter.
     * @return mixed The SOAP result.
     */
    public function get_activities(string $contactid, string $activitytype, string $productcode = ''): mixed {
        $params = [
            'ContactID'    => $contactid,
            'ActivityType' => $activitytype,
            'ProductCode'  => $productcode,
            'AuthToken'    => $this->get_api_auth_token(),
        ];
        $result = $this->call('getActivityByIDAndType', $params, $contactid, $productcode);
        return $result->getActivityByIDAndTypeResult ?? null;
    }

    /**
     * Create a new activity record in iMIS (createActivity).
     *
     * Requires AuthToken as of 2025 ATS security update.
     *
     * @param array $data The activity data (AuthToken is injected automatically).
     * @return mixed The SOAP result.
     */
    public function create_activity(array $data): mixed {
        $data['AuthToken'] = $this->get_api_auth_token();
        // Match the vendor's proven call exactly: the WSDL operation is PascalCase
        // 'CreateActivity' (SOAP operation names are case-sensitive, so the old
        // lowercase 'createActivity' faulted and no note ever landed), and empty
        // fields are dropped so the service does not reject stray empty elements.
        $data = array_filter($data, static fn($value) => $value !== '' && $value !== null);
        $result = $this->call('CreateActivity', $data, $data['ContactID'] ?? null, $data['ProductCode'] ?? null);
        return $result->createActivityResult ?? null;
    }

    /**
     * Update an iMIS activity record with Moodle course completion data (MoodleUpdate).
     *
     * Requires AuthToken as of 2025 ATS security update.
     *
     * The MoodleUpdate `type` field is the record discriminator, not a credit
     * type: the ATS service only writes the record onto the registration when it
     * is the literal 'Completion' (the vendor bridge always sent this), and it
     * leaves `value` empty. Sending anything else returns ResultCode 0 but writes
     * nothing. Status is one of the ATS vocabulary - COMPLETE, INPROGRESS,
     * NOTSTARTED, PASS, FAIL - so it is upper-cased here.
     *
     * `$credittype` and `$creditvalue` are kept in the signature for backward
     * compatibility (this is a documented public API surface) but are no longer
     * transmitted: the ATS MoodleUpdate contract carries no credit type or value
     * - iMIS derives the credit from the product. See build_moodleupdate_params().
     *
     * @param string $imisid          iMIS contact ID (from the configured Moodle user field).
     * @param string $moodlecoursenum Moodle course number (maps to iMIS product code).
     * @param string $credittype      Retained for backward compatibility; not transmitted.
     * @param float  $creditvalue     Retained for backward compatibility; not transmitted.
     * @param string $startdate       Course start date (Y-m-d).
     * @param string $completiondate  Course completion date (Y-m-d).
     * @param string $grantdate       Date credit hours were granted (Y-m-d).
     * @param string $status          Record status (COMPLETE, INPROGRESS, PASS, FAIL, ...).
     * @param float  $score           Last test score.
     * @return mixed The SOAP result.
     */
    public function update_activity_record(
        string $imisid,
        string $moodlecoursenum,
        string $credittype,
        float $creditvalue,
        string $startdate,
        string $completiondate,
        string $grantdate,
        string $status,
        float $score
    ): mixed {
        unset($credittype, $creditvalue);
        $params = array_merge(
            ['AuthToken' => $this->get_api_auth_token()],
            self::build_moodleupdate_params(
                $imisid,
                $moodlecoursenum,
                $startdate,
                $completiondate,
                $grantdate,
                $status,
                $score
            )
        );
        $result = $this->call('MoodleUpdate', $params, $imisid, $moodlecoursenum);
        // The response body is a ResultsBase (ResultCode/ResultMessage/Exception),
        // not a MoodleUpdateResult wrapper.
        return $result->ResultsBase ?? null;
    }

    /**
     * Build the MoodleUpdate SOAP parameters (without the AuthToken).
     *
     * Kept pure and static so the record contract is unit-testable: `type` is the
     * fixed 'Completion' discriminator and `value` is empty, because the ATS
     * service only writes the completion onto the registration for that exact
     * shape (anything else returns ResultCode 0 but lands nothing). Status is
     * upper-cased to the ATS vocabulary (COMPLETE, INPROGRESS, PASS, FAIL, ...).
     *
     * @param string $imisid          iMIS contact ID.
     * @param string $moodlecoursenum Moodle course number (maps to iMIS product code).
     * @param string $startdate       Course start date (Y-m-d).
     * @param string $completiondate  Course completion date (Y-m-d).
     * @param string $grantdate       Grant date (Y-m-d).
     * @param string $status          Record status (COMPLETE, INPROGRESS, PASS, FAIL, ...).
     * @param float  $score           Last test score.
     * @return array The MoodleUpdate parameters.
     */
    public static function build_moodleupdate_params(
        string $imisid,
        string $moodlecoursenum,
        string $startdate,
        string $completiondate,
        string $grantdate,
        string $status,
        float $score
    ): array {
        return [
            'userId'         => $imisid,
            'productId'      => $moodlecoursenum,
            'type'           => 'Completion',
            'value'          => '',
            'startDate'      => $startdate,
            'completionDate' => $completiondate,
            'grantDate'      => $grantdate,
            'status'         => strtoupper($status),
            'score'          => $score,
        ];
    }

    // BRIDGE SETTINGS.

    /**
     * Return the Moodle bridge settings as configured in iMIS.
     *
     * The service contract lists an AuthToken parameter, so it is sent when one
     * is configured; the call is still made without it so a connectivity check
     * can run before the token has been entered.
     *
     * @return mixed The SOAP result.
     */
    public function get_bridge_settings(): mixed {
        // Operation name is case-sensitive: the service advertises GetBridgeSettings.
        $params = [];
        $token  = get_config('local_imisbridge', 'auth_token');
        if (!empty($token)) {
            $params['AuthToken'] = $token;
        }
        $result = $this->call('GetBridgeSettings', $params);
        return $result->GetBridgeSettingsResult ?? null;
    }

    /**
     * Run the ATS bridge setup/verification routine (SetupBridge).
     *
     * Returns the log lines the service emits describing what it checked or set.
     *
     * @return string[] The log lines returned by the service.
     */
    public function setup_bridge(): array {
        $result = $this->call('SetupBridge', [
            'AuthToken' => $this->get_api_auth_token(),
        ]);
        $log = $result->SetupBridgeResult->Log->string ?? [];
        // A single <string> element deserialises to a scalar; normalise to a list.
        if (is_string($log)) {
            $log = [$log];
        }
        return is_array($log) ? array_values($log) : [];
    }

    // IQA.

    /**
     * Get rows from an iMIS IQA query (getIQARows).
     *
     * Per the WSDL this operation takes a single jsonParams object
     * (AuthToken, QueryPath, QueryFilters, PageSize, PageOffset). The service
     * returns its rows as JSON and the WSDL declares an empty SOAP response body,
     * so the raw result is returned for the caller to parse; verify the exact
     * shape against the live endpoint before relying on it. Not used by the
     * plugin's own observers or tasks.
     *
     * @param string $querypath  The IQA query path.
     * @param array  $filters    Key => value filters applied to the query.
     * @param int    $pagesize   Rows per page.
     * @param int    $pageoffset Zero-based page number.
     * @return mixed The SOAP result.
     */
    public function get_iqa_rows(string $querypath, array $filters = [], int $pagesize = 100, int $pageoffset = 0): mixed {
        $queryfilters = [];
        foreach ($filters as $key => $value) {
            $queryfilters[] = ['Key' => (string)$key, 'Value' => (string)$value];
        }
        $jsonparams = [
            'AuthToken'    => $this->get_api_auth_token(),
            'QueryPath'    => $querypath,
            'QueryFilters' => ['QueryFilter' => $queryfilters],
            'PageSize'     => $pagesize,
            'PageOffset'   => $pageoffset,
        ];
        return $this->call('getIQARows', ['jsonParams' => $jsonparams]);
    }
}
