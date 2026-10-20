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
 * Tests for the provider registry and capability resolution.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

use local_imisbridge\provider\manager;
use local_imisbridge\provider\provider_interface;
use local_imisbridge\provider\org_sync_provider;

/**
 * Unit tests for {@see manager} and the provider capability contract.
 *
 * These assert class-level facts (static label/supports/is_configured, interface
 * membership) without constructing a client, since the SOAP client requires a
 * live WSDL to build.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\provider\manager
 */
final class provider_manager_test extends \advanced_testcase {
    /**
     * Reset configuration between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The SOAP bridge is the default provider; 'rest' selects the REST client.
     *
     * @return void
     */
    public function test_active_provider_class(): void {
        $this->assertSame('soap', manager::active_key());
        $this->assertSame(imis_client::class, manager::active_provider_class());

        set_config('transport', 'rest', 'local_imisbridge');
        $this->assertSame('rest', manager::active_key());
        $this->assertSame(rest_client::class, manager::active_provider_class());

        // An unrecognised value falls back to the SOAP bridge.
        set_config('transport', 'bogus', 'local_imisbridge');
        $this->assertSame(imis_client::class, manager::active_provider_class());
    }

    /**
     * The menu lists every registered provider with a non-empty label.
     *
     * @return void
     */
    public function test_menu_lists_providers(): void {
        $menu = manager::menu();
        $this->assertSame(['soap', 'rest'], array_keys($menu));
        foreach ($menu as $label) {
            $this->assertNotEmpty($label);
        }
    }

    /**
     * Capabilities differ by provider: only the SOAP bridge offers order sync.
     *
     * @return void
     */
    public function test_supports(): void {
        $this->assertTrue(imis_client::supports(provider_interface::CAP_PUSH));
        $this->assertTrue(imis_client::supports(provider_interface::CAP_ORG_SYNC));

        $this->assertTrue(rest_client::supports(provider_interface::CAP_PUSH));
        $this->assertFalse(rest_client::supports(provider_interface::CAP_ORG_SYNC));

        $this->assertFalse(imis_client::supports('bogus'));
    }

    /**
     * Only the SOAP bridge implements the inbound-sync contract.
     *
     * @return void
     */
    public function test_org_sync_contract(): void {
        $this->assertTrue(is_a(imis_client::class, org_sync_provider::class, true));
        $this->assertFalse(is_a(rest_client::class, org_sync_provider::class, true));
    }

    /**
     * org_sync_instance returns null for a provider that cannot sync (no construction).
     *
     * @return void
     */
    public function test_org_sync_instance_null_for_rest(): void {
        set_config('transport', 'rest', 'local_imisbridge');
        set_config('rest_base_url', 'https://example.test', 'local_imisbridge');
        $this->assertNull(manager::org_sync_instance());
    }

    /**
     * util::is_configured delegates to the active provider's own required setting.
     *
     * @return void
     */
    public function test_is_configured_delegates(): void {
        // Default provider is SOAP, which needs a WSDL URL.
        $this->assertFalse(util::is_configured());
        set_config('wsdl_url', 'https://example.test/wsmoodle.asmx?WSDL', 'local_imisbridge');
        $this->assertTrue(util::is_configured());

        // Switching to REST changes which setting is required.
        set_config('transport', 'rest', 'local_imisbridge');
        $this->assertFalse(util::is_configured());
        set_config('rest_base_url', 'https://example.test', 'local_imisbridge');
        $this->assertTrue(util::is_configured());
    }

    /**
     * Each provider advertises a non-empty display label.
     *
     * @return void
     */
    public function test_labels(): void {
        $this->assertNotEmpty(imis_client::label());
        $this->assertNotEmpty(rest_client::label());
    }
}
