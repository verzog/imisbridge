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
 * Tests for the local_membershipbridge direct iMIS REST client.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge;

/**
 * Unit tests for the pure request/response logic in {@see rest_client}.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_membershipbridge\rest_client
 */
final class rest_client_test extends \advanced_testcase {
    /**
     * Read a property Value from a built activity body by Name.
     *
     * @param array  $body The body returned by build_activity_body/clone_for_update.
     * @param string $name The property Name to read.
     * @return mixed The Value, or null when the property is absent.
     */
    private function body_value(array $body, string $name) {
        foreach ($body['Properties']['$values'] as $prop) {
            if (($prop['Name'] ?? null) === $name) {
                return $prop['Value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Whether a built activity body carries a property with the given Name.
     *
     * @param array  $body The body.
     * @param string $name The property Name.
     * @return bool
     */
    private function body_has(array $body, string $name): bool {
        foreach ($body['Properties']['$values'] as $prop) {
            if (($prop['Name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * A MOODLE create body carries the verified columns, the parent identity and no SEQN.
     *
     * @return void
     */
    public function test_build_activity_body_moodle(): void {
        $body = rest_client::build_activity_body(
            rest_client::ACTIVITY_MOODLE,
            'jsmith',
            '2150',
            'COMPLETE',
            87.5,
            '2026-10-19',
            'desc'
        );

        $this->assertSame('Party', $body['PrimaryParentEntityTypeName']);
        $this->assertSame(['jsmith'], $body['PrimaryParentIdentity']['IdentityElements']['$values']);
        $this->assertSame('jsmith', $this->body_value($body, 'PartyId'));
        $this->assertSame('MOODLE', $this->body_value($body, 'ACTIVITY_TYPE'));
        $this->assertSame('2150', $this->body_value($body, 'UF_1'));
        $this->assertSame('COMPLETE', $this->body_value($body, 'UF_3'));
        $this->assertSame(87.5, $this->body_value($body, 'UF_4'));
        $this->assertSame('2026-10-19', $this->body_value($body, 'TRANSACTION_DATE'));
        $this->assertSame('desc', $this->body_value($body, 'DESCRIPTION'));
        $this->assertFalse($this->body_has($body, 'SEQN'));
    }

    /**
     * A note is a full vendor-shaped MOODLENOTE record: every column with its .NET type,
     * the course number in PRODUCT_CODE (UF_1 sent empty), the activity name in
     * DESCRIPTION, note text and category in NOTE/NOTE_2, the date in TRANSACTION_DATE
     * and THRU_DATE, and extras such as UF_5 (as a decimal) and SOURCE_CODE.
     *
     * @return void
     */
    public function test_build_note_body_matches_vendor_layout(): void {
        $body = rest_client::build_note_body(
            '20022',
            '2152',
            'PASS',
            2.0,
            '2026-10-26',
            'Practice Exam',
            'Module completed : Practice Exam',
            'modulecompletion',
            ['UF_5' => 1507, 'SOURCE_CODE' => 'quiz', 'UF_6' => '']
        );

        $this->assertSame('Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts', $body['$type']);
        $this->assertSame('Activity', $body['EntityTypeName']);
        $this->assertCount(44, $body['Properties']['$values'], 'every column of a vendor note');
        $this->assertSame('MOODLENOTE', $this->body_value($body, 'ACTIVITY_TYPE'));
        $this->assertSame('20022', $this->body_value($body, 'PartyId'));
        $this->assertSame('2152', $this->body_value($body, 'PRODUCT_CODE'));
        $this->assertTrue($this->body_has($body, 'UF_1'));
        $this->assertNull($this->body_value($body, 'UF_1'), 'UF_1 is sent without a value');
        $this->assertSame('PASS', $this->body_value($body, 'UF_3'));
        $this->assertSame(['$type' => 'System.Decimal', '$value' => 2.0], $this->body_value($body, 'UF_4'));
        $this->assertSame(['$type' => 'System.Decimal', '$value' => 1507.0], $this->body_value($body, 'UF_5'));
        $this->assertSame(['$type' => 'System.Boolean', '$value' => false], $this->body_value($body, 'RECURRING_REQUEST'));
        $this->assertSame('Practice Exam', $this->body_value($body, 'DESCRIPTION'));
        $this->assertSame('Module completed : Practice Exam', $this->body_value($body, 'NOTE'));
        $this->assertSame('modulecompletion', $this->body_value($body, 'NOTE_2'));
        $this->assertSame('quiz', $this->body_value($body, 'SOURCE_CODE'));
        $this->assertSame('2026-10-26', $this->body_value($body, 'TRANSACTION_DATE'));
        $this->assertSame('2026-10-26', $this->body_value($body, 'THRU_DATE'));
        $this->assertNull($this->body_value($body, 'UF_6'), 'an empty extra leaves the column empty');
    }

    /**
     * Extra columns are added when named and non-empty, and skipped when empty.
     *
     * @return void
     */
    public function test_build_activity_body_extra_columns(): void {
        $body = rest_client::build_activity_body(
            rest_client::ACTIVITY_MOODLE,
            'jsmith',
            '2150',
            'COMPLETE',
            100.0,
            '2026-10-19',
            'd',
            ['UF_5' => 'CEU', 'UF_6' => '', 'UF_7' => null]
        );

        $this->assertSame('CEU', $this->body_value($body, 'UF_5'));
        $this->assertFalse($this->body_has($body, 'UF_6'));
        $this->assertFalse($this->body_has($body, 'UF_7'));
    }

    /**
     * The identity block carries the contact id in its IdentityElements.
     *
     * @return void
     */
    public function test_identity_block(): void {
        $block = rest_client::identity_block('abc123');
        $this->assertSame('Party', $block['EntityTypeName']);
        $this->assertSame(['abc123'], $block['IdentityElements']['$values']);
    }

    /**
     * property() unwraps a typed scalar, returns a bare value, and nulls the absent.
     *
     * @return void
     */
    public function test_property_reads(): void {
        $entity = json_decode(json_encode([
            'Properties' => ['$values' => [
                ['Name' => 'UF_1', 'Value' => '2150'],
                ['Name' => 'UF_4', 'Value' => ['$type' => 'System.Decimal', '$value' => 87.5]],
                ['Name' => 'EMPTY'],
            ]],
        ]));

        $this->assertSame('2150', rest_client::property($entity, 'UF_1'));
        $this->assertEquals(87.5, rest_client::property($entity, 'UF_4'));
        $this->assertNull(rest_client::property($entity, 'EMPTY'));
        $this->assertNull(rest_client::property($entity, 'MISSING'));
    }

    /**
     * items() returns the Items.$values rows, or an empty array when absent.
     *
     * @return void
     */
    public function test_items(): void {
        $body = json_decode(json_encode(['Items' => ['$values' => [['Name' => 'a'], ['Name' => 'b']]]]));
        $this->assertCount(2, rest_client::items($body));

        $empty = json_decode(json_encode(['foo' => 'bar']));
        $this->assertSame([], rest_client::items($empty));
    }

    /**
     * next_page() follows HasNext/NextOffset and stops without paging metadata.
     *
     * @return void
     */
    public function test_next_page(): void {
        $more = json_decode(json_encode(['HasNext' => true, 'NextOffset' => 100]));
        $this->assertSame([true, 100], rest_client::next_page($more, 0, 100));

        $last = json_decode(json_encode(['HasNext' => false]));
        $this->assertSame([false, 50], rest_client::next_page($last, 0, 50));

        $none = json_decode(json_encode(['Items' => ['$values' => []]]));
        $this->assertSame([false, 20], rest_client::next_page($none, 20, 0));
    }

    /**
     * next_page() also unwraps typed-scalar HasNext/NextOffset values.
     *
     * @return void
     */
    public function test_next_page_typed_scalars(): void {
        $body = json_decode(json_encode([
            'HasNext'    => ['$type' => 'System.Boolean', '$value' => true],
            'NextOffset' => ['$type' => 'System.Int32', '$value' => 200],
        ]));
        $this->assertSame([true, 200], rest_client::next_page($body, 100, 100));
    }

    /**
     * clone_for_update keeps existing columns, drops audit fields, overrides completion.
     *
     * @return void
     */
    public function test_clone_for_update(): void {
        $template = json_decode(json_encode([
            'PrimaryParentEntityTypeName' => 'Party',
            'Properties' => ['$values' => [
                ['Name' => 'SEQN', 'Value' => '999'],
                ['Name' => 'PartyId', 'Value' => 'jsmith'],
                ['Name' => 'UF_1', 'Value' => '2150'],
                ['Name' => 'UF_2', 'Value' => '1325'],
                ['Name' => 'UF_3', 'Value' => 'INPROGRESS'],
                ['Name' => 'CREATED_ON', 'Value' => '2026-01-01'],
                ['Name' => 'UPDATED_ON', 'Value' => '2026-01-02'],
            ]],
        ]));

        $body = rest_client::clone_for_update($template, 'COMPLETE', 91.0, '2026-10-19', 'done');

        // Overridden completion fields.
        $this->assertSame('COMPLETE', $this->body_value($body, 'UF_3'));
        $this->assertSame(91.0, $this->body_value($body, 'UF_4'));
        $this->assertSame('2026-10-19', $this->body_value($body, 'TRANSACTION_DATE'));
        $this->assertSame('done', $this->body_value($body, 'DESCRIPTION'));
        // Preserved zero-default columns, including SEQN (the PUT target) and UF_2.
        $this->assertSame('jsmith', $this->body_value($body, 'PartyId'));
        $this->assertSame('2150', $this->body_value($body, 'UF_1'));
        $this->assertSame('1325', $this->body_value($body, 'UF_2'));
        $this->assertSame('999', $this->body_value($body, 'SEQN'));
        // Read-only audit columns stripped.
        $this->assertFalse($this->body_has($body, 'CREATED_ON'));
        $this->assertFalse($this->body_has($body, 'UPDATED_ON'));
    }

    /**
     * error_reason pulls a short, markup-free reason from the shapes iMIS returns.
     *
     * @return void
     */
    public function test_error_reason(): void {
        $this->assertSame('Query not found', rest_client::error_reason('{"Message":"Query not found"}'));
        $this->assertSame(
            'Missing required parameter: Code',
            rest_client::error_reason('{"Message":"An error has occurred.","ExceptionMessage":"Missing required parameter: Code"}')
        );
        $this->assertSame(
            'The user name or password is incorrect.',
            rest_client::error_reason('{"error":"invalid_grant","error_description":"The user name or password is incorrect."}')
        );
        $this->assertSame('Missing required parameter', rest_client::error_reason('"Missing required parameter"'));
        $html = "<html><h1>Bad Request</h1>\n<p>Invalid query</p></html>";
        $this->assertSame('Bad Request Invalid query', rest_client::error_reason($html));
        $page = '<html><head><style type="text/css">body { color: red; }</style><script>var x = 1;</script></head>'
            . '<body><h1>502 Bad Gateway</h1></body></html>';
        $this->assertSame('502 Bad Gateway', rest_client::error_reason($page));
        $this->assertSame('', rest_client::error_reason(''));
        $this->assertSame('', rest_client::error_reason('{"Items":[]}'));
        $this->assertSame(300, \core_text::strlen(rest_client::error_reason(str_repeat('x', 400))));
    }
}
