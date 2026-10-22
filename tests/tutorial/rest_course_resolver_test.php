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
     * An exact {code}/REG match returns its Description as the course id, and the
     * lookup is performed with the suffix appended.
     *
     * @return void
     */
    public function test_resolves_exact_match_with_suffix(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REG', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertSame([['ATS_BRIDGE/REG']], $client->calls);
    }

    /**
     * A row whose Code is a neighbouring product (e.g. /REGNZ) is not accepted.
     *
     * @return void
     */
    public function test_rejects_non_exact_code_match(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REGNZ', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertNull($resolver->resolve_courseid('ATS_BRIDGE'));
    }

    /**
     * A Description that names a non-existent course resolves to null.
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
     * When a row carries no Code column, its Description is accepted (nothing to
     * disambiguate), as long as the course exists.
     *
     * @return void
     */
    public function test_accepts_row_without_code_column(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
    }
}
