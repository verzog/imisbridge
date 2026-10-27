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
 * Registry and factory for external records-system providers.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\provider;

/**
 * Resolves the active provider and lists the providers available to choose from.
 *
 * To add support for another external system: write a class implementing
 * {@see provider_interface} (or {@see org_sync_provider}) and add it to
 * {@see providers()} keyed by the value stored in the connection setting.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * The available providers: connection-setting value => provider class name.
     *
     * @return array
     */
    public static function providers(): array {
        return [
            'soap' => \local_membershipbridge\imis_client::class,
            'rest' => \local_membershipbridge\rest_client::class,
        ];
    }

    /**
     * The connection-setting value of the active provider.
     *
     * Resolved against the registry so any registered provider's key is honoured;
     * an unset or unregistered value falls back to the SOAP bridge (the default).
     *
     * @return string
     */
    public static function active_key(): string {
        $configured = (string)get_config('local_membershipbridge', 'transport');
        return isset(self::providers()[$configured]) ? $configured : 'soap';
    }

    /**
     * The class name of the active provider (no instance is constructed).
     *
     * @return string
     */
    public static function active_provider_class(): string {
        return self::providers()[self::active_key()];
    }

    /**
     * Construct the active provider.
     *
     * The caller is responsible for checking {@see provider_interface::is_configured()}
     * first — the constructor requires live connection settings and throws without them.
     *
     * @return provider_interface
     */
    public static function instance(): provider_interface {
        $class = self::active_provider_class();
        return new $class();
    }

    /**
     * Construct the sync provider ({@see sync_provider_class()}) when it can sync and
     * has the configuration its syncs need.
     *
     * @return org_sync_provider|null The provider, or null when no configured provider can sync.
     */
    public static function org_sync_instance(): ?org_sync_provider {
        $class = self::sync_provider_class();
        if (!is_a($class, org_sync_provider::class, true) || !$class::is_sync_configured()) {
            return null;
        }
        $instance = new $class();
        return ($instance instanceof org_sync_provider) ? $instance : null;
    }

    /**
     * Whether a sync provider is available and configured, without constructing it
     * (constructing the SOAP client fetches the WSDL, which a login must not wait on).
     *
     * @return bool
     */
    public static function sync_configured(): bool {
        $class = self::sync_provider_class();
        return is_a($class, org_sync_provider::class, true) && $class::is_sync_configured();
    }

    /**
     * The provider class that runs the enrolment, cancellation and group syncs.
     *
     * The `sync_provider` setting picks it independently of the push provider; blank
     * (the default) or an unknown value follows the connection provider, which is
     * the behaviour before the setting existed.
     *
     * @return string Fully-qualified class name.
     */
    public static function sync_provider_class(): string {
        $configured = (string)get_config('local_membershipbridge', 'sync_provider');
        return self::providers()[$configured] ?? self::active_provider_class();
    }

    /**
     * The choices for the sync provider setting: follow the connection provider, or
     * any registered provider that can sync.
     *
     * @return array<string,string> Setting value => label.
     */
    public static function sync_menu(): array {
        $menu = ['' => get_string('sync_provider_same', 'local_membershipbridge')];
        foreach (self::providers() as $key => $class) {
            if (is_a($class, org_sync_provider::class, true)) {
                $menu[$key] = $class::label();
            }
        }
        return $menu;
    }

    /**
     * The provider choices for a settings dropdown: setting value => display label.
     *
     * @return array
     */
    public static function menu(): array {
        $menu = [];
        foreach (self::providers() as $key => $class) {
            $menu[$key] = $class::label();
        }
        return $menu;
    }
}
