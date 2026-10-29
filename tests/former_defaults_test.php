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
 * Tests for keeping a site on its pre-2.2.0 course lookup values.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Unit tests for {@see former_defaults}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\former_defaults
 */
final class former_defaults_test extends \advanced_testcase {
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
     * Unset settings get the values the lookup used to fall back to.
     *
     * @return void
     */
    public function test_pins_unset_settings(): void {
        unset_config('tutorial_course_iqa', 'local_membershipbridge');
        unset_config('tutorial_course_code_suffix', 'local_membershipbridge');

        $this->assertSame(['tutorial_course_iqa', 'tutorial_course_code_suffix'], former_defaults::pin());
        $this->assertSame(former_defaults::COURSE_IQA, get_config('local_membershipbridge', 'tutorial_course_iqa'));
        $this->assertSame(former_defaults::COURSE_SUFFIX, get_config('local_membershipbridge', 'tutorial_course_code_suffix'));
    }

    /**
     * A blank query used to fall back too, so it is pinned; a blank suffix meant "no
     * suffix", so it is kept.
     *
     * @return void
     */
    public function test_blank_query_pinned_blank_suffix_kept(): void {
        set_config('tutorial_course_iqa', '', 'local_membershipbridge');
        set_config('tutorial_course_code_suffix', '', 'local_membershipbridge');

        $this->assertSame(['tutorial_course_iqa'], former_defaults::pin());
        $this->assertSame(former_defaults::COURSE_IQA, get_config('local_membershipbridge', 'tutorial_course_iqa'));
        $this->assertSame('', get_config('local_membershipbridge', 'tutorial_course_code_suffix'));
    }

    /**
     * Values the site set itself are never changed.
     *
     * @return void
     */
    public function test_keeps_site_values(): void {
        set_config('tutorial_course_iqa', '$/MyOrg/Lookup', 'local_membershipbridge');
        set_config('tutorial_course_code_suffix', '/X', 'local_membershipbridge');

        $this->assertSame([], former_defaults::pin());
        $this->assertSame('$/MyOrg/Lookup', get_config('local_membershipbridge', 'tutorial_course_iqa'));
        $this->assertSame('/X', get_config('local_membershipbridge', 'tutorial_course_code_suffix'));
    }

    /**
     * A new site gets no built-in lookup from the setting defaults.
     *
     * @return void
     */
    public function test_new_site_defaults_are_blank(): void {
        $this->assertSame('', (string)get_config('local_membershipbridge', 'tutorial_course_iqa'));
        $this->assertSame('', (string)get_config('local_membershipbridge', 'tutorial_course_code_suffix'));
    }
}
