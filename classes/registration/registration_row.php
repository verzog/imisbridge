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
 * One registration row from the external registrations source.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\registration;

/**
 * A single registration: who, for which event, and its state.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration_row {
    /** @var string The registrant's iMIS ID (resolved to a Moodle user by the configured ID source). */
    public string $imisid;

    /** @var string The event code; resolved to a Moodle course through MOODLE_PRODUCTS. */
    public string $eventcode;

    /** @var string The raw registration status ('' when active). */
    public string $status;

    /** @var string A stable label for the registration (e.g. order number and registrant), for logs. */
    public string $key;

    /** @var bool|null The source's "is active registration" flag, or null when not supplied. */
    public ?bool $isactive;

    /** @var int|null The event's end date (Unix time), or null when unknown. */
    public ?int $enddate;

    /** @var string The registrant's first name. */
    public string $firstname;

    /** @var string The registrant's last name. */
    public string $lastname;

    /** @var string The registrant's email address. */
    public string $email;

    /**
     * Build a row, trimming each text field.
     *
     * @param string    $imisid    The registrant's iMIS ID.
     * @param string    $eventcode The event code.
     * @param string    $status    The raw registration status ('' when active).
     * @param string    $key       A stable label for the registration.
     * @param bool|null $isactive  The source's active flag, or null when not supplied.
     * @param int|null  $enddate   The event's end date (Unix time), or null when unknown.
     * @param string    $firstname The registrant's first name.
     * @param string    $lastname  The registrant's last name.
     * @param string    $email     The registrant's email address.
     */
    public function __construct(
        string $imisid,
        string $eventcode,
        string $status = '',
        string $key = '',
        ?bool $isactive = null,
        ?int $enddate = null,
        string $firstname = '',
        string $lastname = '',
        string $email = ''
    ) {
        $this->imisid    = trim($imisid);
        $this->eventcode = trim($eventcode);
        $this->status    = trim($status);
        $this->key       = trim($key);
        $this->isactive  = $isactive;
        $this->enddate   = $enddate;
        $this->firstname = trim($firstname);
        $this->lastname  = trim($lastname);
        $this->email     = trim($email);
    }
}
