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
 * Audit logger for local_membershipbridge.
 *
 * Records each SOAP call (operation, scope, status and the full request/response
 * XML with the AuthToken redacted) to the local_membershipbridge_log table. Logging is
 * best-effort: a logging failure never interrupts the sync it is recording.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Writes and prunes Membership Bridge audit-log records.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    /** @var string Status recorded for a successful call. */
    public const STATUS_SUCCESS = 'success';

    /** @var string Status recorded for a failed call. */
    public const STATUS_ERROR = 'error';

    /**
     * Record one SOAP call to the audit log.
     *
     * @param string           $operation   The SOAP operation name.
     * @param string|null      $imisid      The iMIS contact ID sent, if any.
     * @param string|null      $productcode The iMIS product code sent, if any.
     * @param string           $status      self::STATUS_SUCCESS or self::STATUS_ERROR.
     * @param \SoapClient|null  $soap        The traced SOAP client, to read the last request/response.
     * @param string|null      $error       The fault or exception message, when the call failed.
     * @return void
     */
    public static function record(
        string $operation,
        ?string $imisid,
        ?string $productcode,
        string $status,
        ?\SoapClient $soap = null,
        ?string $error = null
    ): void {
        // Read the traced request/response only when capture is on; the generic
        // recorder redacts and stores them.
        $request  = ($soap && self::is_capturing()) ? (string)$soap->__getLastRequest() : null;
        $response = ($soap && self::is_capturing()) ? (string)$soap->__getLastResponse() : null;
        self::record_call($operation, $imisid, $productcode, $status, $error, $request, $response);
    }

    /**
     * Record one call to the audit log, independent of transport (SOAP or REST).
     *
     * The request/response payloads are stored only when payload capture is
     * enabled, and are always redacted. A failure here never interrupts the sync.
     *
     * @param string      $operation   The operation name (SOAP op or "METHOD /path").
     * @param string|null $imisid      The iMIS contact ID sent, if any.
     * @param string|null $productcode The iMIS product code sent, if any.
     * @param string      $status      self::STATUS_SUCCESS or self::STATUS_ERROR.
     * @param string|null $error       The fault/exception/HTTP error, when the call failed.
     * @param string|null $request     The raw request payload, if available.
     * @param string|null $response    The raw response payload, if available.
     * @return void
     */
    public static function record_call(
        string $operation,
        ?string $imisid,
        ?string $productcode,
        string $status,
        ?string $error = null,
        ?string $request = null,
        ?string $response = null
    ): void {
        global $DB;

        // Logging is best-effort: never let it break the sync it is recording.
        try {
            if (!self::is_enabled()) {
                return;
            }

            $record = new \stdClass();
            $record->userid      = self::resolve_userid($imisid);
            // The operation column is char(255); a long REST operation (e.g. an IQA
            // GET URL with encoded prompts) can exceed that, so truncate defensively
            // rather than let the insert throw.
            $record->operation   = \core_text::substr((string)$operation, 0, 255);
            $record->imisid      = ($imisid === null || $imisid === '') ? null : $imisid;
            $record->productcode = ($productcode === null || $productcode === '') ? null : $productcode;
            $record->status      = $status;
            // Full request/response are only stored when payload capture is enabled,
            // and always with credentials redacted.
            if ($request !== null && self::is_capturing()) {
                $record->request = self::redact($request);
            } else {
                $record->request = null;
            }
            if ($response !== null && self::is_capturing()) {
                $record->response = self::redact($response);
            } else {
                $record->response = null;
            }
            // A fault can echo a rejected credential, so redact it too.
            $record->error       = ($error === null) ? null : self::redact($error);
            $record->timecreated = time();

            $DB->insert_record('local_membershipbridge_log', $record);
        } catch (\Throwable $e) {
            debugging('local_membershipbridge: failed to write audit log: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Whether audit logging is enabled. Unset is treated as enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return util::is_enabled('log_enabled');
    }

    /**
     * Whether full SOAP request/response payloads should be stored.
     *
     * Off by default so personal data is not kept at rest in normal operation;
     * turn it on temporarily to capture the wire format for debugging.
     *
     * @return bool
     */
    public static function is_capturing(): bool {
        return (bool)get_config('local_membershipbridge', 'capture_payloads');
    }

    /**
     * Redact credentials from SOAP XML before storing it.
     *
     * Strips the AuthToken value, the getToken session-token result, and any
     * ATS token of the form MO-xxxxxx, in both requests and responses.
     *
     * @param string $xml The raw SOAP XML.
     * @return string The XML with credentials replaced.
     */
    public static function redact(string $xml): string {
        $elements = [
            '#(<(?:\w+:)?AuthToken>).*?(</(?:\w+:)?AuthToken>)#is',
            '#(<(?:\w+:)?Token>).*?(</(?:\w+:)?Token>)#is',
            '#(<(?:\w+:)?getTokenResult>).*?(</(?:\w+:)?getTokenResult>)#is',
        ];
        foreach ($elements as $pattern) {
            $xml = (string)preg_replace($pattern, '${1}[redacted]${2}', $xml);
        }

        // REST credentials: bearer tokens, OAuth token JSON, and form-encoded or
        // JSON password/grant fields.
        $xml = (string)preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '${1}[redacted]', $xml);
        $xml = (string)preg_replace('/("access_token"\s*:\s*")[^"]*(")/i', '${1}[redacted]${2}', $xml);
        $xml = (string)preg_replace('/("password"\s*:\s*")[^"]*(")/i', '${1}[redacted]${2}', $xml);
        $xml = (string)preg_replace('/(\bpassword=)[^&\s]*/i', '${1}[redacted]', $xml);

        return (string)preg_replace('/MO-[A-Za-z0-9]+/', 'MO-[redacted]', $xml);
    }

    /**
     * Resolve the Moodle user id for a logged iMIS ID using the configured source field.
     *
     * Returns 0 when the id is empty, matches no user, or matches more than one
     * (for example a non-unique email), so a row is never attributed to the wrong
     * user for privacy export or erasure.
     *
     * @param string|null $imisid The iMIS contact ID.
     * @return int The Moodle user id, or 0 when it cannot be resolved unambiguously.
     */
    private static function resolve_userid(?string $imisid): int {
        global $DB;

        if ($imisid === null || $imisid === '') {
            return 0;
        }

        $field = util::imis_id_source();
        $ids   = $DB->get_fieldset_select('user', 'id', $field . ' = ?', [$imisid]);

        return (count($ids) === 1) ? (int)reset($ids) : 0;
    }

    /**
     * Delete log rows older than the given number of days.
     *
     * @param int $days Age threshold in days; 0 or less deletes nothing.
     * @return int The number of rows deleted.
     */
    public static function purge(int $days): int {
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = time() - ($days * DAYSECS);
        $count  = $DB->count_records_select('local_membershipbridge_log', 'timecreated < ?', [$cutoff]);
        $DB->delete_records_select('local_membershipbridge_log', 'timecreated < ?', [$cutoff]);

        return $count;
    }
}
