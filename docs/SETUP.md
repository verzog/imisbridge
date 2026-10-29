# Setting up Membership Bridge for your iMIS

A guide for any organisation using iMIS. It covers what to build in iMIS and which
settings to fill in. SCCA's own go-live runbook is `CHANGEOVER-CHECKLIST.md`; this
guide is the general version.

All settings are under *Site administration → Plugins → Local plugins → Membership
Bridge*. Every feature that writes to Moodle or iMIS is off, or in dry-run mode,
until you turn it on.

## 1. Install

1. Install the plugin zip from the GitHub release (*Site administration → Plugins →
   Install plugins*), or unzip it into `local/membershipbridge`, then complete the
   upgrade.
2. Moodle 5.1 to 5.3 is supported.

## 2. Choose how Moodle talks to iMIS

*Connection provider* chooses how records are sent to iMIS:

- **ATS SOAP bridge**, if your iMIS is connected to Moodle through ATS's
  `wsmoodle.asmx` web service: set *ATS SOAP bridge WSDL URL* and *ATS API
  AuthToken*, which ATS gives you.
- **Direct iMIS REST**, to talk to the iMIS REST API with no middleware. Set *iMIS
  REST base URL* (your iMIS site address, for example `https://yourorg.imiscloud.com`),
  and a dedicated iMIS service account's username and password. Use an account
  made for this, not a person's login.

The tutorial group sync and the REST registration sync always use the REST
settings, whichever provider sends records. `PROVIDERS.md` describes the design.

## 3. Match Moodle users to iMIS members

*Member ID source* says which Moodle user field holds the member's iMIS ID:
username (the default), ID number or email. *iMIS ID format* can limit it to digits
only. Users without an ID in that field are skipped.

## 4. Send results to iMIS

Turn on what you want sent: course completions, quiz scores, activity completions,
and scores for the activity types ticked in *Push activity scores*. Each is queued
and retried on failure. To send one course's existing results once, use the
back-fill (a dry run unless `--execute` is given; cron then sends what it queued):

```bash
php local/membershipbridge/cli/backfill.php --course=ID          # dry run
php local/membershipbridge/cli/backfill.php --course=ID --execute
```

## 5. Map events to Moodle courses

The tutorial group sync and the REST registration sync need to know which Moodle
course an iMIS event belongs to. Choose one under *Course mapping*:

- **Moodle course ID number** (nothing to build in iMIS). Set each course's *Course
  ID number* (*Course settings → General*) to its iMIS event code, for example
  `ACCD_26`. If two courses share an ID number, that event is left unmapped.
- **iMIS lookup query.** Build an IQA that takes the code as its one prompt and
  returns the Moodle course id in a `Description` column (a `Code` column, if it
  has one, must equal the looked-up code). Tick *Available via REST API*. Set
  *Course lookup IQA* to its path, for example `$/MyOrg/Events/MoodleCourseLookup`.
  If your lookup is keyed by a product code such as `ACCD_26/REG` rather than the
  event code, set *Registration product suffix* to `/REG`.

Either way, *Course mapping overrides* (`CODE=courseid` lines) win, which is useful
for pointing an event at a test course on a staging site.

With the iMIS lookup chosen and *Course lookup IQA* blank, both syncs stop with an
error instead of treating every event as unmapped.

## 6. Tutorial group sync (optional)

Mirrors tutorial groups built in iMIS into Moodle course groups each night.

**In iMIS**, create a multi-instance panel on the contact (Party), ticked
*Available via REST API*, with:

| Property | Holds | Setting, if your name differs |
|---|---|---|
| `ID` | the member's iMIS ID (added by iMIS) | *Panel field: member ID* |
| `Code` | the event code | *Panel field: event code* |
| `Moodlegroupname` | the tutorial group name | *Panel field: group name* |
| `Tutor` (optional) | Yes/No or text; ticked for the group's tutor | *Tutor field* |

The panel name defaults to `MoodleTutorialGroup`; set *Tutorial panel* if yours is
different. If you add `Tutor` to a panel that already has rows, fix the old rows'
empty value straight away (see `imis/README.md`).

To build the groups in iMIS, `imis/tutorial-groups.html` is a ready-made page for an
event dashboard tab. Set the names in its *Site settings* block to match yours.

**In Moodle:**
1. Choose the course mapping (§5).
2. Set *Tutor field* and *Tutor role* if you use tutors.
3. Tick *Enable tutorial group sync* and leave *dry run* on.
4. Run it and read the log:
   ```bash
   php admin/cli/scheduled_task.php --execute='\local_membershipbridge\task\sync_tutorial_groups'
   ```
5. When the dry run shows what you expect, turn *dry run* off.

The sync only changes its own groups (ID numbers starting `imis:`), its own
memberships and the tutor roles it gave. It skips a run that reads no rows, and
holds back large removals (*removal guard*).

## 7. Registration sync over REST (optional)

Enrols active registrants and suspends cancelled ones, read from iMIS directly.

1. Build an IQA listing current registrations with the columns `ID`, `EventCode` and
   `Status` (plus `FirstName`, `LastName` and `Email` if Moodle should create missing
   accounts), ticked *Available via REST API*, with no required prompts.
2. Set *Registrations IQA* to its path, and *Sync provider* to Direct iMIS REST.
3. Choose the course mapping (§5).
4. Leave *Registration sync: dry run* on, check the log, then turn it off.

## 8. Before going live

- Test against a staging copy of Moodle and iMIS first. `TESTING.md` covers
  testing safely, including against production.
- Keep every dry run on until its log shows what you expect.
- The *Integration audit log* (on the plugin's settings page) records each call to iMIS.
