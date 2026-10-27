# Changelog

All notable changes to the iMIS Bridge plugin (`local_imisbridge`) are documented
in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-28

### Changed
- **Renamed to `local_membershipbridge`** (folder `local/membershipbridge`), so no
  part of the plugin is named after a third party's trademark. Everything that
  carried the old name follows:
  - namespaces;
  - settings (`local_membershipbridge/…`);
  - tables (`local_membershipbridge_log`, `local_membershipbridge_scorepush`);
  - capabilities (`local/membershipbridge:cancelenrolment`, `:readcompletions`);
  - web-service functions (`local_membershipbridge_cancel_enrolment`,
    `local_membershipbridge_get_completions_since`);
  - the external service, now *Membership bridge*.

### Added
- **Carry-over from `local_imisbridge` on install** (`classes/migration.php`). It
  copies:
  - settings;
  - the audit log and sent-score records, keeping their ids;
  - role grants of the old capabilities;
  - the tutorial-group memberships the plugin manages;
  - queued pushes;
  - the cancel function on hand-built web services.
  
  It then switches the old plugin's writes off. The steps (tokens, the ATS unenrol
  mapping, uninstalling the old plugin) are in `docs/CHANGEOVER-CHECKLIST.md` §M.

## [1.26.5] - 2026-10-27

### Changed
- **Public name is Membership Bridge.** The README title, its settings-page
  paths, the task and CLI log prefixes ("Membership Bridge: …") and the design and
  improvement-plan headings now use the plugin's display name, Membership Bridge,
  instead of "iMIS Bridge". The README has a trademarks notice: iMIS and Moodle are
  their owners' trademarks and this project is not affiliated with or endorsed by
  them. Nothing else changes: the component (`local_imisbridge`), settings, tables,
  capabilities, web-service functions and the *iMIS bridge* external service keep
  their names, so existing installations and ATS mappings are unaffected.

## [1.26.4] - 2026-10-26

### Fixed
- **A cancelled learner who registered again got the course welcome email a second
  time.** Moodle sends the welcome message on every manual `enrol_user()` call, not
  only for a new enrolment, and the registration sync reactivated a suspended
  enrolment through it. Reactivation now switches the existing enrolment back on and
  restores its Student role directly, so a learner is welcomed once, when first
  enrolled. Grades and completion are kept as before.

## [1.26.3] - 2026-10-26

### Fixed
- **Quiz score notes sent through the ATS bridge had no description.** They now carry
  the same wording as the direct REST transport: Description "Moodle course N score",
  Note "Score : Moodle course N", Note2 `score` and SourceCode `quiz`, so the
  Description column in iMIS Course Notes is filled in.
- **Course mapping overrides help text.** It now says the ATS bridge enrolment sync
  ignores these mappings, and points to *Push course id overrides* for sending pushes
  from an overridden course, instead of advising that pushes be left off.

## [1.26.2] - 2026-10-26

### Fixed
- **Direct REST notes still rejected (HTTP 500) after 1.26.1.** A full copy of a
  vendor-written note was accepted by iMIS, while the plugin's note carrying only the
  columns it sets was not. Notes are now sent as the complete record iMIS uses for
  them: every Activity column, the envelope `$type`/`EntityTypeName`, and .NET types
  on the numeric and boolean columns (`System.Decimal`, `System.Int32`,
  `System.Boolean`), with the 1.26.1 field layout.

## [1.26.1] - 2026-10-26

### Fixed
- **Direct REST notes rejected by iMIS (HTTP 500).** Quiz-score, activity-score and
  module-completion notes are now laid out as the vendor's `MOODLENOTE` rows, which
  iMIS accepts and shows in the Course Notes panel: the course number in
  `PRODUCT_CODE` (not `UF_1`), the activity name in `DESCRIPTION`, the note text in
  `NOTE` (`Module completed : <activity>` / `Score : <activity>`), its category in
  `NOTE_2`, the module type in `SOURCE_CODE`, and the date in `THRU_DATE` as well as
  `TRANSACTION_DATE`. ATS SOAP notes use the same wording.
- The REST test CLI (`cli/test_rest.php`) prints every field of one activity with
  `--imisid=ID --seqn=N`.

## [1.26.0] - 2026-10-26

