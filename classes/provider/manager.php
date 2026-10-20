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
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\provider;

/**
 * Resolves the active provider and lists the providers available to choose from.
 *
 * To add support for another external system: write a class implementing
 * {@see provider_interface} (or {@see org_sync_provider}) and add it to
 * {@see providers()} keyed by the value stored in the connection setting.
 *
 * @package    local_imisbridge
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
            'soap' => \local_imisbridge\imis_client::class,
            'rest' => \local_imisbridge\rest_client::class,
        ];
    }

    /**
     * The connection-setting value of the active provider.
     *
     * @return string
     */
    public static function active_key(): string {
        return \local_imisbridge\util::transport();
    }

    /**
     * The class name of the active provider (no instance is constructed).
     *
     * @return string
     */
    public static function active_provider_class(): string {
        $map = self::providers();
        return $map[self::active_key()] ?? \local_imisbridge\imis_client::class;
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
     * Construct the active provider when it supports inbound order/group sync.
     *
     * @return org_sync_provider|null The provider, or null when the active one cannot sync.
     */
    public static function org_sync_instance(): ?org_sync_provider {
        $class = self::active_provider_class();
        if (!is_a($class, org_sync_provider::class, true)) {
            return null;
        }
        $instance = new $class();
        return ($instance instanceof org_sync_provider) ? $instance : null;
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
