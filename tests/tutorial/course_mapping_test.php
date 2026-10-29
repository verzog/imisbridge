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
 * Tests for choosing the course mapping and for the course ID number mapping.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use local_membershipbridge\rest_client;

/**
 * Unit tests for {@see course_mapping} and {@see idnumber_course_resolver}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\tutorial\course_mapping
 * @covers     \local_membershipbridge\tutorial\idnumber_course_resolver
 */
final class course_mapping_test extends \advanced_testcase {
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
     * A REST client stub that bypasses the real (config-reading) constructor.
     *
     * @return rest_client
     */
    private function stub_client(): rest_client {
        return new class extends rest_client {
            /**
             * Skip the real constructor.
             */
            public function __construct() {
            }
        };
    }

    /**
     * The iMIS lookup query is the default, and anything unrecognised means it too.
     *
     * @return void
     */
    public function test_iqa_is_default(): void {
        set_config('tutorial_course_iqa', '$/MyOrg/Lookup', 'local_membershipbridge');

        $this->assertSame(course_mapping::SOURCE_IQA, course_mapping::source());
        $this->assertInstanceOf(rest_course_resolver::class, course_mapping::resolver($this->stub_client()));

        set_config('course_map_source', 'bogus', 'local_membershipbridge');
        $this->assertSame(course_mapping::SOURCE_IQA, course_mapping::source());
    }

    /**
     * Choosing the course ID number gives the Moodle-side resolver, which needs no
     * lookup query.
     *
     * @return void
     */
    public function test_idnumber_source(): void {
        set_config('course_map_source', course_mapping::SOURCE_IDNUMBER, 'local_membershipbridge');
        set_config('tutorial_course_iqa', '', 'local_membershipbridge');

        $this->assertSame(course_mapping::SOURCE_IDNUMBER, course_mapping::source());
        $this->assertInstanceOf(idnumber_course_resolver::class, course_mapping::resolver($this->stub_client()));
    }

    /**
     * A code resolves to the course with that ID number, and an unknown one to null.
     *
     * @return void
     */
    public function test_idnumber_resolves(): void {
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'ACCD_26']);
        $this->getDataGenerator()->create_course(['idnumber' => 'OTHER']);

        $resolver = new idnumber_course_resolver();
        $this->assertSame((int)$course->id, $resolver->resolve_courseid('ACCD_26'));
        $this->assertSame((int)$course->id, $resolver->resolve_courseid(' ACCD_26 '));
        $this->assertNull($resolver->resolve_courseid('NOPE'));
        $this->assertNull($resolver->resolve_courseid(''));
    }

    /**
     * Two courses sharing an ID number leave the code unmapped rather than guessed.
     *
     * @return void
     */
    public function test_idnumber_ambiguous_is_unmapped(): void {
        global $DB;

        $one = $this->getDataGenerator()->create_course(['idnumber' => 'DUP']);
        $two = $this->getDataGenerator()->create_course();
        $DB->set_field('course', 'idnumber', 'DUP', ['id' => $two->id]);

        $this->assertNotEquals($one->id, $two->id);
        $this->assertNull((new idnumber_course_resolver())->resolve_courseid('DUP'));
    }

    /**
     * A course mapping override wins over the ID number, and must name a real course.
     *
     * @return void
     */
    public function test_idnumber_override_wins(): void {
        $this->getDataGenerator()->create_course(['idnumber' => 'ACCD_26']);
        $test = $this->getDataGenerator()->create_course();
        set_config('course_map_overrides', "ACCD_26={$test->id}\nGONE=999999", 'local_membershipbridge');

        $resolver = new idnumber_course_resolver();
        $this->assertSame((int)$test->id, $resolver->resolve_courseid('accd_26'));
        $this->assertNull($resolver->resolve_courseid('GONE'));
    }
}
