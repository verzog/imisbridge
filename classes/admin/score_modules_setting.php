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
 * Admin setting: the checklist of activity types whose grades are pushed to iMIS.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * A multi-checkbox of the site's gradable activity types, loaded lazily.
 *
 * The choices come from {@see \local_membershipbridge\util::gradable_activity_choices()},
 * which asks each installed activity whether it is gradable. Loading them only when
 * the setting is rendered or saved keeps that work off every other admin page.
 *
 * @package    local_membershipbridge
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_modules_setting extends \admin_setting_configmulticheckbox {
    /**
     * Build the setting with no choices yet; they are loaded on demand.
     *
     * @param string $name        The setting name (plugin/config).
     * @param string $visiblename The localised setting name.
     * @param string $description The localised description.
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, [], null);
    }

    /**
     * Load the gradable activity types as the choices.
     *
     * @return bool True once loaded; false during Moodle's initial install, when the
     *              activity plugins are not installed yet (as core's own setting does).
     */
    public function load_choices() {
        if (during_initial_install()) {
            return false;
        }
        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = \local_membershipbridge\util::gradable_activity_choices();
        return true;
    }
}