### Added
- **iMIS ID format** setting (*Any value*, the default, or *Digits only*). With
  *Digits only*, a user whose username or ID number is not all digits — such as
  `admin` or an anonymised test account like `anon47` — is treated as having no iMIS
  ID: nothing is pushed for them, they get no login sync, and the results back-fill
  skips them and says why. Stops pushes for such accounts failing in iMIS (HTTP 500)
  and retrying. Does not apply when the iMIS ID source is email. Pushes and login
  syncs queued before the switch are checked again when they run, so their retries
  stop too.

## [1.25.0] - 2026-10-26

### Added
- **Results back-fill** (`cli/backfill.php`): queues pushes for one course's existing
  results as if they had just happened — course completions (with their real
  completion and start dates) and/or activity scores of the types ticked in *Push
  activity scores*. A dry run by default; `--execute` queues the pushes for cron.
  Options: `--course`, `--type=completions|scores|all`, `--member`. Pushes honour the
  push toggles and push course id overrides, and a score already sent and unchanged
  is not sent again (the back-fill uses the live score claim).

### Changed
- The activity score claim-and-queue step is now `observer::queue_score_push()`,
  shared by the `user_graded` observer and the back-fill (no behaviour change).

## [1.24.0] - 2026-10-25

### Added
- **Push course id overrides** (under Tutorial group sync, after Course mapping
  overrides): `moodleid=imisid` lines that replace the course number sent to iMIS
  by completion, quiz-score, activity-score and module-note pushes. A staging course
  can report itself as the course iMIS already holds results for (e.g. `19=2152`),
  so a completion updates that existing record as a live push would. Meant for
  testing; leave it empty in production.

## [1.23.0] - 2026-10-25

### Added
- **Course mapping overrides** (under Tutorial group sync): Moodle-side
  `EVENTCODE=courseid` lines checked before the iMIS `MoodleCourseLookup`, so a
  staging site can point an event at a test course without editing
  `MOODLE_PRODUCTS`. Used by the registration sync and the tutorial group sync; a
  mapping to a missing course is unmapped. Pushes to iMIS are unchanged. Meant for
  testing; leave it empty in production.

### Fixed
- Registration sync account creation no longer triggers a deprecation notice on
  Moodle 5.3: it uses `\core\user::create_user()` where that exists and falls back
  to `user_create_user()` on 5.1 and 5.2.

## [1.22.0] - 2026-10-23

### Added
- **One-member look-back override.** With Direct iMIS REST as the sync provider, the
  manual sync page has *Include events outside the look-back window*: for that one
  member, registrations for events that ended before the window are applied too
  (for example a cancellation processed after the course ended). It is unticked on
  every page load, is named in the confirmation, and dry run still applies. The
  nightly tasks always keep the window. The report now shows the `lookbackdays` used.
- *Test connection* lists each registration's end date.

### Changed
- The registration sync look-back now defaults to **7 days** after the event's end
  date (was 90). Registrations close before an event ends, so a short window still
  catches every normal change. Sites that saved 90 keep it until they change it.
- A failed iMIS REST call now records why: the reason iMIS gives in its error
  response (for example a missing IQA prompt or an unknown query) is added to the
  audit-log error and to the message on the sync page, as `HTTP 400: <reason>`.
  The full error body is kept when payload capture is on, including for bulk reads
  such as IQAs, whose successful bodies are still never stored. Token failures
  record the reason too.

## [1.21.0] - 2026-10-23

### Added
- **Sync provider setting.** Choose which provider runs the enrolment, cancellation
  and group syncs (the manual sync buttons, the login syncs and the nightly tasks),
  separately from the provider that pushes records. Blank, the default, follows the
  connection provider, so nothing changes on upgrade. Setting it to *ATS SOAP bridge*
  while pushing over *Direct iMIS REST* keeps the ATS syncs.
- **Registration sync over direct REST.** The REST provider can now sync: it reads a
  **Registrations IQA** (no prompts) and, per member and course, enrols a member with
  any active registration (creating the account first when allowed, or reactivating
  a suspended enrolment) and suspends a member whose registrations are all cancelled
  (grades and completion kept; nothing is unenrolled or deleted). A member and course
  with no rows is left alone. Event codes resolve to courses through the existing
  `MoodleCourseLookup` IQA. Settings: the IQA path (blank = off), dry run (on by
  default), account creation, cancelled status codes (default `C`), a look-back on
  the event end date (default 90 days) and a per-course suspension guard. Rows
  flagged inactive by the source are ignored, and an empty read changes nothing.
  A blank status is active; a status that is neither blank nor cancelled is
  reported and neither enrols nor suspends. Cancellation also suspends future-dated
  enrolments and ones owned by a disabled plugin. A failed account creation is
  logged and the run carries on. One read of the IQA serves both the enrolment and
  cancellation passes of a login sync. Groups stay with the tutorial group sync,
  and under REST the ATS group sync task skips without moving its watermark.
  Go-live steps in `docs/CHANGEOVER-CHECKLIST.md` §R.

