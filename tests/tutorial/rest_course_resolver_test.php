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
     * The IQA's required Code prompt is sent the {code}/REG product code, and the
     * returned row's Description becomes the course id.
     *
     * @return void
     */
    public function test_resolves_via_required_code_prompt(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REG', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertSame([['ATS_BRIDGE/REG']], $client->calls, 'the Code prompt gets {code}/REG');
    }

    /**
     * A code the IQA returns no row for resolves to null (and is still looked up with
     * the suffixed product code).
     *
     * @return void
     */
    public function test_unmapped_code_returns_null(): void {
        $client = $this->stub_client([]);

        $resolver = new rest_course_resolver($client);
        $this->assertNull($resolver->resolve_courseid('NOPE'));
        $this->assertSame([['NOPE/REG']], $client->calls);
    }

    /**
     * A product whose Description names a non-existent course resolves to null.
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
     * A row whose Code is a neighbouring product (not the looked-up {code}/REG) is
     * rejected, guarding against a loosely-filtered IQA.
     *
     * @return void
     */
    public function test_rejects_mismatched_product_code(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REGNZ', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertNull($resolver->resolve_courseid('ATS_BRIDGE'));
    }

    /**
     * Each code hits the IQA once; a repeat resolve is served from the cache.
     *
     * @return void
     */
    public function test_caches_result_per_code(): void {
        $course = $this->getDataGenerator()->create_course();
        $client = $this->stub_client([
            $this->entity(['Code' => 'ATS_BRIDGE/REG', 'Description' => $course->id]),
        ]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ATS_BRIDGE'));
        $this->assertCount(1, $client->calls, 'the IQA is called once per code, then cached');
    }

    /**
     * A course mapping override resolves the code without reading iMIS, by bare code or
     * full product code, ignoring case; an override to a missing course is unmapped.
     *
     * @return void
     */
    public function test_override_wins_without_reading_imis(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('course_map_overrides', "# Staging test courses\nACCD_26={$course->id}\n"
            . "rpldERM_26/REG={$course->id}\nGONE=999999\n", 'local_imisbridge');
        $client = $this->stub_client([$this->entity(['Code' => 'ACCD_26/REG', 'Description' => '2152'])]);

        $resolver = new rest_course_resolver($client);
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ACCD_26'));
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('RPLDERM_26'));
        $this->assertNull($resolver->resolve_courseid('GONE'));
        $this->assertSame([], $client->calls, 'iMIS is not read for an overridden code');
    }

    /**
     * Override lines are parsed leniently: comments, blanks and malformed lines are skipped.
     *
     * @return void
     */
    public function test_parse_overrides(): void {
        $text = "# comment\n\nACCD_26 = 19\r\nBAD=abc\nNOEQUALS\n=5\nZERO=0\nDERM_JUL26/REG=9";
        $this->assertSame(['accd_26' => 19, 'derm_jul26/reg' => 9], rest_course_resolver::parse_overrides($text));
    }
}
