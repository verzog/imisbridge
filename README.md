# iMIS Bridge #

Bridges Moodle with the iMIS association management system. It pushes
course-completion and quiz results back to iMIS as activity records, mirrors iMIS
tutorial groups into Moodle course groups, and can keep enrolments, cancellations
and group memberships in sync through the ATS bridge.

It talks to iMIS through a selectable **connection provider**: the ATS
`wsmoodle.asmx` SOAP bridge (the default), or the iMIS REST API directly (see
`docs/PROVIDERS.md`).

Key behaviours:

* Course completion and (graded, non-preview) quiz submissions queue an adhoc
  task that records the result in iMIS, with retry on failure.
* **Activity scores** (opt-in per activity type): when an activity of a type ticked
  in *Push activity scores* is graded — for example a tutor marking an assignment
  or case study — its score is sent to iMIS as a note. The list covers every
  gradable activity installed; an unchanged regrade is not sent again.
* **Tutorial group sync** (opt-in): a nightly scheduled task reads the iMIS
  `MoodleTutorialGroup` panel over REST and makes each course's groups match it.
  It uses the REST settings whichever provider is selected.
* **Registration sync over REST** (opt-in, 1.21.0): with *Sync provider* set to
  Direct iMIS REST and a registrations IQA configured, Moodle reads registrations
  from iMIS and enrols active registrants (creating missing accounts) and suspends
  cancelled ones itself, with no ATS involved. A dry run is on by default. The
  *Sync provider* setting can also keep the ATS syncs while records go over REST.
* **Cancellation without data loss**: the `local_imisbridge_cancel_enrolment` web
  service function lets the ATS bridge *suspend* a cancelled learner (grades and
  completion kept) instead of hard-unenrolling them.
* On login, a per-user enrolment/cancellation/group sync can be queued as an adhoc
  task so iMIS round-trips never block the login request, and nightly scheduled
  tasks can run a full enrolment and cancellation sync plus an incremental group
  sync (ATS SOAP bridge only; each toggle can be turned off).
* An admin page (Site administration > Plugins > Local plugins > iMIS Bridge >
  *Open sync admin*) provides a connection test, a one-member manual sync (a Member
  ID is required) and test controls for pushing a single completion or module note.
* Every call made to iMIS is recorded in an audit log (viewable from the
  settings page), with credentials redacted; old entries are pruned on a
  configurable schedule.

## Requirements ##

* Moodle 5.1 to 5.3 LTS.
* PHP 8.2 or later (PHP 8.3+ on Moodle 5.2+).
* For the ATS SOAP bridge: the PHP `soap` extension, network access from the
  Moodle server to the ATS WSDL endpoint, and an ATS-issued API AuthToken (format
  `MO-xxxxxx`).
* For direct REST (including the tutorial group sync): network access to the iMIS
  site and a dedicated iMIS service account.

## Installing via uploaded ZIP file ##

1. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually ##

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/local/imisbridge

Afterwards, log in to your Moodle site as an admin and go to _Site administration
> Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## Configuration ##

After installation, configure the plugin under _Site administration > Plugins >
Local plugins > iMIS Bridge_:

> **Before entering any endpoint, turn the automatic writes off.** Completion and
> quiz pushes, the three login syncs and the three nightly tasks all default to
> enabled, so saving an endpoint first makes the plugin write straight away. See
> `docs/TESTING.md` §1.

* **Connection provider** — *ATS SOAP bridge* (default) or *Direct iMIS REST*.
* **Sync provider** — which provider runs the enrolment, cancellation and group
  syncs; blank follows the connection provider.
* **Registration sync (Direct iMIS REST)** — the registrations IQA, dry run (on by
  default), account creation, cancelled status codes, look-back days (default 7
  after the event ends; a one-member manual sync can override it) and the
  suspension guard.
* **iMIS WSDL URL** — the `wsmoodle.asmx?WSDL` endpoint for your environment
  (ask ATS for the correct host). Blank by default; no SOAP call is made until it
  is set. **Service endpoint URL (override)** forces the SOAP host when the WSDL
  embeds a different environment's address.
* **ATS API AuthToken** — the `MO-xxxxxx` token issued by ATS.
* **iMIS contact ID source** — the Moodle user field that holds the iMIS contact
  ID (username, ID number, or email). Defaults to username; set it to whichever
  field your identity provider fills with the iMIS ID (ID number where SAML2
  matches on `idnumber`).
* **Web service timeout** — how long to wait on iMIS before giving up.
* **Default credit type** and **Credit value course field** — used by the direct
  REST provider only, and only when the matching iMIS column settings are filled
  in. The ATS SOAP `MoodleUpdate` does not carry credit; iMIS derives it from the
  product.
* **iMIS REST connection** — base URL (the iMIS site root, no `/api`), service-account
  username and password. Needed for the REST provider **and** for the tutorial
  group sync.
* **Automatic sync controls** and **Scheduled task controls** — one switch per
  automatic write.
* **Tutorial group sync** — enable switch (off by default), dry run (on by
  default), removal guard, and the course-lookup IQA settings.
* **Audit log** — enable/disable logging, set the retention period (days), and
  optionally capture full request/response payloads (off by default) while
  debugging. Open the log from the *Open iMIS audit log* button.

Scheduled task times (all editable under _Site administration > Server >
Scheduled tasks_): enrolment sync 02:00, cancellation sync 02:15, group sync
02:30, tutorial group sync 02:45, audit-log cleanup 03:45.

## Documentation ##

* `docs/CHANGEOVER-CHECKLIST.md` — go-live: switching from the vendor plugin, and
  the tutorial group sync go-live.
* `docs/TESTING.md` — configuring and testing safely, including against production.
* `docs/TEST-CHECKLIST.md` — step-by-step pre-testing checklist.
* `docs/TEST-PLAN.md` — the living test matrix.
* `docs/PROVIDERS.md` — the connection-provider design.
* `docs/DIRECT-REST-ARCHITECTURE.md` — the direct iMIS REST design.
* `docs/VENDOR-ARCHITECTURE.md`, `docs/DESIGN-NOTES.md` — the vendor bridge and
  the lessons taken from it.
* `docs/IMPROVEMENT-PLAN.md` — the roadmap.

## License ##

2024 Vernon Spain

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.
