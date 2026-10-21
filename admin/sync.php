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
$notice     = '';
$testoutput = '';
$syncoutput = '';
$noteoutput = '';
// Build the "member did not resolve to a single Moodle user" warning for the push
// test controls, so a placeholder score (0) is never mistaken for a real grade. The
// message reports the field queried and the match count, so the operator can tell a
// zero match (wrong field/value, or the ID source points at the wrong field) from a
// non-unique one.
$nosingleusernotice = function (string $field, string $member, int $matchcount): string {
    $a = (object)['field' => $field, 'member' => s($member)];
    return get_string($matchcount > 1 ? 'pushnomatchmany' : 'pushnomatchnone', 'local_imisbridge', $a);
};
// Build a plain-text "how the score was resolved" trace for the push test output, so a
// score of 0 can be traced to the member->user match or the gradebook grade (never the
// raw activity attempt) rather than guessed at. Shared by both push tests; $gradelabel
// names the grade read ("activity" or "course-total"). $detail is a *_detail object
// (module_grade_detail/course_score_detail) or null when no user matched. $sentscore is
// the value actually written to the record and $sentdesc describes it (the activity note
// sends the raw grade, the completion sends a percentage). Rendered in a <pre> via s().
$scoretrace = function (
    string $field,
    string $member,
    array $matches,
    int $userid,
    ?\stdClass $detail,
    string $gradelabel,
    float $sentscore,
    string $sentdesc
): string {
    $lines = [
        'score resolution:',
        '  member "' . $member . '" matched ' . count($matches)
            . ' Moodle user(s) on field "' . $field . '" (need exactly 1)',
    ];
    if ($userid === 0) {
        $lines[] = '  -> no single user: score sent as ' . $sentscore;
        return implode(PHP_EOL, $lines);
    }
    $lines[] = '  -> Moodle user id ' . $userid;
    if ($detail === null || $detail->itemid === null) {
        $lines[] = '  no ' . $gradelabel . ' grade item (nothing to grade)';
        return implode(PHP_EOL, $lines);
    }
    $lines[] = '  ' . $gradelabel . ' grade item ' . $detail->itemid . ', grademax ' . $detail->grademax;
    $lines[] = '  gradebook finalgrade '
        . ($detail->hasgrade ? (string)$detail->finalgrade : 'null (no gradebook grade recorded)');
    $lines[] = '  -> score sent ' . $sentscore . ' (' . $sentdesc . ')';
    if (!$detail->hasgrade) {
        $lines[] = '  NOTE: the gradebook grade for this item is empty, so 0 was sent. A mark on an'
            . ' activity attempt does not count until it reaches the gradebook (check the Grader report'
            . ' for this user; a hidden, locked or not-yet-regraded grade reads as empty).';
    }
    return implode(PHP_EOL, $lines);
};
// Set by the "list activities" step to the graded modules the operator can pick from.
$modulechoices = null;
$notemember    = '';
$notecourse    = 0;

// Manual order/group sync and the connection test are offered by providers that
// support inbound sync (the ATS SOAP bridge). A provider that only receives pushed
// records (direct REST) does not, so these controls are gated on that capability.
$providerclass = \local_imisbridge\provider\manager::active_provider_class();
$cansync       = is_a($providerclass, \local_imisbridge\provider\org_sync_provider::class, true);
// Every provider can push records, so the manual "push completion" test applies to all.
$canpush       = $providerclass::supports(\local_imisbridge\provider\provider_interface::CAP_PUSH);

