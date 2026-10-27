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
 * Contract for an external records-system provider.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\provider;

/**
 * A backend the plugin can push Moodle learning records to.
 *
 * The plugin is a bridge between Moodle and an external membership/records system.
 * Each supported backend (the ATS SOAP bridge and the direct REST API are the two
 * shipped today) is one provider implementing this interface, selected at runtime
 * by the connection setting. Adding support for another system means writing a new
 * class that implements this interface and registering it with {@see manager}.
 *
 * {@see label()}, {@see is_configured()} and {@see supports()} are static so the
 * plugin can list providers, gate capabilities and check configuration without
 * constructing a client (constructing one requires — and may fail on — live
 * connection settings).
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider_interface {
    /** @var string Capability: pushes Moodle completion/score/note records outbound. */
    public const CAP_PUSH = 'push';

    /** @var string Capability: inbound order/cancellation/group sync and connection diagnostics. */
    public const CAP_ORG_SYNC = 'orgsync';

    /**
     * A short human-readable name for this provider, shown in settings.
     *
     * @return string
     */
    public static function label(): string;

    /**
     * Whether this provider has the minimum configuration to connect.
     *
     * Must not construct a client or make a network call, so it is safe to call on
     * every request (e.g. during login) before deciding whether to queue work.
     *
     * @return bool
     */
    public static function is_configured(): bool;

    /**
     * Whether this provider offers the given capability.
     *
     * @param string $capability One of the CAP_* constants.
     * @return bool
     */
    public static function supports(string $capability): bool;

    /**
     * Push a course/activity completion record to the external system.
     *
     * @param \stdClass $data The queued push data (imisid, coursenum, credit*, dates, status, score).
     * @return void
     */
    public function push_completion(\stdClass $data): void;

    /**
     * Push an assessment score record to the external system.
     *
     * @param \stdClass $data The queued push data (imisid, coursenum, status, score, dates).
     * @return void
     */
    public function push_score(\stdClass $data): void;

    /**
     * Push a supplementary course-module note to the external system: a module
     * completion, or an activity score when `notekind` is 'score'.
     *
     * @param \stdClass $data The queued push data (imisid, coursenum, cmid, modname, cmname, status, score, date,
     *                        optional notekind).
     * @return void
     */
    public function push_module_note(\stdClass $data): void;
}
