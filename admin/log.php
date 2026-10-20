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
 * Audit-log report page for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Registered admin page: handles login, the moodle/site:config capability, the
// page context/title/heading, and makes the page bookmarkable from the admin.
admin_externalpage_setup('local_imisbridge_log');

$operation = optional_param('operation', '', PARAM_ALPHANUMEXT);
$status    = optional_param('status', '', PARAM_ALPHA);
$page      = optional_param('page', 0, PARAM_INT);

$perpage = 50;

$baseurl = new moodle_url('/local/imisbridge/admin/log.php');
$pageurl = new moodle_url($baseurl, ['operation' => $operation, 'status' => $status]);

$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('auditlog', 'local_imisbridge'));
$PAGE->set_heading(get_string('auditlog', 'local_imisbridge'));

// Build the filter conditions.
$where  = [];
$params = [];
if ($operation !== '') {
    $where[] = 'operation = :operation';
    $params['operation'] = $operation;
}
if ($status === \local_imisbridge\logger::STATUS_SUCCESS || $status === \local_imisbridge\logger::STATUS_ERROR) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
$select = implode(' AND ', $where);

$total   = $DB->count_records_select('local_imisbridge_log', $select, $params);
$records = $DB->get_records_select(
    'local_imisbridge_log',
    $select,
    $params,
    'timecreated DESC, id DESC',
    '*',
    $page * $perpage,
    $perpage
);

// Preload the users shown on this page for name display.
$userids = array_filter(array_column($records, 'userid'));
$users   = $userids ? $DB->get_records_list('user', 'id', $userids, '', '*') : [];

echo $OUTPUT->header();

// Filter form.
$operations = $DB->get_fieldset_sql('SELECT DISTINCT operation FROM {local_imisbridge_log} ORDER BY operation');
$opmenu     = ['' => get_string('log_filter_all', 'local_imisbridge')];
foreach ($operations as $op) {
    $opmenu[$op] = $op;
}
$statusmenu = [
    ''                      => get_string('log_filter_all', 'local_imisbridge'),
    \local_imisbridge\logger::STATUS_SUCCESS  => get_string('status_success', 'local_imisbridge'),
    \local_imisbridge\logger::STATUS_ERROR    => get_string('status_error', 'local_imisbridge'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out(), 'class' => 'mb-3']);
echo html_writer::start_div('form-inline d-flex gap-2 flex-wrap align-items-end');
echo html_writer::div(
    html_writer::tag('label', get_string('log_operation', 'local_imisbridge'), ['for' => 'operation'])
        . html_writer::select($opmenu, 'operation', $operation, false, ['id' => 'operation', 'class' => 'ml-1']),
    'form-group'
);
echo html_writer::div(
    html_writer::tag('label', get_string('log_status', 'local_imisbridge'), ['for' => 'status'])
        . html_writer::select($statusmenu, 'status', $status, false, ['id' => 'status', 'class' => 'ml-1']),
    'form-group'
);
echo html_writer::tag('button', get_string('log_filter', 'local_imisbridge'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (!$records) {
    echo $OUTPUT->notification(get_string('log_empty', 'local_imisbridge'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('log_count', 'local_imisbridge', $total));

$table = new html_table();
$table->head = [
    get_string('log_time', 'local_imisbridge'),
    get_string('log_user', 'local_imisbridge'),
    get_string('log_operation', 'local_imisbridge'),
    get_string('contactid', 'local_imisbridge'),
    get_string('log_product', 'local_imisbridge'),
    get_string('log_status', 'local_imisbridge'),
    get_string('log_detail', 'local_imisbridge'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

foreach ($records as $row) {
    // User cell.
    if (!empty($row->userid) && isset($users[$row->userid])) {
        $usercell = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $row->userid]),
            fullname($users[$row->userid])
        );
    } else {
        $usercell = '-';
    }

    // Status badge.
    $badgeclass = ($row->status === \local_imisbridge\logger::STATUS_SUCCESS) ? 'badge badge-success' : 'badge badge-danger';
    $statuscell = html_writer::tag('span', s($row->status), ['class' => $badgeclass]);

    // Collapsible request/response and error.
    $detail = '';
    if ($row->error !== null && $row->error !== '') {
        $detail .= html_writer::tag('div', s($row->error), ['class' => 'text-danger mb-1']);
    }
    $pre = '';
    if (!empty($row->request)) {
        $pre .= html_writer::tag('strong', get_string('log_request', 'local_imisbridge'));
        $pre .= html_writer::tag('pre', s($row->request), ['class' => 'bg-light p-2']);
    }
    if (!empty($row->response)) {
        $pre .= html_writer::tag('strong', get_string('log_response', 'local_imisbridge'));
        $pre .= html_writer::tag('pre', s($row->response), ['class' => 'bg-light p-2']);
    }
    if ($pre !== '') {
        $detail .= html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('log_viewpayload', 'local_imisbridge')) . $pre
        );
    }

    $table->data[] = [
        userdate($row->timecreated),
        $usercell,
        s($row->operation),
        $row->imisid !== null ? s($row->imisid) : '-',
        $row->productcode !== null ? s($row->productcode) : '-',
        $statuscell,
        $detail !== '' ? $detail : '-',
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
echo $OUTPUT->footer();