if ($action === 'testconnection' && confirm_sesskey()) {
    // Read-only: verify connectivity without writing any data. The provider's
    // diagnostics send the AuthToken when configured; a profile lookup (contact ID)
    // always exercises it.
    try {
        $provider = \local_imisbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            $error = get_string('providernotsupported', 'local_imisbridge', $providerclass::label());
        } else {
            $testoutput = $provider->diagnostics(!empty($contactid) ? $contactid : null);
        }
    } catch (\Throwable $e) {
        $error = get_string('syncfailed', 'local_imisbridge', $e->getMessage());
    }
} else if ($action === 'pushcompletion' && confirm_sesskey()) {
    // Manual test: push one completion record (MoodleUpdate) for a member and course
    // on demand, so the write path can be exercised without completing a course and
    // waiting for cron. Writes a real record to the active external system.
    global $DB;
    $pushmember = trim(optional_param('pushmemberid', '', PARAM_TEXT));
    $pushcourse = optional_param('pushcourseid', 0, PARAM_INT);
    try {
        if (!$canpush) {
            $error = get_string('pushnotsupported', 'local_imisbridge');
        } else if (!\local_imisbridge\util::is_enabled('push_completions')) {
            // Honour the same kill switch the observer and push task obey.
            $error = get_string('pushdisabled', 'local_imisbridge');
        } else if ($pushmember === '' || $pushcourse <= 0) {
            $error = get_string('pushmissingfields', 'local_imisbridge');
        } else if (!\local_imisbridge\util::is_configured()) {
            $error = get_string('notconfigured', 'local_imisbridge', $providerclass::label());
        } else {
            $course = $DB->get_record('course', ['id' => $pushcourse], 'id');
            if (!$course) {
                $error = get_string('pushnocourse', 'local_imisbridge', $pushcourse);
            } else {
                // Derive the score from the matching Moodle user's grade, but only when
                // the member ID maps to exactly one user: the id field can be non-unique
                // (idnumber, email, or usernames across MNet hosts), and an arbitrary
                // match could attach the wrong grade. No/ambiguous match sends score 0.
                $field   = \local_imisbridge\util::imis_id_source();
                $matches = $DB->get_records('user', [$field => $pushmember], '', 'id', 0, 2);
                $userid  = (count($matches) === 1) ? (int)reset($matches)->id : 0;
                if ($userid === 0) {
                    // No single user behind the member: the score is a placeholder 0.
                    $notice = $nosingleusernotice($field, $pushmember, count($matches));
                }

                $data = \local_imisbridge\observer::completion_push_data($pushmember, $course, $userid);
                \local_imisbridge\provider\manager::instance()->push_completion($data);

                $message    = get_string('pushdone', 'local_imisbridge', s($pushmember));
                $coursedetail = ($userid > 0)
                    ? \local_imisbridge\observer::course_score_detail((int)$course->id, $userid)
                    : null;
                $trace = $scoretrace(
                    $field,
                    $pushmember,
                    $matches,
                    $userid,
                    $coursedetail,
                    'course-total',
                    (float)$data->score,
                    'percent of course total'
                );
                $syncoutput = $trace . PHP_EOL
                    . 'push_completion:' . PHP_EOL . var_export($data, true) . PHP_EOL . PHP_EOL
                    . get_string('pushaudithint', 'local_imisbridge');
            }
        }
    } catch (\Throwable $e) {
        $error = get_string('syncfailed', 'local_imisbridge', $e->getMessage());
    }
} else if ($action === 'listmodules' && confirm_sesskey()) {
    // Read-only: list the course's graded activities so the operator can pick one
    // to push a MOODLENOTE for. Local data only - nothing is sent to iMIS here.
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $notemember = trim(optional_param('pushmemberid', '', PARAM_TEXT));
    $notecourse = optional_param('pushcourseid', 0, PARAM_INT);
    if ($notemember === '' || $notecourse <= 0) {
        $error = get_string('pushmissingfields', 'local_imisbridge');
    } else {
        $course = $DB->get_record('course', ['id' => $notecourse]);
        if (!$course) {
            $error = get_string('pushnocourse', 'local_imisbridge', $notecourse);
        } else {
            $modulechoices = [];
            foreach (get_fast_modinfo($course)->get_cms() as $cm) {
                $gradeitem = \grade_item::fetch([
                    'itemtype'     => 'mod',
                    'itemmodule'   => $cm->modname,
                    'iteminstance' => $cm->instance,
                ]);
                if ($gradeitem) {
                    $modulechoices[(int)$cm->id] = format_string($cm->name) . ' (' . $cm->modname . ')';
                }
            }
            if (empty($modulechoices)) {
                $error = get_string('pushnotenoactivities', 'local_imisbridge');
                $modulechoices = null;
            }
        }
    }
} else if ($action === 'pushnote' && confirm_sesskey()) {
    // Manual test: push one module-completion MOODLENOTE (createActivity) for a
    // member and a chosen course module. Writes a real record to the active system.
    global $DB;
    $pushmember = trim(optional_param('pushmemberid', '', PARAM_TEXT));
    $pushcmid   = optional_param('pushcmid', 0, PARAM_INT);
    try {
        if (!$canpush) {
            $error = get_string('pushnotsupported', 'local_imisbridge');
        } else if (!\local_imisbridge\util::is_enabled_optin('push_module_completions')) {
            // Honour the same opt-in toggle the module-completion observer obeys.
            $error = get_string('pushnotedisabled', 'local_imisbridge');
        } else if ($pushmember === '' || $pushcmid <= 0) {
            $error = get_string('pushmissingfields', 'local_imisbridge');
        } else if (!\local_imisbridge\util::is_configured()) {
            $error = get_string('notconfigured', 'local_imisbridge', $providerclass::label());
        } else {
            $cm = get_coursemodule_from_id('', $pushcmid);
            if (!$cm) {
                $error = get_string('pushnotenomodule', 'local_imisbridge', $pushcmid);
            } else {
                // Resolve the member to a single Moodle user for the real status/score,
                // the same way the completion test does; no/ambiguous match sends 0.
                $field   = \local_imisbridge\util::imis_id_source();
                $matches = $DB->get_records('user', [$field => $pushmember], '', 'id', 0, 2);
                $userid  = (count($matches) === 1) ? (int)reset($matches)->id : 0;
                if ($userid === 0) {
                    // No single user behind the member: the status and score are placeholders.
                    $notice = $nosingleusernotice($field, $pushmember, count($matches));
                }

                $data = \local_imisbridge\observer::module_note_data($pushmember, $cm, $userid);
                if ($data === null) {
                    // The module lost its grade item since it was listed (or pushcmid
                    // was posted directly): ungraded activities are never sent.
                    $error = get_string('pushnotenotgraded', 'local_imisbridge');
                } else {
                    \local_imisbridge\provider\manager::instance()->push_module_note($data);

                    $message    = get_string('pushnotedone', 'local_imisbridge', s($pushmember));
                    $notedetail = ($userid > 0) ? \local_imisbridge\observer::module_grade_detail($cm, $userid) : null;
                    $trace = $scoretrace(
                        $field,
                        $pushmember,
                        $matches,
                        $userid,
                        $notedetail,
                        'activity',
                        (float)$data->score,
                        'raw gradebook grade'
                    );
                    $noteoutput = $trace . PHP_EOL
                        . 'push_module_note:' . PHP_EOL . var_export($data, true) . PHP_EOL . PHP_EOL
                        . get_string('pushaudithint', 'local_imisbridge');
                }
            }
        }
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
        $provider = \local_imisbridge\provider\manager::org_sync_instance();
        if ($provider === null) {
            $error = get_string('providernotsupported', 'local_imisbridge', $providerclass::label());
        } else {
            $scope = $cid
                ? get_string('forcontact', 'local_imisbridge', s($contactid))
                : get_string('allusers', 'local_imisbridge');

            // Collect each call's raw result so a manual sync of a few records can be
            // eyeballed on this page without opening the audit log.
            $results = [];
            switch ($action) {
                case 'enrollments':
                    $results['SendNewOrdersToMoodle'] = $provider->sync_orders($cid);
                    break;

                case 'cancellations':
                    $results['SendCancelledOrdersToMoodle'] = $provider->sync_cancelled_orders($cid);
                    break;

                case 'groups':
                    $results['UpdateMoodleGroups'] = $provider->update_groups($cid);
                    break;

                case 'all':
                    $results['SendNewOrdersToMoodle']       = $provider->sync_orders($cid);
                    $results['SendCancelledOrdersToMoodle'] = $provider->sync_cancelled_orders($cid);
                    $results['UpdateMoodleGroups']          = $provider->update_groups($cid);
                    break;
            }

            $message = get_string('syncdone', 'local_imisbridge', $scope);

            $out = '';
            foreach ($results as $op => $res) {
                $out .= $op . ':' . PHP_EOL . var_export($res, true) . PHP_EOL . PHP_EOL;
            }
            $syncoutput = trim($out);
        }
    } catch (\Exception $e) {
        $error = get_string('syncfailed', 'local_imisbridge', $e->getMessage());
    }
}

