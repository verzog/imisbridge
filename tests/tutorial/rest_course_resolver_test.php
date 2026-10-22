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
 * Tests for the MOODLE_PRODUCTS course resolver.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

use local_imisbridge\rest_client;

/**
 * Unit tests for {@see rest_course_resolver}.
 *
 * A stub REST client returns canned IQA rows, so the tests assert only the
 * resolver's matching and validation logic with no network.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\tutorial\rest_course_resolver
 */
final class rest_course_resolver_test extends \advanced_testcase {
    /**
     * Reset the database before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Build a GenericEntityData-shaped row from name => value pairs.
     *
     * @param array $props The property name => value pairs.
     * @return \stdClass
     */
    private function entity(array $props): \stdClass {
        $values = [];
        foreach ($props as $name => $value) {
            $values[] = (object)['Name' => $name, 'Value' => (string)$value];
        }
        return (object)['Properties' => (object)['$values' => $values]];
    }

    /**
     * A REST client stub whose run_iqa returns fixed rows and records its calls.
     *
     * @param array $rows The rows run_iqa should return.
     * @return rest_client
     */
    private function stub_client(array $rows): rest_client {
        return new class ($rows) extends rest_client {
            /** @var array The rows to return. */
            public array $rows;

            /** @var array The parameter lists run_iqa was called with. */
            public array $calls = [];

            /**
             * Capture the rows and bypass the real (config-reading) constructor.
             *
             * @param array $rows The rows to return.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * Record the parameters and return the canned rows.
             *
             * @param string $queryname  The IQA document path.
             * @param array  $parameters The prompt values.
             * @return array
             */
            public function run_iqa(string $queryname, array $parameters = []): array {
                $this->calls[] = $parameters;
                return $this->rows;
            }
        };
    }

    /**
     * The IQA is prompted with the bare code, and a row whose Code is the {code}/REG
     * product returns its Description as the course id.
     *
     * @return void
     */
    public function test_resolves_bare_code_via_product_row(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REG', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertSame([[]], $client->calls, 'the unprompted IQA is called with no parameters');
    }

    /**
     * Only a neighbouring product (e.g. /REGNZ) in the table does not resolve the
     * bare code.
     *
     * @return void
     */
    public function test_rejects_neighbouring_product(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REGNZ', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertNull($resolver->resolve_courseid('ATS_BRIDGE'));
    }

    /**
     * A product whose Description names a non-existent course is not mapped.
     *
     * @return void
     */
    public function test_rejects_missing_course(): void {
        $client = $this->stub_client([
            $this->entity(['Code' => 'X/REG', 'Description' => '999999']),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertNull($resolver->resolve_courseid('X'));
    }

    /**
     * The whole table is fetched once; resolving several codes makes no extra calls,
     * and the correct product row is picked out of a full table.
     *
     * @return void
     */
    public function test_fetches_table_once_and_picks_correct_row(): void {
        $atsbridge = $this->getDataGenerator()->create_course();
        $accd = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REG', 'Description' => $atsbridge->id]),
            $this->entity(['Code' => 'ATS_Bridgetest/REG', 'Description' => '424242']),
            $this->entity(['Code' => 'ACCD_26/REG', 'Description' => $accd->id]),
            $this->entity(['Code' => 'ACCD_26/REGNZ', 'Description' => $accd->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$atsbridge->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertSame((int)$accd->id, $resolver->resolve_courseid('ACCD_26'));
        $this->assertNull($resolver->resolve_courseid('MISSING'));
        $this->assertCount(1, $client->calls, 'the lookup table is fetched only once');
    }
}