### Changed
- The cancellation web service and the registration sync share one suspension
  routine (`\local_imisbridge\enrolment`), so both suspend in exactly the same way.
- The login sync, the nightly sync tasks and the manual sync page check that the
  *sync* provider is configured, rather than the push provider.
- With the iMIS contact ID source set to username, an iMIS ID now resolves only to
  a local account, never to a remote MNet one.

## [1.20.0] - 2026-10-22

### Added
- **Activity score push (opt-in per activity type).** A new *Push activity scores*
  checklist lists every gradable activity type installed on the site — built from
  each activity's own `FEATURE_GRADE_HAS_GRADE` declaration, so third-party
  activities such as `mod_casestudy` appear with no special-casing. When an activity
  of a ticked type is **graded** (Moodle's `user_graded` event), the learner's score
  is sent to iMIS as an append-only `MOODLENOTE` ("Score: <activity>",
  `Note2 = score`) carrying the raw grade, the activity's id, and PASS/FAIL against
  its pass mark (COMPLETE when it has none). This covers tutor-marked work such as a
  case study, whose mark usually arrives *after* the learner completes it — too late
  for the module-completion note. None are ticked by default; quiz is not listed,
  as quiz scores keep their own push on submission. Only the activity's own grade
  is sent (not course or category totals, not manual gradebook items).
- **Unchanged regrades are not re-sent, and only the latest grade is.** A new
  `local_imisbridge_scorepush` table keeps one row per learner and grade item with
  the last grade queued and a **generation** number that increases on every change;
  each queued push carries the generation it was claimed with and is sent only if
  it is still current. So an unchanged regrade sends nothing; in a burst (A, B, A)
  only the latest push is sent; and **clearing a grade cancels** any push still
  queued (re-entering the grade later sends it). Claims for a learner and grade item
  are serialised with a lock and re-read the stored grade, so concurrent grading
  requests cannot leave a stale value. The table is covered by the privacy provider
  (export and deletion), and erasure also deletes the learner's **queued** score
  pushes, which hold their member ID and score; a push whose row is gone is never
  sent.

## [1.19.7] - 2026-10-22

### Added
- **Per-member dry-run output for the tutorial group sync.** With *dry run* on, the
  task log now has one line per member the sync would add or remove, for groups it
  would create **and** groups that already exist:
  `group 'Tutorial A' (imis:...): would add user 123 (iMIS 20001)`. Previously an
  existing group's changes appeared only as totals, so the dry run could not show
  *who* a later run would move. A member being removed is no longer in the iMIS
  panel, so their iMIS id is read from their Moodle account. Live runs log as
  before.

### Changed
- **The REST settings heading is retitled** from *Direct iMIS REST provider* to
  *iMIS REST connection (tutorial group sync and Direct iMIS REST provider)*, and
  its help text now leads with the fact that the tutorial group sync requires these
  settings whichever connection provider is selected. The old title suggested they
  belonged to the REST provider only, which led to the base URL being left blank and
  the tutorial sync silently skipping.

## [1.19.6] - 2026-10-22

### Added
- **Tutorial group sync go-live section** (`docs/CHANGEOVER-CHECKLIST.md` §T):
  iMIS-side prerequisites, Moodle settings, a dry-run-first procedure, the skip
  reasons to look for in the task log, the empty-read abort and removal guard, the
  schedule, and rollback. The tutorial sync is signed off separately from the vendor
  swap.
- Test-plan sections for the tutorial group sync (§L) and the
  `local_imisbridge_cancel_enrolment` function (§M), plus a module-note row (D6).

### Changed
- **Documentation consistency review.** Every doc checked against the code; fixes:
  - Quiz scores are documented as what they are since 1.16.0 — an append-only
    `MOODLENOTE` via `CreateActivity`, never `MoodleUpdate` (several docs and test
    rows still said `MoodleUpdate`).
  - Credit is documented as **not sent over SOAP** (`MoodleUpdate` carries the fixed
    `type=Completion` and an empty `value`; iMIS derives the CEU from the product).
    The credit settings apply to the direct REST provider only.
  - A blank Member ID on the manual sync page is documented as refused (1.19.1), not
    as an all-users sync.
  - The REST settings are documented as also driving the tutorial group sync,
    whichever connection provider is selected; clearing the WSDL does not stop it.
  - `TESTING.md` no longer claims the WSDL defaults to production (it is blank);
    toggle lists and defaults now match the settings (module-completion push and
    tutorial sync default off).
  - README brought up to date (both providers, tutorial sync, cancellation function,
    task schedule, docs index) and the long-removed *Admin iMIS ID* setting dropped.
  - In-plugin create/enrol functions marked superseded by the core-functions cutover
    (`IMPROVEMENT-PLAN.md`, `DESIGN-NOTES.md`, `VENDOR-ARCHITECTURE.md`, `TEST-PLAN.md`).
  - `DIRECT-REST-ARCHITECTURE.md` records the verified IQA prompt convention
    (`&Parameter=`), the base-URL form and the `MOODLE_PRODUCTS` lookup.
- **Settings help text corrected:** the tutorial sync switch no longer says
  "hourly"; the REST heading no longer says it applies only to the REST provider;
  the removal-guard text states the 10-member floor; the credit settings say they
  are REST-only; the REST base URL and connection-provider descriptions say the
  tutorial sync uses the REST settings too.
- **Sync admin page schedule list** no longer labels the times "UTC" (scheduled
  tasks run in the server timezone), says they are defaults, and now lists the
  tutorial group sync and audit-log cleanup tasks as well.

## [1.19.5] - 2026-10-22

### Changed
- **The tutorial-group sync now defaults to nightly instead of hourly.** The task
  (`\local_imisbridge\task\sync_tutorial_groups`) now runs once a night (02:45) rather
  than every hour. Sites that have not customised the task schedule pick up the new
  default on upgrade; the cadence remains editable per site in
  *Site admin → Server → Scheduled tasks*.

## [1.19.4] - 2026-10-22

### Fixed
- **The 1.19.3 audit-log column upgrade now succeeds.** Widening the `operation`
  column failed at upgrade time with a `ddl_dependency_exception` because the column
  carries a non-unique index (`…_ope_ix`), and the database will not alter an indexed
  column. The upgrade step now drops that index, widens the column to `char(255)`,
  then re-adds the index. Each step is guarded so it re-runs cleanly on sites where
  the 1.19.3 attempt aborted part-way.

## [1.19.3] - 2026-10-22

### Fixed
- **Audit log no longer fails to write on long REST operation names.** A REST
  operation is logged with its `METHOD /path` — for an IQA read, the full
  `GET /api/iqa?QueryName=…&Parameter=…&Limit=…&Offset=…` URL — which overflowed the
  `operation` column's `char(100)`, so the insert threw
  (`value too long for type character varying(100)`). The write is best-effort and
  was caught, so syncs still completed, but no row was recorded. The column is now
  `char(255)` (with an upgrade step) and the logger truncates the operation
  defensively, so a long operation name can never break logging.

## [1.19.2] - 2026-10-22

### Fixed
- **Tutorial group sync: course lookup now sends the IQA's required `Code` prompt.**
  The `MoodleCourseLookup` IQA has a **required** `Code` prompt, so the unprompted
  full-table call the resolver was making returned HTTP 400 ("Missing required
  parameter: Code") and aborted every run. The resolver now looks a code up one at a
  time, sending the full product code `{code}/REG` as the IQA parameter
  (`&Parameter=…`, the form iMIS requires) and reading the `Description` column as the
  course id — confirmed against live iMIS (`ATS_BRIDGE/REG` → `7`). Results are cached
  per code for the run.

## [1.19.1] - 2026-10-22

### Changed
- **The manual sync on the admin page now requires a Member ID.** Running an
  enrolment/cancellation/group/all sync with the Member ID field blank previously
  issued an *all-user* batch (`sync_*(null)`), which is slow enough to hit the web
  request timeout and is never the intent of an on-demand run. It now returns
  "Member ID required" and does nothing instead. All-user syncs remain the job of the
  nightly scheduled tasks. The connection test is unaffected (it still runs without a
  Member ID).

## [1.19.0] - 2026-10-22

### Added
- **Tutorial group sync (iMIS MoodleTutorialGroup panel → Moodle course groups).**
  A new hourly scheduled task (`\local_imisbridge\task\sync_tutorial_groups`) reads
  the iMIS `MoodleTutorialGroup` panel directly over the iMIS REST API and makes each
  course's groups match it. iMIS is the source of truth and the sync is one-way:
  people in the rows are added to the Moodle group, people removed in iMIS are
  removed from it. The engine is built to be safe and self-contained:
  - It runs **server-side as cron** using Moodle's internal group API, so it needs
    **no** web-service user and **no** group web-service capabilities.
  - It only ever touches **its own** groups (idnumber `imis:{code}:{slug}-{hash}`,
    where the hash is derived from the case-insensitive group name so distinct
    names never collide and the identifier stays within Moodle's 100-char limit,
    inside a per-course "Tutorials (iMIS)" grouping) and **its own** memberships
    (added with component `local_imisbridge`); teacher-made groups and manual
    members are never changed or removed.
  - Reconciliation is global, keyed by (course, idnumber): every group the plugin
    has created is discovered site-wide, so a group whose rows all disappear — or
    whose code is remapped to another course — is still emptied, never left stale.
  - Users are matched by the configured iMIS-id source (username/idnumber/email);
    those with no account or not enrolled are skipped and logged, then picked up on a
    later run once enrolled.
  - Safety: an **off-by-default** master switch, a **dry-run** mode (on by default)
    that logs every change without writing, a **removal guard** that skips a group's
    removals when they would exceed a configured share of its members, and a rule
    that an empty read makes no changes at all (never empties every group).
  - The code→course mapping is one replaceable function (`course_resolver`), reading
    the `moodle_products` lookup IQA today so it can follow the Causeis program rework
    without engine changes.
  - The sync engine is transport-agnostic (depends only on a `reader` and a
    `course_resolver`), with the direct-REST reader shipped today; it reuses the
    existing REST client's OAuth, paging and property helpers.

## [1.18.0] - 2026-10-21

### Added
- **Inbound "cancel enrolment" web-service function that suspends instead of
  unenrolling** (`local_imisbridge_cancel_enrolment`). The ATS cancellation path
  (`SendCancelledOrdersToMoodle`) routed to core `enrol_manual_unenrol_users`, which
  **hard-unenrols** — deleting that enrolment's grades and completion — the wrong
  outcome for a cancelled order. The new function takes `userid`+`courseid` and, for
  each of the user's enrolments in the course, **suspends** it (the enrolment row,
  grades and completion all remain; only access is removed) and **unassigns the role
  that enrolment grants** (the enrolment's default role, or a role id you pass, so
  another role such as a manual teacher override is kept). It never unenrols or
  deletes, and each entry runs in its own
  transaction, so an error cannot lose or half-apply data; failures are returned as
  warnings. Fully reversible by a later re-enrol.
  - New capability `local/imisbridge:cancelenrolment` (course context, no data-loss
    risk flag, granted to no role by default), exposed on the `iMIS bridge` service.
  - To adopt: deploy and upgrade the plugin, grant the capability to the inbound
    service-account role, add `local_imisbridge_cancel_enrolment` to the external
    service the ATS token uses, and point the ATS `FN_ENROL_MANUAL_UNENROL_USERS`
    mapping at it (see `docs/CHANGEOVER-CHECKLIST.md`).
  - **Verified in production (2026-10-22).** The remap resolved the original
    `invalidparameter` error (core `enrol_manual_unenrol_users` expects a manual-only
    `ueid`, which the ATS does not send; the new function takes `userid`+`courseid`
    instead). The follow-on `accessexception` was simply the new function not yet
    being listed on the token's external service; adding it there fixed the call. A
    live cancellation then suspended the enrolment and removed the role while the
    learner's grades and completion survived intact, confirming the data-preserving
    behaviour end-to-end.

## [1.17.3] - 2026-10-21

### Changed
- **Quiz-score pushes (the "Moodle course N score" MOODLENOTE) now carry the raw
  quiz grade, not a percentage.** Previously a quiz submission sent the attempt
  percentage (a full-marks attempt went out as `100`); it is now sent as the
  learner's actual quiz grade, rescaled to the quiz's maximum grade (8/10 marks on
  a quiz graded out of 100 is `80`; out of 10 is `8`). This matches the raw grades
  the module-completion notes now send, and the vendor's historical item grades.
  Pass/Fail status is unchanged (still derived from the attempt percentage against
  the quiz pass mark). Backed by a new pure `observer::quiz_raw_grade()` helper
  with unit tests.

## [1.17.2] - 2026-10-21

### Changed
- **Module-completion notes (MOODLENOTE) now carry the learner's raw gradebook
  grade, not a percentage.** Previously a 10/10 activity was sent as `100`; it is
  now sent as `10`, matching the vendor's historical Course Notes (which carried
  raw item grades such as `6.67`, `5.00`, `17.00`). Applies to both the automatic
  `course_module_completion_updated` observer and the "Push activity note (test)"
  control. Completion pushes (`MoodleUpdate`) are unchanged — the course score
  stays a percentage of the course total, as the vendor's "Moodle course N score"
  note does. The test output's score-resolution trace now labels the value it
  sent ("raw gradebook grade" vs "percent of course total").

