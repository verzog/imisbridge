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
 * Manual sync admin page for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Registered admin page: handles login, the moodle/site:config capability, the
// page URL/context/title/heading, and makes the page bookmarkable from the admin.
admin_externalpage_setup('local_imisbridge_sync');

// Use POST to avoid action/contactid appearing in browser history and server logs.
$action    = optional_param('action', '', PARAM_ALPHANUMEXT);
$contactid = optional_param('contactid', '', PARAM_TEXT);
$message    = '';
$error      = '';
$testoutput = '';
$syncoutput = '';

if ($action === 'testconnection' && confirm_sesskey()) {
    // Read-only: verify connectivity without writing any data. GetBridgeSettings
    // sends the AuthToken when one is configured; a profile lookup (contact ID)
    // always exercises it.
    try {
        $client = new \local_imisbridge\imis_client();
        // Show where requests will actually be sent (override vs WSDL address) so
        // a WSDL that advertises the wrong environment is caught before writing.
        $override = \local_imisbridge\imis_client::get_effective_endpoint();
        if ($override !== null) {
            $endpointline = get_string('endpoint_override_active', 'local_imisbridge', $override);
        } else {
            $wsdladdr     = \local_imisbridge\imis_client::read_wsdl_endpoint();
            $endpointline = ($wsdladdr !== null)
                ? get_string('endpoint_from_wsdl', 'local_imisbridge', $wsdladdr)
                : get_string('endpoint_from_wsdl_unknown', 'local_imisbridge');
        }
        $out  = $endpointline . PHP_EOL . PHP_EOL;
        $out .= 'GetBridgeSettings:' . PHP_EOL . var_export($client->get_bridge_settings(), true);
        if (!empty($contactid)) {
            $out .= PHP_EOL . PHP_EOL . 'MoodleGetUserProfile(' . $contactid . '):' . PHP_EOL
                . var_export($client->get_contact_by_id($contactid), true);
            $out .= PHP_EOL . PHP_EOL . get_string('testcredentialsok', 'local_imisbridge');
        } else {
            $out .= PHP_EOL . PHP_EOL . get_string('testcredentialsnote', 'local_imisbridge');
        }
        $testoutput = $out;
    } catch (\Throwable $e) {
        $error = get_string('syncfailed', 'local_imisbridge', $e->getMessage());
    }
} else if ($action && confirm_sesskey()) {
    $confirmed = optional_param('confirm', 0, PARAM_BOOL);
    $cid       = !empty($contactid) ? $contactid : null;

    if (!$confirmed) {
        // Confirm before any write: a blank contact ID syncs all users in production.
        $warning = $cid
            ? get_string('confirmsync', 'local_imisbridge', s($contactid))
            : get_string('confirmsyncall', 'local_imisbridge');
        $continueurl = new moodle_url('/local/imisbridge/admin/sync.php', [
            'action'    => $action,
            'contactid' => $contactid,
            'confirm'   => 1,
            'sesskey'   => sesskey(),
        ]);

        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            $warning,
            new single_button($continueurl, get_string('continue'), 'post'),
            new moodle_url('/local/imisbridge/admin/sync.php')
        );
        echo $OUTPUT->footer();
        exit;
    }

    // A full all-user sync can be slow; give it room beyond the default web limit.
    \core_php_time_limit::raise(300);

    try {
        $client = new \local_imisbridge\imis_client();
        $scope  = $cid
            ? get_string('forcontact', 'local_imisbridge', s($contactid))
            : get_string('allusers', 'local_imisbridge');

        // Collect each call's raw result so a manual sync of a few records can be
        // eyeballed on this page without opening the audit log.
        $results = [];
        switch ($action) {
            case 'enrollments':
                $results['SendNewOrdersToMoodle'] = $client->sync_orders($cid);
                break;

            case 'cancellations':
                $results['SendCancelledOrdersToMoodle'] = $client->sync_cancelled_orders($cid);
                break;

            case 'groups':
                $results['UpdateMoodleGroups'] = $client->update_groups($cid);
                break;

            case 'all':
                $results['SendNewOrdersToMoodle']       = $client->sync_orders($cid);
                $results['SendCancelledOrdersToMoodle'] = $client->sync_cancelled_orders($cid);
                $results['UpdateMoodleGroups']          = $client->update_groups($cid);
                break;
        }

        $message = get_string('syncdone', 'local_imisbridge', $scope);

        $out = '';
        foreach ($results as $op => $res) {
            $out .= $op . ':' . PHP_EOL . var_export($res, true) . PHP_EOL . PHP_EOL;
        }
        $syncoutput = trim($out);
    } catch (\Exception $e) {
        $error = get_string('syncfailed', 'local_imisbridge', $e->getMessage());
    }
}

