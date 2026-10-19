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
 * Tests for the local_imisbridge SOAP client.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Unit tests for {@see imis_client}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\imis_client
 */
final class imis_client_test extends \advanced_testcase {
    /**
     * Reset the database between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Without an endpoint, no SOAP `location` is set (the WSDL address is used).
     *
     * @return void
     */
    public function test_build_soap_options_without_endpoint(): void {
        $options = imis_client::build_soap_options(30, null);

        $this->assertArrayNotHasKey('location', $options);
        $this->assertSame(30, $options['connection_timeout']);
        $this->assertSame(WSDL_CACHE_DISK, $options['cache_wsdl']);
        $this->assertTrue($options['exceptions']);
    }

    /**
     * An empty endpoint string is treated as no override.
     *
     * @return void
     */
    public function test_build_soap_options_empty_endpoint(): void {
        $options = imis_client::build_soap_options(30, '');

        $this->assertArrayNotHasKey('location', $options);
    }

    /**
     * A non-empty endpoint is passed through as the SOAP `location` override.
     *
     * @return void
     */
    public function test_build_soap_options_with_endpoint(): void {
        $endpoint = 'https://sccatest.atsservices.net/wsmoodle.asmx';
        $options  = imis_client::build_soap_options(15, $endpoint);

        $this->assertArrayHasKey('location', $options);
        $this->assertSame($endpoint, $options['location']);
        $this->assertSame(15, $options['connection_timeout']);
    }

    /**
     * get_effective_endpoint returns null when the override is not configured.
     *
     * @return void
     */
    public function test_effective_endpoint_null_when_unset(): void {
        $this->assertNull(imis_client::get_effective_endpoint());
    }

    /**
     * get_effective_endpoint returns the configured override when set.
     *
     * @return void
     */
    public function test_effective_endpoint_returns_override(): void {
        $endpoint = 'https://sccatest.atsservices.net/wsmoodle.asmx';
        set_config('service_endpoint', $endpoint, 'local_imisbridge');

        $this->assertSame($endpoint, imis_client::get_effective_endpoint());
    }
}
