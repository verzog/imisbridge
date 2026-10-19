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
 * and tasks today, and the rest are provided for SSO, reporting and other
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
 * Confirmed ABSENT from the wsmoodle.asmx WSDL — these will fault against this
 * service: get_token / get_service_token (getToken), check_authorization
 * (checkAuthorization), encrypt / decrypt (asiEncrypt / asiDecrypt). ATS docs
 * imply the token flow lives on the REST bridge, not this SOAP endpoint; these
 * methods are retained for that path only.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imis_client {
    /** @var \SoapClient */
    private $soap;

    /** @var string Cached iMIS session token (for SSO, not the API AuthToken). */
    private $token;

    /** @var string Cached ATS API AuthToken (MO-xxxxxx). */
    private $apiauthtoken;

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
        logger::record($operation, $imisid, $productcode, logger::STATUS_SUCCESS, $this->soap, null);
        return $result;
    }

    /**
     * Generate an iMIS session token for the given iMIS ID.
     *
     * @param string $imisid The iMIS contact ID.
     * @return string The generated session token.
     */
    public function get_token(string $imisid): string {
        $result = $this->call('getToken', ['iMISID' => $imisid], $imisid);
        return $result->getTokenResult ?? '';
    }

    /**
     * Get or lazily create a service-level session token using the configured admin iMIS ID.
     *
     * @return string The service-level session token.
     * @throws \moodle_exception If no admin iMIS ID is configured.
     */
    public function get_service_token(): string {
        if (empty($this->token)) {
            $adminid = get_config('local_imisbridge', 'admin_imis_id');
            if (empty($adminid)) {
                throw new \moodle_exception('noadminimisid', 'local_imisbridge');
            }
            $this->token = $this->get_token($adminid);
        }
        return $this->token;
    }

    /**
     * Check if a given iMIS user is authorised to log in.
     *
     * @param string $imisid The iMIS contact ID.
     * @return bool True if the user is authorised.
     */
    public function check_authorization(string $imisid): bool {
        $result = $this->call('checkAuthorization', ['iMISID' => $imisid], $imisid);
        return (bool)($result->checkAuthorizationResult ?? false);
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
        $result = $this->call('createActivity', $data, $data['ContactID'] ?? null, $data['ProductCode'] ?? null);
        return $result->createActivityResult ?? null;
    }

    /**
     * Update an iMIS activity record with Moodle course completion data (MoodleUpdate).
     *
     * Requires AuthToken as of 2025 ATS security update.
     *
     * @param string $imisid          iMIS contact ID (from the configured Moodle user field).
     * @param string $moodlecoursenum Moodle course number (maps to iMIS product code).
     * @param string $credittype      Type of credit hour.
     * @param float  $creditvalue     Number of credit hours awarded.
     * @param string $startdate       Course start date (Y-m-d).
     * @param string $completiondate  Course completion date (Y-m-d).
     * @param string $grantdate       Date credit hours were granted (Y-m-d).
     * @param string $status          Pass or Fail.
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
        $params = [
            'AuthToken'      => $this->get_api_auth_token(),
            'userId'         => $imisid,
            'productId'      => $moodlecoursenum,
            'type'           => $credittype,
            'value'          => $creditvalue,
            'startDate'      => $startdate,
            'completionDate' => $completiondate,
            'grantDate'      => $grantdate,
            'status'         => $status,
            'score'          => $score,
        ];
        $result = $this->call('MoodleUpdate', $params, $imisid, $moodlecoursenum);
        // The response body is a ResultsBase (ResultCode/ResultMessage/Exception),
        // not a MoodleUpdateResult wrapper.
        return $result->ResultsBase ?? null;
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

    // ENCRYPTION HELPERS.

    /**
     * Encrypt a value via iMIS.
     *
     * @param string $value The value to encrypt.
     * @return string The encrypted value.
     */
    public function encrypt(string $value): string {
        // Not routed through the audit log: the request carries the plaintext value.
        $result = $this->soap->asiEncrypt(['value' => $value]);
        return $result->asiEncryptResult ?? '';
    }

    /**
     * Decrypt a value via iMIS.
     *
     * @param string $value The value to decrypt.
     * @return string The decrypted value.
     */
    public function decrypt(string $value): string {
        // Not routed through the audit log: the request/response carries plaintext.
        $result = $this->soap->asiDecrypt(['value' => $value]);
        return $result->asiDecryptResult ?? '';
    }
}
