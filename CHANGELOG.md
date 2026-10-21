# Changelog

All notable changes to the iMIS Bridge plugin (`local_imisbridge`) are documented
in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.18.0] - 2026-10-21

### Added
- **Inbound "cancel enrolment" web-service function that suspends instead of
  unenrolling** (`local_imisbridge_cancel_enrolment`). The ATS cancellation path
  (`SendCancelledOrdersToMoodle`) routed to core `enrol_manual_unenrol_users`, which
  **hard-unenrols** — deleting that enrolment's grades and completion — the wrong
  outcome for a cancelled order. The new function takes `userid`+`courseid` and, for
  each of the user's enrolments in the course, **suspends** it (the enrolment row,
  grades and completion all remain; only access is removed) and **unassigns the role
  that enrolment granted** (scoped by enrol component + instance, so a manual admin
  override is kept). It never unenrols or deletes, and each entry runs in its own
  transaction, so an error cannot lose or half-apply data; failures are returned as
  warnings. Fully reversible by a later re-enrol.
  - New capability `local/imisbridge:cancelenrolment` (course context, no data-loss
    risk flag, granted to no role by default), exposed on the `iMIS bridge` service.
  - To adopt: grant the capability to the inbound service-account role and point the
    ATS `FN_ENROL_MANUAL_UNENROL_USERS` mapping at `local_imisbridge_cancel_enrolment`
    (see `docs/CHANGEOVER-CHECKLIST.md`).
  - Note: the live `invalidparameter` error on the cancellation call is not yet
    root-caused (the real ATS→Moodle payload has not been captured), so this is not
    presented as a guaranteed fix for that error — its purpose is the data-preserving
    suspend. Capture the actual request and reconcile its schema before relying on
    the remap to resolve the error.

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

[1.8.0]: https://github.com/verzog/imisbridge/releases/tag/v1.8.0
[1.7.0]: https://github.com/verzog/imisbridge/releases/tag/v1.7.0
[1.6.1]: https://github.com/verzog/imisbridge/releases/tag/v1.6.1
[1.6.0]: https://github.com/verzog/imisbridge/releases/tag/v1.6.0
[1.5.0]: https://github.com/verzog/imisbridge/releases/tag/v1.5.0
[1.4.2]: https://github.com/verzog/imisbridge/releases/tag/v1.4.2
[1.4.1]: https://github.com/verzog/imisbridge/releases/tag/v1.4.1
[1.4.0]: https://github.com/verzog/imisbridge/releases/tag/v1.4.0
