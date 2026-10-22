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
     * @param \stdClass $user A user record carrying at least the configured field.
     * @return string The iMIS contact ID, or '' when the field is empty or absent.
     */
    public static function get_imis_id(\stdClass $user): string {
        $field = self::imis_id_source();
        return isset($user->{$field}) ? trim((string)$user->{$field}) : '';
    }

    /**
     * Find the Moodle user whose configured iMIS-ID field matches the given id.
     *
     * The reverse of {@see get_imis_id()}: group sync receives an iMIS contact id
     * and must resolve it to a Moodle account. The lookup uses the same source
     * field as the rest of the plugin ('username', 'idnumber' or 'email'), so a
     * site that maps iMIS ids onto idnumber (not username) still resolves. An
     * empty id, no match, or an ambiguous match (more than one active user with
     * that value) all return null rather than guessing.
     *
     * @param string $imisid The iMIS contact id to resolve.
     * @return \stdClass|null The single matching active user, or null.
     */
    public static function find_user_by_imis_id(string $imisid): ?\stdClass {
        global $DB;

        $imisid = trim($imisid);
        if ($imisid === '') {
            return null;
        }

        $field = self::imis_id_source();
        $matches = $DB->get_records('user', [$field => $imisid, 'deleted' => 0], '', '*', 0, 2);
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