echo $OUTPUT->header();

if ($message) {
    echo $OUTPUT->notification($message, 'notifysuccess');
}
if ($notice) {
    // A record was still sent, but with placeholder status/score: warn, don't block.
    echo $OUTPUT->notification($notice, 'notifywarning');
}
if ($error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

$syncurl  = new moodle_url('/local/imisbridge/admin/sync.php');
$auditurl = new moodle_url('/local/imisbridge/admin/log.php');
$logsurl  = new moodle_url('/admin/tasklogs.php');
$sesskey  = sesskey();
$cidvalue = s($contactid);
// The push-completion form keeps its own member-ID value, independent of the
// order-sync contact filter above, so the two fields don't mirror each other.
$pushmembervalue = s(optional_param('pushmemberid', '', PARAM_TEXT));

if ($cansync) {
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
} else {
    // The active provider receives pushed records but has no manual order/group
    // sync or connection test; say so instead of showing controls that would fail.
    echo $OUTPUT->notification(
        get_string('providernotsupported', 'local_imisbridge', $providerclass::label()),
        'notifymessage'
    );
}

if ($canpush) {
    // On-demand completion push: fire one MoodleUpdate for a member and course so the
    // write path can be exercised without completing a course and waiting for cron.
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('pushtest', 'local_imisbridge'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('pushcompletion_help', 'local_imisbridge'), ['class' => 'text-muted']);
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="sesskey" value="' . $sesskey . '">';
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('pushmemberid', 'local_imisbridge'), ['for' => 'pushmemberid']);
    echo '<input type="text" id="pushmemberid" name="pushmemberid" class="form-control"'
        . ' value="' . $pushmembervalue . '" placeholder="' . s(get_string('contactidplaceholder', 'local_imisbridge')) . '">';
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('pushcourseid', 'local_imisbridge'), ['for' => 'pushcourseid']);
    echo '<input type="number" id="pushcourseid" name="pushcourseid" class="form-control" min="1">';
    echo html_writer::tag('small', get_string('pushcourseidhelp', 'local_imisbridge'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();
    echo html_writer::start_div('mt-3');
    echo '<button type="submit" name="action" value="pushcompletion" class="btn btn-primary">'
        . s(get_string('pushcompletion', 'local_imisbridge')) . '</button>';
    echo html_writer::end_div();
    echo '</form>';
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($canpush) {
    // On-demand module-note push: pick a real graded activity in a course and fire
    // one createActivity MOODLENOTE, so the supplementary-note path can be verified
    // without a live quiz submission and completion event.
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('pushnotetest', 'local_imisbridge'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('pushnotetest_help', 'local_imisbridge'), ['class' => 'text-muted']);

    // Step 1: member + course, then list the course's graded activities.
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="sesskey" value="' . $sesskey . '">';
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('pushmemberid', 'local_imisbridge'), ['for' => 'notememberid']);
    echo '<input type="text" id="notememberid" name="pushmemberid" class="form-control"'
        . ' value="' . s($notemember) . '" placeholder="' . s(get_string('contactidplaceholder', 'local_imisbridge')) . '">';
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('pushcourseid', 'local_imisbridge'), ['for' => 'notecourseid']);
    echo '<input type="number" id="notecourseid" name="pushcourseid" class="form-control" min="1"'
        . ' value="' . ($notecourse > 0 ? (int)$notecourse : '') . '">';
    echo html_writer::tag('small', get_string('pushcourseidhelp', 'local_imisbridge'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();
    echo html_writer::start_div('mt-3');
    echo '<button type="submit" name="action" value="listmodules" class="btn btn-outline-primary">'
        . s(get_string('pushnotelist', 'local_imisbridge')) . '</button>';
    echo html_writer::end_div();
    echo '</form>';

    // Step 2: pick one of the listed graded activities and push its note.
    if (!empty($modulechoices)) {
        echo html_writer::empty_tag('hr');
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="sesskey" value="' . $sesskey . '">';
        echo '<input type="hidden" name="pushmemberid" value="' . s($notemember) . '">';
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('pushnoteactivity', 'local_imisbridge'), ['for' => 'pushcmid']);
        echo html_writer::select(
            $modulechoices,
            'pushcmid',
            '',
            ['' => get_string('choosedots')],
            ['id' => 'pushcmid', 'class' => 'custom-select']
        );
        echo html_writer::end_div();
        echo html_writer::start_div('mt-3');
        echo '<button type="submit" name="action" value="pushnote" class="btn btn-primary">'
            . s(get_string('pushnote', 'local_imisbridge')) . '</button>';
        echo html_writer::end_div();
        echo '</form>';
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

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

if ($noteoutput !== '') {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('pushnotetest', 'local_imisbridge'), ['class' => 'card-title']);
    echo html_writer::tag('p', get_string('syncresulthelp', 'local_imisbridge'), ['class' => 'text-muted']);
    echo html_writer::tag('pre', s($noteoutput), ['class' => 'bg-light p-3']);
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
