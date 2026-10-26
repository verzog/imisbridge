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
 * Shared helpers for local_imisbridge.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Small utility helpers.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Whether a sync toggle is enabled.
     *
     * Every toggle defaults to on: an unset value (e.g. immediately after an
     * upgrade, before defaults are written) is treated as enabled so existing
     * behaviour is preserved. Only an explicit '0' disables the operation.
     *
     * @param string $name The plugin config name of the checkbox setting.
     * @return bool
     */
    public static function is_enabled(string $name): bool {
        $value = get_config('local_imisbridge', $name);
        return ($value === false) ? true : (bool)$value;
    }

    /**
     * Whether an opt-in (default-off) toggle is explicitly enabled.
     *
     * Unlike {@see is_enabled()}, an unset value is treated as **off**. Use this for
     * toggles that default to disabled, so a fresh install does not enable them
     * before their default has been written.
     *
     * @param string $name The plugin config name of the checkbox setting.
     * @return bool
     */
    public static function is_enabled_optin(string $name): bool {
        return (bool)get_config('local_imisbridge', $name);
    }

    /**
     * The activity types (module names) whose grades are pushed to iMIS as score
     * notes, from the `push_score_modules` checklist. Empty (the default) means none.
     *
     * Quiz is never included: quiz scores have their own push on submission
     * (`push_quiz_scores`), so listing it here would send each quiz score twice.
     *
     * @return string[] Module names, e.g. ['assign', 'casestudy'].
     */
    public static function score_push_modules(): array {
        $value = (string)get_config('local_imisbridge', 'push_score_modules');
        $modules = array_filter(array_map('trim', explode(',', $value)), static fn($m) => $m !== '');
        return array_values(array_diff($modules, ['quiz']));
    }

    /**
     * The gradable activity types installed on this site, for the score-push
     * checklist: module name => localised name.
     *
     * Built from each module's own declaration (`<modname>_supports()` returning true
     * for FEATURE_GRADE_HAS_GRADE), the same way Moodle builds its lists of gradable
     * activities — so any gradable activity, core or third-party, appears with no
     * special-casing here. Quiz is left out (see {@see score_push_modules()}).
     *
     * @return array<string,string>
     */
    public static function gradable_activity_choices(): array {
        $choices = [];
        foreach (array_keys(\core_component::get_plugin_list('mod')) as $modname) {
            if ($modname === 'quiz') {
                continue;
            }
            if (!plugin_supports('mod', $modname, FEATURE_GRADE_HAS_GRADE, false)) {
                continue;
            }
            $choices[$modname] = get_string('pluginname', 'mod_' . $modname);
        }
        \core_collator::asort($choices);
        return $choices;
    }

    /**
     * The course number a push sends to iMIS for a Moodle course: the numeric course
     * id, or its replacement from the *Push course id overrides* setting
     * (`moodleid=imisid` lines). The override lets a staging course report itself as
     * the course id iMIS already holds results for, e.g. 19=2152.
     *
     * @param int $courseid The Moodle course id.
     * @return string The course number to send.
     */
    public static function push_course_number(int $courseid): string {
        $overrides = self::parse_push_course_overrides((string)get_config('local_imisbridge', 'push_course_overrides'));
        return (string)($overrides[$courseid] ?? $courseid);
    }

    /**
     * Parse the push course id overrides: one `moodleid=imisid` per line, both
     * positive whole numbers. Blank lines, lines starting with # and anything else
     * malformed are ignored.
     *
     * @param string $text The setting value.
     * @return array<int,int> Moodle course id => course id sent to iMIS.
     */
    public static function parse_push_course_overrides(string $text): array {
        $overrides = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if (ctype_digit($from) && ctype_digit($to) && (int)$from > 0 && (int)$to > 0) {
                $overrides[(int)$from] = (int)$to;
            }
        }
        return $overrides;
    }

    /**
     * The Moodle user field configured as the source of the iMIS contact ID.
     *
     * Defaults to 'username' so existing installations keep their behaviour.
     * Only 'username', 'idnumber' and 'email' are valid; anything else (including
     * an unset value) falls back to 'username'.
     *
     * @return string One of 'username', 'idnumber' or 'email'.
     */
    public static function imis_id_source(): string {
        $source = get_config('local_imisbridge', 'imis_id_source');
        $allowed = ['username', 'idnumber', 'email'];
        return in_array($source, $allowed, true) ? $source : 'username';
    }

    /**
     * Resolve the iMIS contact ID for a user from the configured source field.
     *
     * A value that does not match the *iMIS ID format* setting counts as no ID, so
     * accounts such as admin or anonymised test users are never sent to iMIS.
     *
     * @param \stdClass $user A user record carrying at least the configured field.
     * @return string The iMIS contact ID, or '' when the field is empty, absent or not in the iMIS ID format.
     */
    public static function get_imis_id(\stdClass $user): string {
        $field = self::imis_id_source();
        $value = isset($user->{$field}) ? trim((string)$user->{$field}) : '';
        return self::is_valid_imis_id($value) ? $value : '';
    }

    /**
     * Whether a value is in the iMIS ID format: any non-empty value, or digits only
     * when the *iMIS ID format* setting says so. The digits rule applies to the
     * username and ID-number sources only, as an email address is never digits.
     *
     * @param string $value The candidate iMIS ID.
     * @return bool
     */
    public static function is_valid_imis_id(string $value): bool {
        if ($value === '') {
            return false;
        }
        if (get_config('local_imisbridge', 'imis_id_format') === 'digits' && self::imis_id_source() !== 'email') {
            return ctype_digit($value);
        }
        return true;
    }

    /**
     * Find the Moodle user whose configured iMIS-ID field matches the given id.
     *
     * The reverse of {@see get_imis_id()}: group sync receives an iMIS contact id
     * and must resolve it to a Moodle account. The lookup uses the same source
     * field as the rest of the plugin ('username', 'idnumber' or 'email'), so a
     * site that maps iMIS ids onto idnumber (not username) still resolves. An
     * empty id, no match, or an ambiguous match (more than one active user with
     * that value) all return null rather than guessing. Usernames are only unique per
     * MNet host, so a username match is limited to local accounts.
     *
     * @param string $imisid The iMIS contact id to resolve.
     * @return \stdClass|null The single matching active user, or null.
     */
    public static function find_user_by_imis_id(string $imisid): ?\stdClass {
        global $CFG, $DB;

        $imisid = trim($imisid);
        if ($imisid === '') {
            return null;
        }

        $field  = self::imis_id_source();
        $params = [$field => $imisid, 'deleted' => 0];
        if ($field === 'username') {
            $params['mnethostid'] = $CFG->mnet_localhost_id;
        }
        $matches = $DB->get_records('user', $params, '', '*', 0, 2);
        if (count($matches) !== 1) {
            return null;
        }

        return reset($matches);
    }

    /**
     * Whether the plugin has the minimum configuration to talk to the external system.
     *
     * Delegates to the active provider's static check, so automatic writes skip
     * cleanly (rather than throwing in cron) until that provider is configured.
     * The check must not construct a client — each provider's constructor requires
     * live connection settings and throws without them.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $class = provider\manager::active_provider_class();
        return $class::is_configured();
    }
}
