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
 * One-off carry-over from the plugin's former component name.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Carries a site over from local_imisbridge, the plugin's name before 2.0.0.
 *
 * Moodle sees a renamed component as a new plugin, so on install this copies what
 * the old one held: its settings, audit log and sent-score records (with their ids,
 * so a score already sent is not sent again), the roles' grants of its
 * capabilities, the tutorial-group memberships it manages, its queued pushes, and
 * its cancellation function on any hand-built web service. It then switches the
 * old plugin's writes off, so the two never both write while the old one awaits
 * uninstalling. Nothing of the old plugin is deleted: uninstalling it is a separate,
 * deliberate step. Everything is skipped when local_imisbridge was never installed.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migration {
    /** @var string The former component name. */
    public const OLD = 'local_imisbridge';

    /** @var string The current component name. */
    public const NEW = 'local_membershipbridge';

    /** @var string[] Tables carried over, by their name without the component prefix. */
    private const TABLES = ['log', 'scorepush'];

    /** @var array The old plugin's write settings, each with the value that turns it off. */
    private const OLD_WRITES_OFF = [
        'push_completions'            => '0',
        'push_quiz_scores'            => '0',
        'push_module_completions'     => '0',
        'push_score_modules'          => '',
        'sync_enrolments_on_login'    => '0',
        'sync_cancellations_on_login' => '0',
        'sync_groups_on_login'        => '0',
        'task_enrolments_enabled'     => '0',
        'task_cancellations_enabled'  => '0',
        'task_groups_enabled'         => '0',
        'tutorial_sync_enabled'       => '0',
    ];

    /**
     * Carry the site over from local_imisbridge, if it was ever installed.
     *
     * @return array<string,int> What was carried over: settings, rows per table,
     *     capabilities, group memberships, tasks and service functions.
     */
    public static function from_imisbridge(): array {
        global $DB;

        $report = [];
        if (!$DB->record_exists('config_plugins', ['plugin' => self::OLD, 'name' => 'version'])) {
            return $report;
        }

        $report['settings'] = self::copy_settings();
        foreach (self::TABLES as $suffix) {
            $report[$suffix] = self::copy_table(self::OLD . '_' . $suffix, self::NEW . '_' . $suffix);
        }
        $report['capabilities']  = self::copy_capabilities();
        $report['groupmembers']  = self::move_group_memberships();
        $report['adhoctasks']    = self::move_adhoc_tasks();
        $report['servicefuncs']  = self::add_functions_to_services();
        self::switch_off_old_writes();
        return $report;
    }

    /**
     * Copy every setting the old plugin holds that this one does not yet have.
     *
     * @return int The number of settings copied.
     */
    private static function copy_settings(): int {
        global $DB;

        $copied = 0;
        foreach ($DB->get_records('config_plugins', ['plugin' => self::OLD]) as $row) {
            if ($row->name === 'version' || get_config(self::NEW, $row->name) !== false) {
                continue;
            }
            set_config($row->name, $row->value, self::NEW);
            $copied++;
        }
        return $copied;
    }

    /**
     * Copy an old table's rows into its new table, keeping their ids.
     *
     * @param string $from The old table.
     * @param string $to   The new table (empty, just installed).
     * @return int The number of rows copied.
     */
    private static function copy_table(string $from, string $to): int {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists($from)) {
            return 0;
        }

        $copied = 0;
        $rows = $DB->get_recordset($from, null, 'id');
        foreach ($rows as $row) {
            $DB->import_record($to, $row);
            $copied++;
        }
        $rows->close();
        if ($copied) {
            $dbman->reset_sequence(new \xmldb_table($to));
        }
        return $copied;
    }

    /**
     * Give each role, in each context, the same permission on the new capabilities
     * as it had on the old ones (e.g. the inbound service account's
     * local/imisbridge:cancelenrolment).
     *
     * @return int The number of grants copied.
     */
    private static function copy_capabilities(): int {
        global $DB;

        $oldprefix = 'local/imisbridge:';
        $newprefix = 'local/membershipbridge:';
        $copied = 0;
        $grants = $DB->get_records_select(
            'role_capabilities',
            $DB->sql_like('capability', ':prefix'),
            ['prefix' => $DB->sql_like_escape($oldprefix) . '%']
        );
        foreach ($grants as $grant) {
            $capability = $newprefix . substr($grant->capability, strlen($oldprefix));
            $existing = [
                'roleid'     => $grant->roleid,
                'contextid'  => $grant->contextid,
                'capability' => $capability,
            ];
            if ($DB->record_exists('role_capabilities', $existing)) {
                continue;
            }
            unset($grant->id);
            $grant->capability = $capability;
            $DB->insert_record('role_capabilities', $grant);
            $copied++;
        }
        if ($copied) {
            accesslib_clear_all_caches(false);
        }
        return $copied;
    }

    /**
     * Hand the tutorial-group memberships the old plugin created to this one, so its
     * tutorial group sync keeps managing (and can remove) them.
     *
     * @return int The number of memberships moved.
     */
    private static function move_group_memberships(): int {
        global $DB;

        $count = $DB->count_records('groups_members', ['component' => self::OLD]);
        if ($count) {
            $DB->set_field('groups_members', 'component', self::NEW, ['component' => self::OLD]);
        }
        return $count;
    }

    /**
     * Point pushes the old plugin queued but had not yet sent at this plugin's tasks,
     * so they are still sent once.
     *
     * @return int The number of queued tasks moved.
     */
    private static function move_adhoc_tasks(): int {
        global $DB;

        $moved = 0;
        foreach ($DB->get_records('task_adhoc', ['component' => self::OLD]) as $task) {
            $task->component = self::NEW;
            $task->classname = str_replace(self::OLD . '\\', self::NEW . '\\', $task->classname);
            $DB->update_record('task_adhoc', $task);
            $moved++;
        }
        return $moved;
    }

    /**
     * Add this plugin's web-service functions to every hand-built service that holds
     * the old ones (such as the ATS inbound service), so its token keeps working once
     * the ATS mapping names the new function. The old plugin's own service is left
     * alone: uninstalling it removes that service.
     *
     * @return int The number of service functions added.
     */
    private static function add_functions_to_services(): int {
        global $DB;

        $added = 0;
        $sql = "SELECT sf.id, sf.externalserviceid, sf.functionname
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE " . $DB->sql_like('sf.functionname', ':prefix') . "
                   AND (s.component IS NULL OR s.component <> :oldcomponent)";
        $params = ['prefix' => $DB->sql_like_escape(self::OLD . '_') . '%', 'oldcomponent' => self::OLD];
        foreach ($DB->get_records_sql($sql, $params) as $link) {
            $function = self::NEW . substr($link->functionname, strlen(self::OLD));
            $record = ['externalserviceid' => $link->externalserviceid, 'functionname' => $function];
            if (!$DB->record_exists('external_services_functions', $record)) {
                $DB->insert_record('external_services_functions', (object)$record);
                $added++;
            }
        }
        return $added;
    }

    /**
     * Turn the old plugin's pushes and syncs off, so if its code is still on disk the
     * two plugins never both write.
     *
     * @return void
     */
    private static function switch_off_old_writes(): void {
        foreach (self::OLD_WRITES_OFF as $name => $off) {
            set_config($name, $off, self::OLD);
        }
    }
}