echo $OUTPUT->header();

if ($message) {
    echo $OUTPUT->notification($message, 'notifysuccess');
}
if ($error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

$syncurl  = new moodle_url('/local/imisbridge/admin/sync.php');
$auditurl = new moodle_url('/local/imisbridge/admin/log.php');
$logsurl  = new moodle_url('/admin/tasklogs.php');
$sesskey  = sesskey();
$cidvalue = s($contactid);

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('filtercontactid', 'local_imisbridge'), ['class' => 'card-title']);
echo '<form method="post" action="">';
echo '<input type="hidden" name="sesskey" value="' . $sesskey . '">';
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('contactid', 'local_imisbridge'), ['for' => 'contactid']);
echo '<input type="text" id="contactid" name="contactid" class="form-control"'
    . ' value="' . $cidvalue . '" placeholder="' . s(get_string('contactidplaceholder', 'local_imisbridge')) . '">';
echo html_writer::tag('small', get_string('contactidhelp', 'local_imisbridge'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::start_div('mt-3 d-flex gap-2 flex-wrap');
echo '<button type="submit" name="action" value="enrollments" class="btn btn-primary">'
    . s(get_string('sync_enrollments', 'local_imisbridge')) . '</button>';
echo '<button type="submit" name="action" value="cancellations" class="btn btn-warning">'
    . s(get_string('sync_cancellations', 'local_imisbridge')) . '</button>';
echo '<button type="submit" name="action" value="groups" class="btn btn-info">'
    . s(get_string('sync_groups', 'local_imisbridge')) . '</button>';
echo '<button type="submit" name="action" value="all" class="btn btn-success">'
    . s(get_string('sync_all', 'local_imisbridge')) . '</button>';
echo '<button type="submit" name="action" value="testconnection" class="btn btn-outline-secondary">'
    . s(get_string('testconnection', 'local_imisbridge')) . '</button>';
echo html_writer::end_div();
echo html_writer::tag('small', get_string('testconnection_help', 'local_imisbridge'), ['class' => 'form-text text-muted mt-2']);
echo '</form>';
echo html_writer::end_div();
echo html_writer::end_div();

if ($testoutput !== '') {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('testconnection', 'local_imisbridge'), ['class' => 'card-title']);
    echo html_writer::tag('pre', s($testoutput), ['class' => 'bg-light p-3']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($syncoutput !== '') {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('syncresult', 'local_imisbridge'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('syncresulthelp', 'local_imisbridge'), ['class' => 'text-muted']);
    echo html_writer::tag('pre', s($syncoutput), ['class' => 'bg-light p-3']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('card');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('scheduledtaskstatus', 'local_imisbridge'), ['class' => 'card-title']);
echo html_writer::tag('p', get_string('scheduledtaskintro', 'local_imisbridge'));
echo html_writer::start_tag('ul');
echo html_writer::tag('li', get_string('schedule_enrollments', 'local_imisbridge'));
echo html_writer::tag('li', get_string('schedule_cancellations', 'local_imisbridge'));
echo html_writer::tag('li', get_string('schedule_groups', 'local_imisbridge'));
echo html_writer::end_tag('ul');
// Primary link: the iMIS audit log shows what was actually sent to iMIS and the
// result. The secondary link is Moodle's cron run output for the scheduled tasks.
echo html_writer::tag('p', get_string('viewlogshelp', 'local_imisbridge'), ['class' => 'text-muted']);
echo html_writer::start_div('d-flex gap-2 flex-wrap');
echo html_writer::link($auditurl, get_string('openlog', 'local_imisbridge'), ['class' => 'btn btn-primary btn-sm']);
echo html_writer::link($logsurl, get_string('viewtaskrunlogs', 'local_imisbridge'), ['class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
