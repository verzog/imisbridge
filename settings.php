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
 * Admin settings for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_imisbridge', get_string('pluginname', 'local_imisbridge'));

    $ADMIN->add('localplugins', $settings);

    // WSDL endpoint.
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/wsdl_url',
        get_string('imis_wsdl_url', 'local_imisbridge'),
        get_string('imis_wsdl_url_desc', 'local_imisbridge'),
        '',
        PARAM_URL
    ));

    // Optional service endpoint override. When set, SOAP requests are sent here
    // instead of the address embedded in the WSDL (guards against a test WSDL
    // that advertises a production endpoint).
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/service_endpoint',
        get_string('service_endpoint', 'local_imisbridge'),
        get_string('service_endpoint_desc', 'local_imisbridge'),
        '',
        PARAM_URL
    ));

    // ATS API AuthToken (MO-xxxxxx) — required on all secured methods from 2025 onward.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_imisbridge/auth_token',
        get_string('imis_api_auth_token', 'local_imisbridge'),
        get_string('imis_api_auth_token_desc', 'local_imisbridge'),
        '',
        PARAM_TEXT
    ));

    // Admin iMIS ID for SSO session token generation.
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/admin_imis_id',
        get_string('imis_auth_token', 'local_imisbridge'),
        get_string('imis_auth_token_desc', 'local_imisbridge'),
        '',
        PARAM_TEXT
    ));

    // Which Moodle user field holds the iMIS contact ID sent to iMIS.
    $settings->add(new admin_setting_configselect(
        'local_imisbridge/imis_id_source',
        get_string('imis_id_source', 'local_imisbridge'),
        get_string('imis_id_source_desc', 'local_imisbridge'),
        'username',
        [
            'username' => get_string('imis_id_source_username', 'local_imisbridge'),
            'idnumber' => get_string('imis_id_source_idnumber', 'local_imisbridge'),
            'email'    => get_string('imis_id_source_email', 'local_imisbridge'),
        ]
    ));

    // Web service timeout (seconds) for calls to iMIS.
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/ws_timeout',
        get_string('ws_timeout', 'local_imisbridge'),
        get_string('ws_timeout_desc', 'local_imisbridge'),
        '30',
        PARAM_INT
    ));

    // Default credit type recorded against iMIS activity records.
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/credit_type',
        get_string('credit_type', 'local_imisbridge'),
        get_string('credit_type_desc', 'local_imisbridge'),
        'CEU',
        PARAM_ALPHANUMEXT
    ));

    // Course custom field short name holding the credit value.
    $settings->add(new admin_setting_configtext(
        'local_imisbridge/credit_field',
        get_string('credit_field', 'local_imisbridge'),
        get_string('credit_field_desc', 'local_imisbridge'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // Automatic sync controls — toggle each automatic write to iMIS.
    $settings->add(new admin_setting_heading(
        'local_imisbridge/autosynccontrols',
        get_string('autosynccontrols', 'local_imisbridge'),
        get_string('autosynccontrols_desc', 'local_imisbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/sync_enrolments_on_login',
        get_string('sync_enrolments_on_login', 'local_imisbridge'),
        get_string('sync_enrolments_on_login_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/sync_cancellations_on_login',
        get_string('sync_cancellations_on_login', 'local_imisbridge'),
        get_string('sync_cancellations_on_login_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/sync_groups_on_login',
        get_string('sync_groups_on_login', 'local_imisbridge'),
        get_string('sync_groups_on_login_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/push_completions',
        get_string('push_completions', 'local_imisbridge'),
        get_string('push_completions_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/push_quiz_scores',
        get_string('push_quiz_scores', 'local_imisbridge'),
        get_string('push_quiz_scores_desc', 'local_imisbridge'),
        1
    ));

    // Scheduled task controls — toggle each nightly all-user task.
    $settings->add(new admin_setting_heading(
        'local_imisbridge/scheduledtaskcontrols',
        get_string('scheduledtaskcontrols', 'local_imisbridge'),
        get_string('scheduledtaskcontrols_desc', 'local_imisbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/task_enrolments_enabled',
        get_string('task_enrolments_enabled', 'local_imisbridge'),
        get_string('task_enrolments_enabled_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/task_cancellations_enabled',
        get_string('task_cancellations_enabled', 'local_imisbridge'),
        get_string('task_cancellations_enabled_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/task_groups_enabled',
        get_string('task_groups_enabled', 'local_imisbridge'),
        get_string('task_groups_enabled_desc', 'local_imisbridge'),
        1
    ));

    // Audit log controls.
    $settings->add(new admin_setting_heading(
        'local_imisbridge/auditlogheading',
        get_string('auditlog', 'local_imisbridge'),
        get_string('auditlog_desc', 'local_imisbridge')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/log_enabled',
        get_string('log_enabled', 'local_imisbridge'),
        get_string('log_enabled_desc', 'local_imisbridge'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_imisbridge/log_retention_days',
        get_string('log_retention_days', 'local_imisbridge'),
        get_string('log_retention_days_desc', 'local_imisbridge'),
        '90',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_imisbridge/capture_payloads',
        get_string('capture_payloads', 'local_imisbridge'),
        get_string('capture_payloads_desc', 'local_imisbridge'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_imisbridge/viewlogheading',
        '',
        html_writer::link(
            new moodle_url('/local/imisbridge/admin/log.php'),
            get_string('openlog', 'local_imisbridge'),
            ['class' => 'btn btn-secondary']
        )
    ));

    // Manual sync controls — link to admin tool page.
    $settings->add(new admin_setting_heading(
        'local_imisbridge/manual_sync_heading',
        get_string('manualsynccontrols', 'local_imisbridge'),
        html_writer::link(
            new moodle_url('/local/imisbridge/admin/sync.php'),
            get_string('opensyncadmin', 'local_imisbridge'),
            ['class' => 'btn btn-primary']
        )
    ));
}
