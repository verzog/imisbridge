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
 * Tests for the tutorial panel reader's panel and field settings.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use local_membershipbridge\rest_client;

/**
 * Unit tests for {@see rest_reader}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\tutorial\rest_reader
 */
final class rest_reader_test extends \advanced_testcase {
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
            $values[] = (object)['Name' => $name, 'Value' => $value];
        }
        return (object)['Properties' => (object)['$values' => $values]];
    }

    /**
     * A REST client stub whose get_all_entities returns fixed rows and records the path.
     *
     * @param array $rows The rows to return.
     * @return rest_client
     */
    private function stub_client(array $rows): rest_client {
        return new class ($rows) extends rest_client {
            /** @var array The rows to return. */
            public array $rows;

            /** @var string[] The paths read. */
            public array $paths = [];

            /**
             * Capture the rows and bypass the real (config-reading) constructor.
             *
             * @param array $rows The rows to return.
             */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            /**
             * Record the path and return the canned rows.
             *
             * @param string $path  The entity path.
             * @param int    $limit The page size.
             * @return array
             */
            public function get_all_entities(string $path, int $limit = 100): array {
                $this->paths[] = $path;
                return $this->rows;
            }
        };
    }

    /**
     * By default the reader reads the MoodleTutorialGroup panel's ID, Code and
     * Moodlegroupname properties, as it always has.
     *
     * @return void
     */
    public function test_default_panel_and_fields(): void {
        $client = $this->stub_client([
            $this->entity(['ID' => '20001', 'Code' => 'ACCD_26', 'Moodlegroupname' => 'Tutorial A', 'Tutor' => 'True']),
            $this->entity(['ID' => '20002', 'Code' => 'ACCD_26', 'Moodlegroupname' => '']),
        ]);

        $rows = (new rest_reader($client, 'Tutor'))->fetch_rows();
        $this->assertSame(['/api/MoodleTutorialGroup'], $client->paths);
        $this->assertCount(1, $rows, 'a row without a group name is dropped');
        $this->assertSame('20001', $rows[0]->imisid);
        $this->assertSame('ACCD_26', $rows[0]->code);
        $this->assertSame('Tutorial A', $rows[0]->groupname);
        $this->assertTrue($rows[0]->tutor);
    }

    /**
     * Another site's panel and property names are read from the settings, and a
     * blank setting falls back to its default.
     *
     * @return void
     */
    public function test_custom_panel_and_fields(): void {
        set_config('tutorial_panel', 'StudyGroup', 'local_membershipbridge');
        set_config('tutorial_field_code', 'EventCode', 'local_membershipbridge');
        set_config('tutorial_field_group', 'GroupName', 'local_membershipbridge');
        set_config('tutorial_field_id', '', 'local_membershipbridge');
        $client = $this->stub_client([
            $this->entity(['ID' => '20001', 'EventCode' => 'E1', 'GroupName' => 'Group 1']),
        ]);

        $rows = (new rest_reader($client, ''))->fetch_rows();
        $this->assertSame(['/api/StudyGroup'], $client->paths);
        $this->assertCount(1, $rows);
        $this->assertSame('20001', $rows[0]->imisid);
        $this->assertSame('E1', $rows[0]->code);
        $this->assertSame('Group 1', $rows[0]->groupname);
        $this->assertFalse($rows[0]->tutor);
    }
}
