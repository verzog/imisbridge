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
 * Admin settings for local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_membershipbridge', get_string('pluginname', 'local_membershipbridge'));

    $ADMIN->add('localplugins', $settings);

    // Register the sync and audit-log pages as admin external pages so they appear
    // in the admin navigation and can be added to the admin bookmarks.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_membershipbridge_sync',
        get_string('manualsynccontrols', 'local_membershipbridge'),
        new moodle_url('/local/membershipbridge/admin/sync.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_membershipbridge_log',
        get_string('openlog', 'local_membershipbridge'),
        new moodle_url('/local/membershipbridge/admin/log.php'),
        'moodle/site:config'
    ));

    // Which provider connects to the external system. The choices come from the
    // provider registry, so adding a provider class makes it selectable here.
    $settings->add(new admin_setting_configselect(
        'local_membershipbridge/transport',
        get_string('transport', 'local_membershipbridge'),
        get_string('transport_desc', 'local_membershipbridge'),
        'soap',
        \local_membershipbridge\provider\manager::menu()
    ));

    // Which provider runs the enrolment, cancellation and group syncs. Blank follows
    // the connection provider (the behaviour before this setting existed).
    $settings->add(new admin_setting_configselect(
        'local_membershipbridge/sync_provider',
        get_string('sync_provider', 'local_membershipbridge'),
        get_string('sync_provider_desc', 'local_membershipbridge'),
        '',
        \local_membershipbridge\provider\manager::sync_menu()
    ));

    // WSDL endpoint.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/wsdl_url',
        get_string('imis_wsdl_url', 'local_membershipbridge'),
        get_string('imis_wsdl_url_desc', 'local_membershipbridge'),
        '',
        PARAM_URL
    ));

    // Optional service endpoint override. When set, SOAP requests are sent here
    // instead of the address embedded in the WSDL (guards against a test WSDL
    // that advertises a production endpoint).
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/service_endpoint',
        get_string('service_endpoint', 'local_membershipbridge'),
        get_string('service_endpoint_desc', 'local_membershipbridge'),
        '',
        PARAM_URL
    ));

    // ATS API AuthToken (MO-xxxxxx) — required on all secured methods from 2025 onward.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_membershipbridge/auth_token',
        get_string('imis_api_auth_token', 'local_membershipbridge'),
        get_string('imis_api_auth_token_desc', 'local_membershipbridge'),
        '',
        PARAM_TEXT
    ));

    // Which Moodle user field holds the iMIS contact ID sent to iMIS.
    $settings->add(new admin_setting_configselect(
        'local_membershipbridge/imis_id_source',
        get_string('imis_id_source', 'local_membershipbridge'),
        get_string('imis_id_source_desc', 'local_membershipbridge'),
        'username',
        [
            'username' => get_string('imis_id_source_username', 'local_membershipbridge'),
            'idnumber' => get_string('imis_id_source_idnumber', 'local_membershipbridge'),
            'email'    => get_string('imis_id_source_email', 'local_membershipbridge'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_membershipbridge/imis_id_format',
        get_string('imis_id_format', 'local_membershipbridge'),
        get_string('imis_id_format_desc', 'local_membershipbridge'),
        'any',
        [
            'any'    => get_string('imis_id_format_any', 'local_membershipbridge'),
            'digits' => get_string('imis_id_format_digits', 'local_membershipbridge'),
        ]
    ));

    // Web service timeout (seconds) for calls to iMIS.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/ws_timeout',
        get_string('ws_timeout', 'local_membershipbridge'),
        get_string('ws_timeout_desc', 'local_membershipbridge'),
        '30',
        PARAM_INT
    ));

    // Default credit type recorded against iMIS activity records.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/credit_type',
        get_string('credit_type', 'local_membershipbridge'),
        get_string('credit_type_desc', 'local_membershipbridge'),
        'CEU',
        PARAM_ALPHANUMEXT
    ));

    // Course custom field short name holding the credit value.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/credit_field',
        get_string('credit_field', 'local_membershipbridge'),
        get_string('credit_field_desc', 'local_membershipbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // SOAP ResultCodes treated as success. Blank = judge failures only by the
    // response's Exception field; set the real ATS success code(s) to also flag an
    // unexpected ResultCode as a failure.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/soap_success_result_codes',
        get_string('soap_success_result_codes', 'local_membershipbridge'),
        get_string('soap_success_result_codes_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // REST connection settings for iMIS: required by the tutorial group sync under
    // either provider, and used for pushes when Transport = Direct iMIS REST.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/restheading',
        get_string('restheading', 'local_membershipbridge'),
        get_string('restheading_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_base_url',
        get_string('rest_base_url', 'local_membershipbridge'),
        get_string('rest_base_url_desc', 'local_membershipbridge'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_username',
        get_string('rest_username', 'local_membershipbridge'),
        get_string('rest_username_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_membershipbridge/rest_password',
        get_string('rest_password', 'local_membershipbridge'),
        get_string('rest_password_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW
    ));

    // Optional iMIS column mappings. Leave blank until confirmed against iMIS;
    // a blank column name means that value is not sent.
    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_col_credittype',
        get_string('rest_col_credittype', 'local_membershipbridge'),
        get_string('rest_col_credittype_desc', 'local_membershipbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_col_creditvalue',
        get_string('rest_col_creditvalue', 'local_membershipbridge'),
        get_string('rest_col_creditvalue_desc', 'local_membershipbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_col_grantdate',
        get_string('rest_col_grantdate', 'local_membershipbridge'),
        get_string('rest_col_grantdate_desc', 'local_membershipbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/rest_col_completiondate',
        get_string('rest_col_completiondate', 'local_membershipbridge'),
        get_string('rest_col_completiondate_desc', 'local_membershipbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // Automatic sync controls — toggle each automatic write to iMIS.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/autosynccontrols',
        get_string('autosynccontrols', 'local_membershipbridge'),
        get_string('autosynccontrols_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/sync_enrolments_on_login',
        get_string('sync_enrolments_on_login', 'local_membershipbridge'),
        get_string('sync_enrolments_on_login_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/sync_cancellations_on_login',
        get_string('sync_cancellations_on_login', 'local_membershipbridge'),
        get_string('sync_cancellations_on_login_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/sync_groups_on_login',
        get_string('sync_groups_on_login', 'local_membershipbridge'),
        get_string('sync_groups_on_login_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/push_completions',
        get_string('push_completions', 'local_membershipbridge'),
        get_string('push_completions_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/push_quiz_scores',
        get_string('push_quiz_scores', 'local_membershipbridge'),
        get_string('push_quiz_scores_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/push_module_completions',
        get_string('push_module_completions', 'local_membershipbridge'),
        get_string('push_module_completions_desc', 'local_membershipbridge'),
        0
    ));

    // Which gradable activity types also send their score when graded. The list is
    // built from what each installed activity declares, so none is special-cased.
    $settings->add(new \local_membershipbridge\admin\score_modules_setting(
        'local_membershipbridge/push_score_modules',
        get_string('push_score_modules', 'local_membershipbridge'),
        get_string('push_score_modules_desc', 'local_membershipbridge')
    ));

    // Scheduled task controls — toggle each nightly all-user task.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/scheduledtaskcontrols',
        get_string('scheduledtaskcontrols', 'local_membershipbridge'),
        get_string('scheduledtaskcontrols_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/task_enrolments_enabled',
        get_string('task_enrolments_enabled', 'local_membershipbridge'),
        get_string('task_enrolments_enabled_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/task_cancellations_enabled',
        get_string('task_cancellations_enabled', 'local_membershipbridge'),
        get_string('task_cancellations_enabled_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/task_groups_enabled',
        get_string('task_groups_enabled', 'local_membershipbridge'),
        get_string('task_groups_enabled_desc', 'local_membershipbridge'),
        1
    ));

    // Registration sync over direct REST: enrol and suspend from a registrations IQA.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/regsyncheading',
        get_string('regsync', 'local_membershipbridge'),
        get_string('regsync_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/reg_iqa',
        get_string('reg_iqa', 'local_membershipbridge'),
        get_string('reg_iqa_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/reg_dry_run',
        get_string('reg_dry_run', 'local_membershipbridge'),
        get_string('reg_dry_run_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/reg_create_users',
        get_string('reg_create_users', 'local_membershipbridge'),
        get_string('reg_create_users_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/reg_cancelled_codes',
        get_string('reg_cancelled_codes', 'local_membershipbridge'),
        get_string('reg_cancelled_codes_desc', 'local_membershipbridge'),
        'C',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/reg_lookback_days',
        get_string('reg_lookback_days', 'local_membershipbridge'),
        get_string('reg_lookback_days_desc', 'local_membershipbridge'),
        '7',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/reg_guard_pct',
        get_string('reg_guard_pct', 'local_membershipbridge'),
        get_string('reg_guard_pct_desc', 'local_membershipbridge'),
        '50',
        PARAM_INT
    ));

    // Tutorial group sync — reads the iMIS MoodleTutorialGroup panel over REST.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/tutorialgroupsheading',
        get_string('tutorialgroups', 'local_membershipbridge'),
        get_string('tutorialgroups_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/tutorial_sync_enabled',
        get_string('tutorial_sync_enabled', 'local_membershipbridge'),
        get_string('tutorial_sync_enabled_desc', 'local_membershipbridge'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/tutorial_dry_run',
        get_string('tutorial_dry_run', 'local_membershipbridge'),
        get_string('tutorial_dry_run_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/tutorial_removal_guard_pct',
        get_string('tutorial_removal_guard_pct', 'local_membershipbridge'),
        get_string('tutorial_removal_guard_pct_desc', 'local_membershipbridge'),
        '50',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/tutorial_course_iqa',
        get_string('tutorial_course_iqa', 'local_membershipbridge'),
        get_string('tutorial_course_iqa_desc', 'local_membershipbridge'),
        '$/_SCCA/Events/MoodleCourseLookup',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/tutorial_course_code_suffix',
        get_string('tutorial_course_code_suffix', 'local_membershipbridge'),
        get_string('tutorial_course_code_suffix_desc', 'local_membershipbridge'),
        '/REG',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/tutorial_tutor_field',
        get_string('tutorial_tutor_field', 'local_membershipbridge'),
        get_string('tutorial_tutor_field_desc', 'local_membershipbridge'),
        'Tutor',
        PARAM_ALPHANUMEXT
    ));

    // Course-level roles a tutor may be given; never manager or site-wide roles.
    $tutorroles = [];
    foreach (role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL) as $role) {
        if (in_array($role->archetype, ['teacher', 'editingteacher'], true)
                || ($role->archetype === '' && in_array(CONTEXT_COURSE, get_role_contextlevels($role->id)))) {
            $tutorroles[$role->shortname] = $role->localname;
        }
    }
    $settings->add(new admin_setting_configselect(
        'local_membershipbridge/tutorial_tutor_role',
        get_string('tutorial_tutor_role', 'local_membershipbridge'),
        get_string('tutorial_tutor_role_desc', 'local_membershipbridge'),
        'teacher',
        $tutorroles
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_membershipbridge/course_map_overrides',
        get_string('course_map_overrides', 'local_membershipbridge'),
        get_string('course_map_overrides_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_membershipbridge/push_course_overrides',
        get_string('push_course_overrides', 'local_membershipbridge'),
        get_string('push_course_overrides_desc', 'local_membershipbridge'),
        '',
        PARAM_RAW
    ));

    // Audit log controls.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/auditlogheading',
        get_string('auditlog', 'local_membershipbridge'),
        get_string('auditlog_desc', 'local_membershipbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/log_enabled',
        get_string('log_enabled', 'local_membershipbridge'),
        get_string('log_enabled_desc', 'local_membershipbridge'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_membershipbridge/log_retention_days',
        get_string('log_retention_days', 'local_membershipbridge'),
        get_string('log_retention_days_desc', 'local_membershipbridge'),
        '90',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_membershipbridge/capture_payloads',
        get_string('capture_payloads', 'local_membershipbridge'),
        get_string('capture_payloads_desc', 'local_membershipbridge'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_membershipbridge/viewlogheading',
        '',
        html_writer::link(
            new moodle_url('/local/membershipbridge/admin/log.php'),
            get_string('openlog', 'local_membershipbridge'),
            ['class' => 'btn btn-secondary']
        )
    ));

    // Manual sync controls — link to admin tool page.
    $settings->add(new admin_setting_heading(
        'local_membershipbridge/manual_sync_heading',
        get_string('manualsynccontrols', 'local_membershipbridge'),
        html_writer::link(
            new moodle_url('/local/membershipbridge/admin/sync.php'),
            get_string('opensyncadmin', 'local_membershipbridge'),
            ['class' => 'btn btn-primary']
        )
    ));
}