## [1.17.1] - 2026-10-21

### Changed
- **The push test controls now warn when a member ID does not resolve to exactly one
  Moodle user.** Both "Push completion (test)" and "Push activity note (test)" derive
  the score (and, for the note, the status) by looking up the Moodle user whose
  configured Member ID source field equals the entered member ID. When that lookup
  found no user, or more than one, the controls silently sent a placeholder score of
  `0`, which was easily mistaken for a real grade. They now still send the record but
  display a warning naming the field queried and whether the match was zero or
  non-unique, so an operator can tell a wrong ID-source setting or a missing/duplicate
  field value from a genuine zero score. (The automatic completion/quiz observers are
  unaffected — they use the authoritative user from the event.)
- **Both push test outputs now show how the score was resolved.** The "Push activity
  note (test)" and "Push completion (test)" result panes trace the member→user match,
  the grade item (the activity's grade item, or the course total) and grademax, and the
  learner's gradebook **final grade** — so a score of `0` can be read straight off as
  "no single user", "no grade item", or "gradebook final grade is empty" (a mark on an
  activity attempt does not count until it reaches the gradebook; a hidden, locked or
  not-yet-regraded grade reads as empty). Backed by new public
  `observer::module_grade_detail()` and `observer::course_score_detail()` helpers,
  unit-tested for the with-grade, without-grade, and no-grade-item cases.

## [1.17.0] - 2026-10-21

### Fixed
- **`createActivity` (MOODLENOTE) pushes used the wrong SOAP operation name.** The
  call sent lowercase `createActivity`, but the ATS WSDL operation is PascalCase
  `CreateActivity` and SOAP operation names are case-sensitive — so every note push
  faulted and no supplementary note (quiz score / module completion) ever reached
  iMIS. Now matches the vendor's proven call, including dropping empty fields from
  the payload before sending.

### Added
- **"Push activity note (test)" control on the manual sync page.** Enter a member ID
  and course, list the course's graded activities, then push one real `MOODLENOTE`
  (`createActivity`) for a chosen activity — the note-path equivalent of the existing
  "Push completion (test)" control, for verifying the write path without waiting for a
  live submission and completion event. Gated on the `push_module_completions` opt-in.
- `observer::module_note_data()`, a pure, unit-tested helper that builds the module-note
  push data, shared by the `course_module_completion_updated` observer and the new
  test control.

## [1.16.0] - 2026-10-21

### Fixed
- **Completion pushes now land in iMIS.** The SOAP `MoodleUpdate` was sending the
  configured credit type (default `CEU`) in the `type` field, `0` in `value`, and
  `Pass` in `status`. The ATS service treats `type` as a fixed record
  discriminator and only writes the completion onto the registration when it is
  the literal **`Completion`** — so our pushes returned `ResultCode 0` (accepted)
  but silently landed nothing. Now matches the vendor's proven contract: `type`
  is always `Completion`, `value` is empty, and `status` is upper-cased to the ATS
  vocabulary (`COMPLETE` for a finished course, `PASS`/`FAIL` for a graded
  result). Verified against the reconstructed vendor source (`local_imis`).

- **SOAP quiz-score pushes are now append-only MOODLENOTE records, not
  `MoodleUpdate`.** With `type='Completion'` now effective, routing a graded quiz
  submission through `MoodleUpdate` would have let a re-attempt overwrite the
  course's canonical `COMPLETE` status and final grade. `imis_client::push_score()`
  now writes a `createActivity`/MOODLENOTE record (as the REST transport and the
  vendor already did), preserving the invariant that only a course-completion
  event may write the completion record.

### Changed
- The MoodleUpdate and MOODLENOTE parameter arrays are now built by pure,
  unit-tested helpers (`imis_client::build_moodleupdate_params()` and
  `build_note_params()`). `update_activity_record()` keeps its original public
  signature — `$credittype` / `$creditvalue` are retained for backward
  compatibility but are no longer transmitted (the ATS `MoodleUpdate` call never
  carried them; iMIS derives the credit from the product). The `credit_type` /
  `credit_field` settings continue to feed the optional REST extra-columns mapping.
- `docs/VENDOR-ARCHITECTURE.md` now documents the full `MoodleUpdate` /
  `createActivity` field contract learned from the vendor source.

## [1.8.0] - 2026-10-19

### Fixed
- **`MoodleUpdate` now sends the numeric Moodle course id as `productID`.** Per the
  authoritative ATS bridge spec, `productID` is the numeric Moodle Course ID
  (iMIS maps it to the iMIS product code via the `MOODLE_PRODUCTS` lookup table).
  The completion/quiz observers previously sent the course **idnumber** (falling
  back to shortname), so activity records would have landed under a different
  product and not matched the vendor's existing history. `observer::course_number()`
  now returns `$course->id`. The upgrade drops any `push_activity_task` adhoc
  tasks queued before 1.8.0 (they carry the old idnumber/shortname value);
  affected completions can be re-sent via a manual sync or the bulk pull web
  service.
- **Course completion score path no longer fatals on Moodle 5.x.**
  `observer::course_score()` called `grade_get_course_grades()`, which no longer
  exists in Moodle 5.1+, so a completion push with a score would have thrown
  "undefined function". Rewritten to use `grade_item::fetch_course_item()` /
  `grade_grade::fetch()`, and now covered by a unit test.

### Removed
- Dead SOAP client methods that targeted operations **not** exposed by
  `wsmoodle.asmx` (they would fault against the bridge and were unused):
  `get_token()` / `get_service_token()` (`getToken`), `check_authorization()`
  (`checkAuthorization` — the real bridge method is `checkSSOFilter`), and
  `encrypt()` / `decrypt()` (`asiEncrypt` / `asiDecrypt`). These belong to the
  separate ATS SSO application, not this SOAP endpoint.
- The **Admin iMIS ID** setting (`admin_imis_id`), whose only consumer was the
  removed `get_service_token()`. The ATS API AuthToken is the credential for all
  secured calls. Any stored value is simply ignored after upgrade.

## [1.7.0] - 2026-10-19

### Added
- **Service endpoint override.** A new optional *Service endpoint URL (override)*
  setting routes SOAP requests to a specified `.asmx` endpoint instead of the
  address embedded in the WSDL. PHP's `SoapClient` sends requests to the
  `<soap:address location>` inside the WSDL, not to the host the WSDL was fetched
  from, so a staging WSDL that embeds a production address would otherwise send
  every call — including writes — to production. Leave blank to use the WSDL's
  address (unchanged behaviour).
- The read-only connection test (CLI and admin page) now reports the endpoint
  requests will actually be sent to — the override, or the address read from the
  WSDL — so a staging-to-production mismatch is caught before any write.

## [1.6.1] - 2026-10-15

### Fixed
- Align the SOAP client with the published `wsmoodle.asmx` WSDL contract:
  - `MoodleUpdate`: send the contract's camelCase `userId` / `productId`
    parameters (were `UserID` / `productID`, which the document/literal service
    would not bind), and read the `ResultsBase` response (was a non-existent
    `MoodleUpdateResult`).
  - `UpdateMoodleGroups`: use `CourseId` / `LastUpdated` (were `CourseID` /
    `lastUpdated`), send the `AuthToken`, and read the `ResultsBase` response.
  - `SendNewOrdersToMoodle` / `SendCancelledOrdersToMoodle`: send the required
    `AuthToken`.
  - `GetBridgeSettings`: send the `AuthToken` when one is configured (still
    allowing a token-less connectivity check).
  - `MoodleGetUserProfileByToken`: use the contract's lowercase `token`
    parameter and no `AuthToken`.
  - `getIQARows`: pass the single `jsonParams` object (`QueryPath`,
    `QueryFilters`, `PageSize`, `PageOffset`) the WSDL defines.

### Added
- `imis_client::setup_bridge()` wrapping the `SetupBridge` operation, returning
  the service's log lines.

## [1.6.0] - 2026-10-13

### Added
- **Bulk completions/grades pull web service.** A new read-only web service
  function `local_imisbridge_get_completions_since` lets iMIS poll Moodle for
  every course completion and final course grade changed since a given time,
  in stable id order with a paging cursor (`afterid` / `nextafterid` / `hasmore`).
  iMIS makes the call, so no extra iMIS API user licence is needed. Exposed
  through a pre-built, restricted "iMIS bridge" service and gated by a new
  `local/imisbridge:readcompletions` capability (granted to no role by default —
  assign it to the dedicated web service user only). Deleted and suspended
  users are excluded.

## [1.5.0] - 2026-10-13

### Added
- **iMIS audit log.** Every SOAP call to iMIS is now recorded (operation, user,
  product, status, any fault, timestamp) in a new `local_imisbridge_log` table,
  viewable and filterable on a new admin page (_iMIS Bridge_ settings → Open iMIS
  audit log). Credentials (AuthToken, session tokens, `MO-` tokens) are redacted.
- **Opt-in payload capture.** A _Capture full request/response_ setting (off by
  default) additionally stores the full SOAP request/response XML for debugging
  the wire format; leave it off in normal operation to avoid holding personal
  data at rest.
- **Configurable retention.** A daily task prunes log rows older than a
  configurable number of days (default 90; 0 keeps them indefinitely).
- The privacy provider now exports and erases a user's audit-log rows.

## [1.4.2] - 2026-10-13

### Added
- The manual sync admin page now shows a confirmation step before running any
  enrolment, cancellation, group or full sync, warning explicitly when no
  contact ID is set (which syncs **all** users against the production iMIS).

## [1.4.1] - 2026-10-12

### Changed
- CI: rewrote the GitHub Actions matrix to the Moodle 5.1–5.3 LTS baseline —
  branch-aware (MOODLE_501/502/503_STABLE plus a non-blocking Moodle `main`
  cell), on PostgreSQL 17 and MariaDB 11. The retired Moodle 5.0 cells are
  removed. Grunt now runs only on the 5.1 cells.
- Raised the minimum Moodle version to 5.1 (`$plugin->requires` = `2025100600`)
  and declared `$plugin->supported = [501, 503]`. The `requires` for 5.1 is a
  minimum; Moodle 5.0 is no longer a declared target.
- Updated the README and testing-guide requirements to Moodle 5.1–5.3.

## [1.4.0] - 2026-09-21

Open-source release preparation. All changes are backward-compatible: existing
installations keep their stored configuration and their current on-the-wire
behaviour.

### Added
- **Configurable iMIS contact ID source** — a new *iMIS contact ID source*
  setting (`imis_id_source`) lets sites choose which Moodle user field holds the
  iMIS contact ID: username, ID number, or email. Defaults to username, so
  existing installs are unaffected. Observers resolve the ID through the new
  `util::get_imis_id()` helper.
- **Configuration guard** — `util::is_configured()` short-circuits every
  automatic write (the login/completion/quiz observers, the three nightly tasks,
  and the queued adhoc tasks) when no WSDL URL is set, so a fresh install is a
  clean no-op instead of raising repeated cron errors.
- Unit tests for the ID-source resolver, the configuration guard, and the
  observer paths that use them.

### Changed
- The shipped **iMIS WSDL URL** default is now blank instead of an
  organisation-specific ATS host, so a fresh install never points at another
  site's endpoint. Existing installs keep their stored URL.
- Generalised the privacy metadata string, `README.md`, and `docs/TESTING.md` to
  describe the configurable identifier rather than assuming the username.

[1.8.0]: https://github.com/verzog/membershipbridge/releases/tag/v1.8.0
[1.7.0]: https://github.com/verzog/membershipbridge/releases/tag/v1.7.0
[1.6.1]: https://github.com/verzog/membershipbridge/releases/tag/v1.6.1
[1.6.0]: https://github.com/verzog/membershipbridge/releases/tag/v1.6.0
[1.5.0]: https://github.com/verzog/membershipbridge/releases/tag/v1.5.0
[1.4.2]: https://github.com/verzog/membershipbridge/releases/tag/v1.4.2
[1.4.1]: https://github.com/verzog/membershipbridge/releases/tag/v1.4.1
[1.4.0]: https://github.com/verzog/membershipbridge/releases/tag/v1.4.0
