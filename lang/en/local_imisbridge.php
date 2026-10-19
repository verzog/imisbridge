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
 * Language strings for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['allusers'] = 'all users';
$string['auditlog'] = 'iMIS audit log';
$string['auditlog_desc'] = 'Records each call made to iMIS so you can see what was sent and whether it was accepted.';
$string['autosynccontrols'] = 'Automatic sync controls';
$string['autosynccontrols_desc'] = 'Enable or disable each automatic write to iMIS. Manual syncs on the sync admin page are not affected. Turn writes off when testing against a production iMIS.';
$string['capture_payloads'] = 'Capture full request/response';
$string['capture_payloads_desc'] = 'When enabled, each log entry also stores the full SOAP request and response XML (with credentials redacted). This keeps personal data at rest, so enable it only while debugging and turn it off afterwards.';
$string['confirmsync'] = 'This will run the sync against iMIS for contact {$a}. Continue?';
$string['confirmsyncall'] = 'This will run the sync against iMIS for ALL users. It can create, cancel or modify records in the production iMIS. Continue?';
$string['contactid'] = 'iMIS contact ID';
$string['contactidhelp'] = 'Leave this blank to synchronise all users.';
$string['contactidplaceholder'] = 'e.g. 12345';
$string['credit_field'] = 'Credit value course field';
$string['credit_field_desc'] = 'The short name of the course custom field that holds the credit value awarded on completion. Leave blank to send a credit value of 0.';
$string['credit_type'] = 'Default credit type';
$string['credit_type_desc'] = 'The credit type recorded against iMIS activity records (for example, CEU).';
$string['endpoint_from_wsdl'] = 'Requests are sent to the service address embedded in the WSDL: {$a}. Verify this matches your intended environment (staging vs production) before running any write.';
$string['endpoint_from_wsdl_unknown'] = 'Requests are sent to the service address embedded in the WSDL (could not read it automatically - open the WSDL and check its <soap:address location>). Verify it matches your intended environment before running any write.';
$string['endpoint_override_active'] = 'Requests are sent to the ENDPOINT OVERRIDE: {$a} (not the address embedded in the WSDL).';
$string['filtercontactid'] = 'Optional: filter by iMIS contact ID';
$string['forcontact'] = 'contact {$a}';
$string['imis_api_auth_token'] = 'ATS API AuthToken';
$string['imis_api_auth_token_desc'] = 'The ATS-issued AuthToken (format: MO-xxxxxx) required on all secured API methods. Store this securely - do not share or commit to version control. Contact ATS if the token needs to be reissued.';
$string['imis_id_source'] = 'iMIS contact ID source';
$string['imis_id_source_desc'] = 'The Moodle user field that holds the iMIS contact ID sent to iMIS. Choose the field your identity provider populates with the iMIS ID. Defaults to username.';
$string['imis_id_source_email'] = 'Email address';
$string['imis_id_source_idnumber'] = 'ID number';
$string['imis_id_source_username'] = 'Username';
$string['imis_wsdl_url'] = 'iMIS WSDL URL';
$string['imis_wsdl_url_desc'] = 'The WSDL endpoint for the iMIS Moodle web service (for example, https://your-ats-host/wsmoodle.asmx?WSDL). Ask ATS for the correct URL for your environment. No sync or connection test runs until this is set.';
$string['imisbridge:readcompletions'] = 'Read course completions and grades through the iMIS bridge web service';
$string['last_sync'] = 'Last sync time';
$string['log_count'] = '{$a} log entries match.';
$string['log_detail'] = 'Detail';
$string['log_empty'] = 'No log entries match.';
$string['log_enabled'] = 'Enable audit logging';
$string['log_enabled_desc'] = 'Record each call made to iMIS in the audit log.';
$string['log_filter'] = 'Filter';
$string['log_filter_all'] = 'All';
$string['log_operation'] = 'Operation';
$string['log_product'] = 'Product';
$string['log_request'] = 'Request';
$string['log_response'] = 'Response';
$string['log_retention_days'] = 'Log retention (days)';
$string['log_retention_days_desc'] = 'Audit-log entries older than this many days are deleted by a daily task. Set to 0 to keep entries indefinitely.';
$string['log_status'] = 'Status';
$string['log_time'] = 'Time';
$string['log_user'] = 'User';
$string['log_viewpayload'] = 'View request/response';
$string['manualsynccontrols'] = 'Manual sync controls';
$string['noauthtoken'] = 'No ATS API AuthToken configured. Please add the AuthToken in plugin settings.';
$string['norestbaseurl'] = 'No iMIS REST base URL configured. Check plugin settings.';
$string['nowsdlconfigured'] = 'No iMIS WSDL URL configured. Check plugin settings.';
$string['openlog'] = 'Open iMIS audit log';
$string['opensyncadmin'] = 'Open sync admin';
$string['pluginname'] = 'iMIS Bridge';
$string['privacy:metadata:imis'] = 'The iMIS Bridge plugin transmits personal data to an external iMIS association management system in order to synchronise memberships, enrolments and learning records.';
$string['privacy:metadata:imis:completiondate'] = 'The dates a course was started, completed and credit granted are sent to iMIS.';
$string['privacy:metadata:imis:courseid'] = 'The identifier of the course an activity record relates to is sent to iMIS.';
$string['privacy:metadata:imis:score'] = 'Your assessment score is sent to iMIS.';
$string['privacy:metadata:imis:status'] = 'Your completion status (pass or fail) is sent to iMIS.';
$string['privacy:metadata:imis:userid'] = 'Your iMIS contact ID (taken from your Moodle username, ID number or email address, as configured) is sent to identify your record in iMIS.';
$string['privacy:metadata:local_imisbridge_log'] = 'A log of the calls the plugin made to iMIS, kept for auditing and troubleshooting.';
$string['privacy:metadata:local_imisbridge_log:error'] = 'Any fault message returned by iMIS for the call.';
$string['privacy:metadata:local_imisbridge_log:imisid'] = 'The iMIS contact ID sent in the call.';
$string['privacy:metadata:local_imisbridge_log:operation'] = 'The iMIS operation that was called.';
$string['privacy:metadata:local_imisbridge_log:productcode'] = 'The iMIS product code sent in the call.';
$string['privacy:metadata:local_imisbridge_log:request'] = 'The request sent to iMIS (stored only when payload capture is enabled; credentials are redacted).';
$string['privacy:metadata:local_imisbridge_log:response'] = 'The response received from iMIS (stored only when payload capture is enabled).';
$string['privacy:metadata:local_imisbridge_log:status'] = 'Whether the call to iMIS succeeded or failed.';
$string['privacy:metadata:local_imisbridge_log:timecreated'] = 'The time the call was made.';
$string['privacy:metadata:local_imisbridge_log:userid'] = 'The Moodle user the call related to.';
$string['push_completions'] = 'Push course completions to iMIS';
$string['push_completions_desc'] = 'When enabled, completing a course sends the learner\'s result to iMIS. Turn off to stop all completion writes.';
$string['push_quiz_scores'] = 'Push quiz scores to iMIS';
$string['push_quiz_scores_desc'] = 'When enabled, submitting a graded quiz sends the score to iMIS. Turn off to stop all quiz writes.';
$string['rest_base_url'] = 'iMIS REST base URL';
$string['rest_base_url_desc'] = 'The base URL of the iMIS instance for the direct REST transport (for example, https://your-org.imiscloud.com), with no trailing path. Used only when Transport is set to Direct iMIS REST.';
$string['rest_col_completiondate'] = 'iMIS column: completion date';
$string['rest_col_completiondate_desc'] = 'Optional. The raw iMIS activity column that should receive the completion date. Leave blank until confirmed against your iMIS - a blank name means the value is not sent.';
$string['rest_col_credittype'] = 'iMIS column: credit type';
$string['rest_col_credittype_desc'] = 'Optional. The raw iMIS activity column that should receive the credit type. Leave blank until confirmed against your iMIS - a blank name means the value is not sent.';
$string['rest_col_creditvalue'] = 'iMIS column: credit value';
$string['rest_col_creditvalue_desc'] = 'Optional. The raw iMIS activity column that should receive the credit value. Leave blank until confirmed against your iMIS - a blank name means the value is not sent.';
$string['rest_col_grantdate'] = 'iMIS column: grant date';
$string['rest_col_grantdate_desc'] = 'Optional. The raw iMIS activity column that should receive the credit-grant date. Leave blank until confirmed against your iMIS - a blank name means the value is not sent.';
$string['rest_password'] = 'iMIS service-account password';
$string['rest_password_desc'] = 'The password for the dedicated iMIS REST service account. Store securely - do not share or commit to version control. Use a least-privilege RemoteService account, never a personal or SysAdmin login.';
$string['rest_username'] = 'iMIS service-account username';
$string['rest_username_desc'] = 'The username of the dedicated iMIS REST service account used for the OAuth password grant.';
$string['restheading'] = 'Direct iMIS REST transport';
$string['restheading_desc'] = 'Settings for talking directly to the iMIS REST API, with no ATS middleware. These apply only when Transport is set to Direct iMIS REST. See docs/DIRECT-REST-ARCHITECTURE.md.';
$string['restlockfailed'] = 'Could not acquire the iMIS REST write lock for this contact and course; the task will retry.';
$string['restrequestfailed'] = 'iMIS REST request failed: {$a}.';
$string['resttokenfailed'] = 'iMIS REST authentication failed (HTTP {$a}). Check the base URL and service-account credentials.';
$string['schedule_cancellations'] = '02:15 UTC - cancellation sync (all users)';
$string['schedule_enrollments'] = '02:00 UTC - enrolment sync (all users)';
$string['schedule_groups'] = '02:30 UTC - group sync (incremental, based on last run)';
$string['scheduledtaskcontrols'] = 'Scheduled tasks';
$string['scheduledtaskcontrols_desc'] = 'Enable or disable each nightly all-user task. These can also be managed from Site administration > Server > Scheduled tasks.';
$string['scheduledtaskintro'] = 'Scheduled nightly tasks run automatically via Moodle cron:';
$string['scheduledtaskstatus'] = 'Scheduled task status';
$string['service_endpoint'] = 'Service endpoint URL (override)';
$string['service_endpoint_desc'] = 'Optional. When set, all SOAP requests are sent to this URL instead of the service address embedded in the WSDL. Use it when the WSDL advertises a different environment than you intend - for example a test WSDL whose address points at production. Enter the .asmx endpoint with no ?WSDL suffix (for example https://your-ats-host/wsmoodle.asmx). Leave blank to use the address from the WSDL. This controls where writes actually land, so set it to your staging endpoint before any write test.';
$string['servicename'] = 'iMIS bridge';
$string['status_error'] = 'Error';
$string['status_success'] = 'Success';
$string['sync_all'] = 'Run full iMIS sync';
$string['sync_cancellations'] = 'Sync iMIS cancellations';
$string['sync_cancellations_on_login'] = 'Sync cancellations on login';
$string['sync_cancellations_on_login_desc'] = 'When enabled, a user logging in triggers a cancellation sync for that user.';
$string['sync_enrollments'] = 'Sync iMIS enrolments';
$string['sync_enrolments_on_login'] = 'Sync enrolments on login';
$string['sync_enrolments_on_login_desc'] = 'When enabled, a user logging in triggers an enrolment sync for that user.';
$string['sync_error'] = 'Sync encountered errors. Check the logs.';
$string['sync_groups'] = 'Sync iMIS groups';
$string['sync_groups_on_login'] = 'Sync groups on login';
$string['sync_groups_on_login_desc'] = 'When enabled, a user logging in triggers a group sync for that user.';
$string['sync_success'] = 'Sync completed successfully.';
$string['syncdone'] = 'Sync completed for {$a}.';
$string['syncfailed'] = 'Sync failed: {$a}';
$string['task_cancellations_enabled'] = 'Nightly cancellation sync task';
$string['task_cancellations_enabled_desc'] = 'Run the nightly cancellation sync for all users.';
$string['task_cleanup_log'] = 'iMIS Bridge: prune audit log';
$string['task_enrolments_enabled'] = 'Nightly enrolment sync task';
$string['task_enrolments_enabled_desc'] = 'Run the nightly enrolment sync for all users.';
$string['task_groups_enabled'] = 'Nightly group sync task';
$string['task_groups_enabled_desc'] = 'Run the nightly incremental group sync.';
$string['task_push_activity'] = 'iMIS Bridge: push activity record';
$string['task_sync_cancellations'] = 'iMIS Bridge: sync cancellations';
$string['task_sync_enrollments'] = 'iMIS Bridge: sync enrolments';
$string['task_sync_groups'] = 'iMIS Bridge: sync groups';
$string['task_sync_user'] = 'iMIS Bridge: sync user on login';
$string['testconnection'] = 'Test connection';
$string['testconnection_help'] = 'Runs read-only calls (GetBridgeSettings, plus a profile lookup when a contact ID is entered) to verify connectivity and credentials without writing any data.';
$string['testcredentialsnote'] = 'Connectivity and WSDL verified. The AuthToken was included in the call if one is configured; for a definitive credential check, enter an iMIS contact ID and run again to exercise an authenticated profile lookup.';
$string['testcredentialsok'] = 'Connectivity and credentials (AuthToken) verified.';
$string['transport'] = 'Transport';
$string['transport_desc'] = 'How the plugin talks to iMIS. ATS SOAP bridge uses the ATS wsmoodle.asmx middleware (the WSDL/AuthToken settings). Direct iMIS REST talks to the iMIS REST API directly (the REST settings below), with no ATS middleware.';
$string['transport_rest'] = 'Direct iMIS REST';
$string['transport_soap'] = 'ATS SOAP bridge';
$string['viewtasklogs'] = 'View task logs';
$string['ws_timeout'] = 'Web service timeout (seconds)';
$string['ws_timeout_desc'] = 'Maximum time in seconds to wait when connecting to or reading from the iMIS web service before giving up.';
