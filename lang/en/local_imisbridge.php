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
$string['auditlog'] = 'Integration audit log';
$string['auditlog_desc'] = 'Records each call made to the membership system so you can see what was sent and whether it was accepted.';
$string['autosynccontrols'] = 'Automatic sync controls';
$string['autosynccontrols_desc'] = 'Enable or disable each automatic write to the membership system. Manual syncs on the sync admin page are not affected. Turn writes off when testing against a production system.';
$string['capture_payloads'] = 'Capture full request/response';
$string['capture_payloads_desc'] = 'When enabled, each log entry also stores the full request and response payload (with credentials redacted). This keeps personal data at rest, so enable it only while debugging and turn it off afterwards.';
$string['confirmsync'] = 'This will run the sync against the membership system for member {$a}. Continue?';
$string['contactid'] = 'Member ID';
$string['contactidhelp'] = 'Enter the member ID to synchronise. A manual sync runs for one member only; all-user syncs are handled by the nightly scheduled tasks.';
$string['contactidplaceholder'] = 'e.g. 12345';
$string['contactidrequired'] = 'Member ID required: enter a member ID to run a manual sync. All-user syncs are handled by the nightly scheduled tasks.';
$string['credit_field'] = 'Credit value course field';
$string['credit_field_desc'] = 'Direct iMIS REST only: the short name of the course custom field that holds the credit value awarded on completion, sent when the credit-value column below is set. The ATS SOAP bridge sends no credit (iMIS derives it from the product).';
$string['credit_type'] = 'Default credit type';
$string['credit_type_desc'] = 'Direct iMIS REST only: the credit type (for example, CEU) written to activity records when the credit-type column below is set. The ATS SOAP bridge sends no credit (iMIS derives it from the product).';
$string['endpoint_from_wsdl'] = 'Requests are sent to the service address embedded in the WSDL: {$a}. Verify this matches your intended environment (staging vs production) before running any write.';
$string['endpoint_from_wsdl_unknown'] = 'Requests are sent to the service address embedded in the WSDL (could not read it automatically - open the WSDL and check its <soap:address location>). Verify it matches your intended environment before running any write.';
$string['endpoint_override_active'] = 'Requests are sent to the ENDPOINT OVERRIDE: {$a} (not the address embedded in the WSDL).';
$string['filtercontactid'] = 'Optional: filter by member ID';
$string['forcontact'] = 'member {$a}';
$string['imis_api_auth_token'] = 'ATS API AuthToken';
$string['imis_api_auth_token_desc'] = 'The ATS-issued AuthToken (format: MO-xxxxxx) required on all secured API methods of the ATS SOAP bridge. Store this securely - do not share or commit to version control. Contact ATS if the token needs to be reissued.';
$string['imis_id_source'] = 'Member ID source';
$string['imis_id_source_desc'] = 'The Moodle user field that holds the member ID sent to the external system. Choose the field your identity provider populates with that ID. Defaults to username.';
$string['imis_id_source_email'] = 'Email address';
$string['imis_id_source_idnumber'] = 'ID number';
$string['imis_id_source_username'] = 'Username';
$string['imis_wsdl_url'] = 'ATS SOAP bridge WSDL URL';
$string['imis_wsdl_url_desc'] = 'The WSDL endpoint for the ATS Moodle web service (for example, https://your-ats-host/wsmoodle.asmx?WSDL). Ask ATS for the correct URL for your environment. Used only by the ATS SOAP bridge provider; no sync or connection test runs until this is set.';
$string['imisbridge:cancelenrolment'] = 'Cancel an enrolment (suspend and remove role, without unenrolling) through the membership bridge web service';
$string['imisbridge:readcompletions'] = 'Read course completions and grades through the membership bridge web service';
$string['last_sync'] = 'Last sync time';
$string['log_count'] = '{$a} log entries match.';
$string['log_detail'] = 'Detail';
$string['log_empty'] = 'No log entries match.';
$string['log_enabled'] = 'Enable audit logging';
$string['log_enabled_desc'] = 'Record each call made to the membership system in the audit log.';
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
$string['norestbaseurl'] = 'No REST base URL configured. Check plugin settings.';
$string['notconfigured'] = 'The active provider ({$a}) is not fully configured. Check its connection settings in the plugin configuration.';
$string['nowsdlconfigured'] = 'No WSDL URL configured. Check plugin settings.';
$string['openlog'] = 'Open integration audit log';
$string['opensyncadmin'] = 'Open sync admin';
$string['pluginname'] = 'Membership Bridge';
$string['privacy:metadata:imis'] = 'The Membership Bridge plugin transmits personal data to an external membership/association management system in order to synchronise memberships, enrolments and learning records.';
$string['privacy:metadata:imis:completiondate'] = 'The dates a course was started, completed and credit granted are sent to the membership system.';
$string['privacy:metadata:imis:courseid'] = 'The identifier of the course an activity record relates to is sent to the membership system.';
$string['privacy:metadata:imis:score'] = 'Your assessment score is sent to the membership system.';
$string['privacy:metadata:imis:status'] = 'Your completion status (pass or fail) is sent to the membership system.';
$string['privacy:metadata:imis:userid'] = 'Your member ID (taken from your Moodle username, ID number or email address, as configured) is sent to identify your record in the membership system.';
$string['privacy:metadata:local_imisbridge_log'] = 'A log of the calls the plugin made to the membership system, kept for auditing and troubleshooting.';
$string['privacy:metadata:local_imisbridge_log:error'] = 'Any fault message returned by the membership system for the call.';
$string['privacy:metadata:local_imisbridge_log:imisid'] = 'The member ID sent in the call.';
$string['privacy:metadata:local_imisbridge_log:operation'] = 'The operation that was called.';
$string['privacy:metadata:local_imisbridge_log:productcode'] = 'The product code sent in the call.';
$string['privacy:metadata:local_imisbridge_log:request'] = 'The request sent to the membership system (stored only when payload capture is enabled; credentials are redacted).';
$string['privacy:metadata:local_imisbridge_log:response'] = 'The response received from the membership system (stored only when payload capture is enabled).';
$string['privacy:metadata:local_imisbridge_log:status'] = 'Whether the call to the membership system succeeded or failed.';
$string['privacy:metadata:local_imisbridge_log:timecreated'] = 'The time the call was made.';
$string['privacy:metadata:local_imisbridge_log:userid'] = 'The Moodle user the call related to.';
$string['providernotsupported'] = 'Manual order/group sync and the connection test are provided by the ATS SOAP bridge. The active provider ({$a}) receives pushed completion and score records instead, sent automatically on course completion and quiz submission.';
$string['push_completions'] = 'Push course completions';
$string['push_completions_desc'] = 'When enabled, completing a course sends the learner\'s result to the membership system. Turn off to stop all completion writes.';
$string['push_module_completions'] = 'Push module completions';
$string['push_module_completions_desc'] = 'When enabled, completing a **graded** activity sends a supplementary note record to the membership system. Off by default; ungraded activities are never sent, and this never changes the course-completion record. Note this fires per activity, so it can be high volume.';
$string['push_quiz_scores'] = 'Push quiz scores';
$string['push_quiz_scores_desc'] = 'When enabled, submitting a graded quiz sends the score to the membership system. Turn off to stop all quiz writes.';
$string['pushaudithint'] = 'The record was sent. Open the integration audit log to see the request and response.';
$string['pushcompletion'] = 'Push completion now';
$string['pushcompletion_help'] = 'Send one completion record for the member and course below, without waiting for a course completion and cron run. This writes a real record to the active external system. The status is sent as a pass; the score comes from the course gradebook when a matching Moodle user is found, otherwise 0.';
$string['pushcourseid'] = 'Moodle course ID';
$string['pushcourseidhelp'] = 'The numeric course id (from the course URL, ?id=N). This is sent as the product identifier.';
$string['pushdisabled'] = 'Pushing course completions is turned off (see Push course completions in settings). Enable it to use this test control.';
$string['pushdone'] = 'Completion pushed for member {$a}.';
$string['pushmemberid'] = 'Member ID';
$string['pushmissingfields'] = 'Enter both a member ID and a numeric course ID.';
$string['pushnocourse'] = 'No Moodle course found with id {$a}.';
$string['pushnomatchmany'] = 'More than one Moodle user has {$a->field} set to "{$a->member}", so this test could not pick a learner: the record was sent with placeholder values (status COMPLETE, score 0), not a real grade. Make {$a->field} unique for members, or change the ID source setting.';
$string['pushnomatchnone'] = 'No Moodle user has {$a->field} set to "{$a->member}", so this test had no learner to read: the record was sent with placeholder values (status COMPLETE, score 0), not a real grade. Set that user\'s {$a->field}, or change the ID source setting (Member ID source) to the field that actually holds the iMIS member ID.';
$string['pushnote'] = 'Push activity note now';
$string['pushnoteactivity'] = 'Activity';
$string['pushnotedisabled'] = 'Pushing module completions is turned off (see Push module completions in settings). Enable it to use this test control.';
$string['pushnotedone'] = 'Activity note pushed for member {$a}.';
$string['pushnotelist'] = 'List graded activities';
$string['pushnotenoactivities'] = 'This course has no graded activities to push a note for.';
$string['pushnotenomodule'] = 'No course module found with id {$a}.';
$string['pushnotenotgraded'] = 'That activity is not graded, so no note was sent. Only graded activities are pushed.';
$string['pushnotetest'] = 'Push activity note (test)';
$string['pushnotetest_help'] = 'Send one supplementary note (MOODLENOTE) record for a graded activity in a course, without waiting for a live submission and completion event. This writes a real record to the active external system. Enter a member ID and course ID, list the course\'s graded activities, then choose one to push; the status and score come from the member\'s actual module completion and grade when a matching Moodle user is found.';
$string['pushnotsupported'] = 'The active provider does not support pushing records.';
$string['pushtest'] = 'Push completion (test)';
$string['rest_base_url'] = 'iMIS REST base URL';
$string['rest_base_url_desc'] = 'The base URL of the iMIS instance for the direct REST provider (for example, https://your-org.imiscloud.com), with no trailing path. Used only when the connection provider is set to Direct iMIS REST.';
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
$string['restheading'] = 'Direct iMIS REST provider';
$string['restheading_desc'] = 'Settings for talking directly to the iMIS REST API, with no ATS middleware. They are used by the Direct iMIS REST provider, and by the tutorial group sync whichever provider is selected (with the ATS SOAP bridge selected, filling these in enables only the tutorial group sync). See docs/DIRECT-REST-ARCHITECTURE.md.';
$string['restlockfailed'] = 'Could not acquire the REST write lock for this member and course; the task will retry.';
$string['restrequestfailed'] = 'REST request failed: {$a}.';
$string['resttokenfailed'] = 'REST authentication failed (HTTP {$a}). Check the base URL and service-account credentials.';
$string['schedule_cancellations'] = '02:15 UTC - cancellation sync (all users)';
$string['schedule_enrollments'] = '02:00 UTC - enrolment sync (all users)';
$string['schedule_groups'] = '02:30 UTC - group sync (incremental, based on last run)';
$string['scheduledtaskcontrols'] = 'Scheduled tasks';
$string['scheduledtaskcontrols_desc'] = 'Enable or disable each nightly all-user task. These can also be managed from Site administration > Server > Scheduled tasks.';
$string['scheduledtaskintro'] = 'Scheduled nightly tasks run automatically via Moodle cron:';
$string['scheduledtaskstatus'] = 'Scheduled task status';
$string['service_endpoint'] = 'Service endpoint URL (override)';
$string['service_endpoint_desc'] = 'Optional. When set, all SOAP requests are sent to this URL instead of the service address embedded in the WSDL. Use it when the WSDL advertises a different environment than you intend - for example a test WSDL whose address points at production. Enter the .asmx endpoint with no ?WSDL suffix (for example https://your-ats-host/wsmoodle.asmx). Leave blank to use the address from the WSDL. This controls where writes actually land, so set it to your staging endpoint before any write test.';
$string['servicename'] = 'Membership bridge';
$string['soap_success_result_codes'] = 'SOAP success ResultCodes';
$string['soap_success_result_codes_desc'] = 'Comma-separated ATS ResultCodes to treat as success for SOAP writes (for example, 0). Leave blank to flag a failure only when the response carries an Exception. Set the real success code(s) to also treat an unexpected ResultCode as a failure, so a rejected write is logged as an error and retried.';
$string['soapresulterror'] = 'The SOAP provider reported a failure: {$a}.';
$string['status_error'] = 'Error';
$string['status_success'] = 'Success';
$string['sync_all'] = 'Run full sync';
$string['sync_cancellations'] = 'Sync cancellations';
$string['sync_cancellations_on_login'] = 'Sync cancellations on login';
$string['sync_cancellations_on_login_desc'] = 'When enabled, a user logging in triggers a cancellation sync for that user.';
$string['sync_enrollments'] = 'Sync enrolments';
$string['sync_enrolments_on_login'] = 'Sync enrolments on login';
$string['sync_enrolments_on_login_desc'] = 'When enabled, a user logging in triggers an enrolment sync for that user.';
$string['sync_error'] = 'Sync encountered errors. Check the logs.';
$string['sync_groups'] = 'Sync groups';
$string['sync_groups_on_login'] = 'Sync groups on login';
$string['sync_groups_on_login_desc'] = 'When enabled, a user logging in triggers a group sync for that user.';
$string['sync_success'] = 'Sync completed successfully.';
$string['syncdone'] = 'Sync completed for {$a}.';
$string['syncfailed'] = 'Sync failed: {$a}';
$string['syncresult'] = 'Sync result';
$string['syncresulthelp'] = 'The raw response returned by the membership system for each call just run. For a full history of every call and its status, open the integration audit log.';
$string['task_cancellations_enabled'] = 'Nightly cancellation sync task';
$string['task_cancellations_enabled_desc'] = 'Run the nightly cancellation sync for all users.';
$string['task_cleanup_log'] = 'Membership Bridge: prune audit log';
$string['task_enrolments_enabled'] = 'Nightly enrolment sync task';
$string['task_enrolments_enabled_desc'] = 'Run the nightly enrolment sync for all users.';
$string['task_groups_enabled'] = 'Nightly group sync task';
$string['task_groups_enabled_desc'] = 'Run the nightly incremental group sync.';
$string['task_push_activity'] = 'Membership Bridge: push activity record';
$string['task_push_module_note'] = 'Membership Bridge: push module completion note';
$string['task_sync_cancellations'] = 'Membership Bridge: sync cancellations';
$string['task_sync_enrollments'] = 'Membership Bridge: sync enrolments';
$string['task_sync_groups'] = 'Membership Bridge: sync groups';
$string['task_sync_tutorial_groups'] = 'Membership Bridge: sync tutorial groups';
$string['task_sync_user'] = 'Membership Bridge: sync user on login';
$string['testconnection'] = 'Test connection';
$string['testconnection_help'] = 'Runs read-only calls (GetBridgeSettings, plus a profile lookup when a member ID is entered) to verify connectivity and credentials without writing any data. Applies to the ATS SOAP bridge provider.';
$string['testcredentialsnote'] = 'Connectivity and WSDL verified. The AuthToken was included in the call if one is configured; for a definitive credential check, enter a member ID and run again to exercise an authenticated profile lookup.';
$string['testcredentialsok'] = 'Connectivity and credentials (AuthToken) verified.';
$string['transport'] = 'Connection provider';
$string['transport_desc'] = 'Which provider connects Moodle to your membership system. The ATS SOAP bridge talks to iMIS through the ATS wsmoodle.asmx middleware (the WSDL/AuthToken settings); Direct iMIS REST talks to the iMIS REST API directly (the REST settings below), with no middleware. Each provider uses only its own settings.';
$string['transport_rest'] = 'Direct iMIS REST';
$string['transport_soap'] = 'ATS SOAP bridge (iMIS)';
$string['tutorial_course_code_suffix'] = 'Registration product suffix';
$string['tutorial_course_code_suffix_desc'] = 'MOODLE_PRODUCTS is keyed by the full product code (e.g. ATS_BRIDGE/REG) while the tutorial panel stores the bare event code (ATS_BRIDGE). This suffix is appended to the bare code to find its registration product row, so a neighbour such as /REGNZ is never matched. Set to empty if your lookup is keyed by the bare code.';
$string['tutorial_course_iqa'] = 'Course lookup IQA';
$string['tutorial_course_iqa_desc'] = 'The iMIS IQA document path that maps a product code to a Moodle course id (the {code}/REG row\'s Description column). Change this if your MOODLE_PRODUCTS lookup lives elsewhere, e.g. after the program rework.';
$string['tutorial_dry_run'] = 'Tutorial group sync: dry run';
$string['tutorial_dry_run_desc'] = 'When enabled, the sync logs every group and membership change it would make without changing anything. Leave this on when first enabling the sync on a site, then turn it off to apply changes.';
$string['tutorial_grouping_name'] = 'Tutorials (iMIS)';
$string['tutorial_removal_guard_pct'] = 'Tutorial group sync: removal guard (%)';
$string['tutorial_removal_guard_pct_desc'] = 'Safety limit: if a single run would remove 10 or more of a group\'s members and that is more than this percentage of the group, the removals for that group are skipped and logged (additions still happen). Smaller removals are never blocked. A read that returns no rows at all makes no changes.';
$string['tutorial_sync_enabled'] = 'Enable tutorial group sync';
$string['tutorial_sync_enabled_desc'] = 'Master switch for the scheduled task (nightly by default; change it under Site administration > Server > Scheduled tasks) that makes Moodle course groups match the iMIS MoodleTutorialGroup panel. Off by default. This feature reads iMIS over REST and needs the REST connection settings configured, regardless of the connection provider chosen above.';
$string['tutorialgroups'] = 'Tutorial group sync';
$string['tutorialgroups_desc'] = 'One-way sync (iMIS to Moodle) that mirrors the iMIS tutorial-group builder into Moodle course groups. iMIS is the source of truth: people added in iMIS are added to the Moodle group, and people removed in iMIS are removed. It only touches its own groups (idnumber prefix "imis:") and its own memberships.';
$string['viewlogshelp'] = 'The integration audit log shows each call made to the membership system and whether it succeeded - that is, what actually synced. The scheduled task run logs show the cron execution output for the nightly tasks.';
$string['viewtasklogs'] = 'View task logs';
$string['viewtaskrunlogs'] = 'Scheduled task run logs (cron output)';
$string['ws_timeout'] = 'Connection timeout (seconds)';
$string['ws_timeout_desc'] = 'Maximum time in seconds to wait when connecting to or reading from the membership system before giving up.';
