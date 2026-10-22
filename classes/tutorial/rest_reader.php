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
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_imisbridge\tutorial;

use local_imisbridge\rest_client;

/**
 * Direct-REST {@see reader}: reads the iMIS MoodleTutorialGroup business object.
 *
 * The panel is a contact-parented, multi-instance business object. Over the iMIS
 * REST API each instance exposes (confirmed on sccadev, 22/10/2026): ID (the iMIS
 * id, which matches the Moodle username), Ordinal, CreatedOn, UpdatedOn, Code and
 * Moodlegroupname. One unfiltered paged GET returns every instance across all
 * contacts, which is exactly the authoritative set the sync needs.
 *
 * @package    local_imisbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rest_reader implements reader {
    /** @var string The iMIS REST entity path for the tutorial-group panel. */
    public const ENTITY_PATH = '/api/MoodleTutorialGroup';

    /** @var rest_client The authenticated iMIS REST client. */
    private rest_client $client;

    /**
     * @param rest_client|null $client An iMIS REST client, or null to build one from settings.
     */
    public function __construct(?rest_client $client = null) {
        $this->client = $client ?? new rest_client();
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
        foreach ($this->client->get_all_entities(self::ENTITY_PATH) as $entity) {
            $imisid    = (string)rest_client::property($entity, 'ID');
            $code      = (string)rest_client::property($entity, 'Code');
            $groupname = (string)rest_client::property($entity, 'Moodlegroupname');
            if (trim($imisid) === '' || trim($code) === '' || trim($groupname) === '') {
                continue;
            }
            $rows[] = new membership_row($imisid, $code, $groupname);
        }
        return $rows;
    }
}
