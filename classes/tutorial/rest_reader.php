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
 * Reads tutorial-group membership rows from the iMIS MoodleTutorialGroup panel.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\tutorial;

use local_membershipbridge\rest_client;

/**
 * Direct-REST {@see reader}: reads the iMIS MoodleTutorialGroup business object.
 *
 * The panel is a contact-parented, multi-instance business object. Over the iMIS
 * REST API each instance exposes (confirmed on sccadev, 22/10/2026): ID (the iMIS
 * id, which matches the Moodle username), Ordinal, CreatedOn, UpdatedOn, Code and
 * Moodlegroupname. One unfiltered paged GET returns every instance across all
 * contacts, which is exactly the authoritative set the sync needs.
 *
 * The panel and its property names are settings (*Tutorial panel*, *Member ID field*,
 * *Event code field*, *Group name field*), defaulting to the names above; a blank
 * setting falls back to its default.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_reader implements reader {
    /** @var string Default panel (business object) name. */
    public const DEFAULT_PANEL = 'MoodleTutorialGroup';

    /** @var array<string,string> Field setting => default property name. */
    public const DEFAULT_FIELDS = [
        'tutorial_field_id'    => 'ID',
        'tutorial_field_code'  => 'Code',
        'tutorial_field_group' => 'Moodlegroupname',
    ];

    /** @var rest_client The authenticated iMIS REST client. */
    private rest_client $client;

    /** @var string The panel property that marks a tutor (the Tutor checkbox), or '' for none. */
    private string $tutorfield;

    /** @var string The iMIS REST entity path for the panel, e.g. /api/MoodleTutorialGroup. */
    private string $path;

    /** @var array<string,string> Field setting => the panel's property name. */
    private array $fields = [];

    /**
     * Build the reader, using the given client or one constructed from settings.
     *
     * @param rest_client|null $client     An iMIS REST client, or null to build one from settings.
     * @param string|null      $tutorfield The panel's tutor property, or null to use the
     *                                     *Tutor field* setting.
     */
    public function __construct(?rest_client $client = null, ?string $tutorfield = null) {
        $this->client = $client ?? new rest_client();
        $this->tutorfield = trim($tutorfield ?? (string)get_config('local_membershipbridge', 'tutorial_tutor_field'));
        $this->path = '/api/' . self::setting('tutorial_panel', self::DEFAULT_PANEL);
        foreach (self::DEFAULT_FIELDS as $name => $default) {
            $this->fields[$name] = self::setting($name, $default);
        }
    }

    /**
     * A name setting, or its default when unset or blank.
     *
     * @param string $name    The setting name.
     * @param string $default The default.
     * @return string
     */
    private static function setting(string $name, string $default): string {
        $value = trim((string)get_config('local_membershipbridge', $name));
        return $value === '' ? $default : $value;
    }

    /**
     * Read every MoodleTutorialGroup instance and map it to a membership row.
     *
     * Rows missing any of the three required fields are dropped here so the engine
     * never has to defend against partial source data.
     *
     * @return membership_row[]
     */
    public function fetch_rows(): array {
        $rows = [];
        foreach ($this->client->get_all_entities($this->path) as $entity) {
            $imisid    = (string)rest_client::property($entity, $this->fields['tutorial_field_id']);
            $code      = (string)rest_client::property($entity, $this->fields['tutorial_field_code']);
            $groupname = (string)rest_client::property($entity, $this->fields['tutorial_field_group']);
            if (trim($imisid) === '' || trim($code) === '' || trim($groupname) === '') {
                continue;
            }
            $tutor = ($this->tutorfield !== '') && self::is_ticked(rest_client::property($entity, $this->tutorfield));
            $rows[] = new membership_row($imisid, $code, $groupname, $tutor);
        }
        return $rows;
    }

    /**
     * Whether a panel checkbox value is ticked. iMIS returns a checkbox as a boolean,
     * or as text such as "True" or "1" depending on how the property is defined.
     *
     * @param mixed $value The property value.
     * @return bool
     */
    public static function is_ticked($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(\core_text::strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y'], true);
    }
}
