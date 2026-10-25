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
 * Tests for the local_imisbridge util helpers.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge;

/**
 * Unit tests for {@see util}.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_imisbridge\util
 */
final class util_test extends \advanced_testcase {
    /**
     * Toggles default to on when unset, and only an explicit '0' disables them.
     *
     * @return void
     */
    public function test_is_enabled(): void {
        $this->resetAfterTest();

        // Unset is treated as enabled so upgrades keep existing behaviour.
        $this->assertTrue(util::is_enabled('push_completions'));

        set_config('push_completions', '0', 'local_imisbridge');
        $this->assertFalse(util::is_enabled('push_completions'));

        set_config('push_completions', '1', 'local_imisbridge');
        $this->assertTrue(util::is_enabled('push_completions'));
    }

    /**
     * The iMIS ID source defaults to username and rejects unknown values.
     *
     * @return void
     */
    public function test_imis_id_source(): void {
        $this->resetAfterTest();

        // Unset defaults to username so existing installs are unchanged.
        $this->assertSame('username', util::imis_id_source());

        set_config('imis_id_source', 'idnumber', 'local_imisbridge');
        $this->assertSame('idnumber', util::imis_id_source());

        set_config('imis_id_source', 'email', 'local_imisbridge');
        $this->assertSame('email', util::imis_id_source());

        // An unrecognised value falls back to username.
        set_config('imis_id_source', 'bogus', 'local_imisbridge');
        $this->assertSame('username', util::imis_id_source());
    }

    /**
     * The iMIS ID is read from the configured user field, trimmed, or '' when empty.
     *
     * @return void
     */
    public function test_get_imis_id(): void {
        $this->resetAfterTest();

        $user = (object)['username' => 'member123', 'idnumber' => 'IM-9', 'email' => 'a@b.test'];

        // Default source is username.
        $this->assertSame('member123', util::get_imis_id($user));

        set_config('imis_id_source', 'idnumber', 'local_imisbridge');
        $this->assertSame('IM-9', util::get_imis_id($user));

        set_config('imis_id_source', 'email', 'local_imisbridge');
        $this->assertSame('a@b.test', util::get_imis_id($user));

        // Empty configured field yields an empty string.
        set_config('imis_id_source', 'idnumber', 'local_imisbridge');
        $this->assertSame('', util::get_imis_id((object)['idnumber' => '   ']));
        $this->assertSame('', util::get_imis_id((object)['username' => 'x']));
    }

    /**
     * The plugin is only "configured" once a WSDL URL is set.
     *
     * @return void
     */
    public function test_is_configured(): void {
        $this->resetAfterTest();

        // Unset WSDL: not configured, so automatic writes should no-op.
        $this->assertFalse(util::is_configured());

        set_config('wsdl_url', 'https://example.invalid/wsmoodle.asmx?WSDL', 'local_imisbridge');
        $this->assertTrue(util::is_configured());

        set_config('wsdl_url', '', 'local_imisbridge');
        $this->assertFalse(util::is_configured());
    }

    /**
     * Push course id overrides parse only `moodleid=imisid` lines of positive whole numbers.
     *
     * @return void
     */
    public function test_parse_push_course_overrides(): void {
        $text = "# comment\n\n19 = 2152\r\n7=abc\nX=5\n0=3\n9=0\nnoequals\n20=3001";
        $this->assertSame([19 => 2152, 20 => 3001], util::parse_push_course_overrides($text));
    }
}
