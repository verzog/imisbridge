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
        $endpoint = 'https://ats-sandbox.example.test/wsmoodle.asmx';
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
        $endpoint = 'https://ats-sandbox.example.test/wsmoodle.asmx';
        set_config('service_endpoint', $endpoint, 'local_imisbridge');

        $this->assertSame($endpoint, imis_client::get_effective_endpoint());
    }

    /**
     * The WSDL service address is extracted from a soap:address element.
     *
     * @return void
     */
    public function test_extract_wsdl_endpoint_soap(): void {
        $xml = '<wsdl:service><wsdl:port>'
            . '<soap:address location="https://ats-prod.example.test/wsmoodle.asmx" />'
            . '</wsdl:port></wsdl:service>';

        $this->assertSame(
            'https://ats-prod.example.test/wsmoodle.asmx',
            imis_client::extract_wsdl_endpoint($xml)
        );
    }

    /**
     * The address is also extracted when only a soap12:address is present.
     *
     * @return void
     */
    public function test_extract_wsdl_endpoint_soap12(): void {
        $xml = '<wsdl:port><soap12:address location="https://host/wsmoodle.asmx"/></wsdl:port>';

        $this->assertSame('https://host/wsmoodle.asmx', imis_client::extract_wsdl_endpoint($xml));
    }

    /**
     * A document with no service address returns null.
     *
     * @return void
     */
    public function test_extract_wsdl_endpoint_none(): void {
        $this->assertNull(imis_client::extract_wsdl_endpoint('<wsdl:definitions></wsdl:definitions>'));
    }

    /**
     * A result with no ResultsBase is treated as a (transport-level) success.
     *
     * @return void
     */
    public function test_result_error_no_resultsbase(): void {
        $result = json_decode(json_encode(['MoodleGetUserProfileResult' => ['ID' => '123']]));
        $this->assertNull(imis_client::result_error($result));
        $this->assertNull(imis_client::result_error(null));
    }

    /**
     * A populated Exception->Message (ATS's object shape) is a failure.
     *
     * @return void
     */
    public function test_result_error_exception(): void {
        $result = json_decode(json_encode([
            'ResultsBase' => [
                'ResultCode'    => '5',
                'ResultMessage' => 'rejected',
                'Exception'     => ['Message' => 'Bad productId', 'Source' => 'ATS'],
            ],
        ]));
        $this->assertSame('Bad productId', imis_client::result_error($result));
    }

    /**
     * A scalar Exception string is also treated as a failure (defensive).
     *
     * @return void
     */
    public function test_result_error_scalar_exception(): void {
        $result = json_decode(json_encode(['ResultsBase' => ['Exception' => 'plain error']]));
        $this->assertSame('plain error', imis_client::result_error($result));
    }

    /**
     * The real success shape (ResultCode 0, an all-empty Exception object) is a success.
     *
     * @return void
     */
    public function test_result_error_empty_exception_no_codes(): void {
        $result = json_decode(json_encode([
            'ResultsBase' => [
                'ResultCode'    => '0',
                'ResultMessage' => '',
                'Exception'     => ['Message' => '', 'Source' => '', 'StackTrace' => ''],
            ],
        ]));
        $this->assertNull(imis_client::result_error($result));
    }

    /**
     * With success codes configured, an unexpected ResultCode is a failure.
     *
     * @return void
     */
    public function test_result_error_resultcode_strict(): void {
        set_config('soap_success_result_codes', '0', 'local_imisbridge');

        $ok = json_decode(json_encode(['ResultsBase' => ['ResultCode' => '0', 'Exception' => '']]));
        $this->assertNull(imis_client::result_error($ok));

        $bad = json_decode(json_encode(['ResultsBase' => ['ResultCode' => '5', 'ResultMessage' => 'rejected']]));
        $this->assertSame('ResultCode 5: rejected', imis_client::result_error($bad));
    }

    /**
     * The status may sit on the createActivity wrapper rather than a ResultsBase.
     *
     * @return void
     */
    public function test_result_error_createactivity_wrapper(): void {
        $result = json_decode(json_encode(['createActivityResult' => ['Exception' => ['Message' => 'nope']]]));
        $this->assertSame('nope', imis_client::result_error($result));
    }

    /**
     * Success codes are parsed from a comma list; blank yields an empty set.
     *
     * @return void
     */
    public function test_success_result_codes(): void {
        $this->assertSame([], imis_client::success_result_codes());

        set_config('soap_success_result_codes', '0, 1 ,2', 'local_imisbridge');
        $this->assertSame(['0', '1', '2'], imis_client::success_result_codes());
    }
}
