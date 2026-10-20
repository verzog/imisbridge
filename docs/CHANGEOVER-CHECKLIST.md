# iMIS plugin changeover checklist

Confirming a successful switch from the vendor `local_imis` plugin to
`local_imisbridge` on the live Moodle. Use alongside `TEST-CHECKLIST.md`
(setup/connectivity) and `VENDOR-ARCHITECTURE.md` (what the vendor did).

The crux of "successful" is: (B) the new plugin writes the **same** iMIS records
the vendor did, (C) inbound create/enrol still works, and (E) **only one** plugin
writes at a time. Everything else supports those.

---

## What is the same, and what differs

| Concern | Vendor `local_imis` | New `local_imisbridge` |
|---|---|---|
| Completion → iMIS | `course_completed` → `MoodleUpdate` (canonical MOODLE record) | `course_completed` → `MoodleUpdate` — **same** |
| Score → iMIS | `user_graded` → `createActivity` (MOODLENOTE) | `quiz_attempt_submitted` → `MoodleUpdate` |
| Module completions | `course_module_completion_updated` → MOODLENOTE | **not tracked** |
| Inbound provisioning | `local_imis_*` web-service functions (in-plugin) | **none in-plugin** → Moodle **core** functions on a hand-built service |
| Identity field | `$user->idnumber` | `imis_id_source` setting (set to **ID number**) |
| productID | numeric Moodle course id | numeric Moodle course id — **same** |

Verified from the code: the new plugin's observers are `user_loggedin`,
`course_completed`, and `mod_quiz\event\attempt_submitted`; its only web-service
function is `local_imisbridge_get_completions_since` (a read-only completions
pull on a restricted service) — it exposes **no** create/get/update/enrol/unenrol
functions.

---

## Three parity decisions that determine success

Resolve these before cutover — they are behaviour changes, not bugs:

1. **Score coverage narrows.** The vendor pushed on **any** `user_graded`
   (every gradebook activity); the new plugin pushes on **quiz submission only**.
   If any course awards credit via a non-quiz graded activity (assignment, SCORM,
   lesson, …), that score will **not** flow to iMIS. Decide whether quiz-only is
   acceptable, or add a `user_graded` observer / make the trigger configurable.
2. **The MOODLENOTE audit trail stops.** The vendor wrote supplementary
   `MOODLENOTE` records for module completions and per-grade notes; the new plugin
   writes **none**. Confirm no iMIS report or process depends on those records.
3. **Inbound behaviour changes.** Moodle's core `core_user_create_users`
   **throws when the user already exists** (the vendor returned "already exists =
   success"), and the core functions have no idempotency or create/enrol
   decoupling. Confirm ATS's create+enrol sequencing will not strand existing
   members on a create error — this was the original vendor outage pattern.

---

## A. Pre-cutover — new plugin configured, all writes OFF

- [ ] `local_imisbridge` installed on the live Moodle.
- [ ] **iMIS ID source = ID number** (SAML matches `UNIQUEID` → `idnumber`, so that
  is the authoritative field; see `DIRECT-REST-ARCHITECTURE.md` §3).
- [ ] WSDL URL + ATS AuthToken set for the intended iMIS/bridge environment; the
  `service_endpoint` override set if the WSDL embeds a different environment's
  address (see the trap in `TESTING.md`).
- [ ] **Every write toggle disabled** to start: completion push, quiz push, the
  three login syncs, and the three nightly tasks — so nothing fires before you are
  ready.
- [ ] The three parity decisions above are resolved.

## B. Outbound parity — the new plugin must write the SAME record

- [ ] On a test course, complete it and confirm the iMIS activity created matches
  what the vendor produced: **same productID (numeric course id), status, score,
  start/completion/grant dates, credit**, under the right contact (`idnumber`).
  Compare against an existing vendor-written record for continuity with the
  history already in iMIS.
- [ ] Submit a graded quiz and confirm the score lands correctly.
- [ ] Confirm each call in the **audit log** (operation, status, any fault).

## C. Inbound provisioning — verify the core-functions service

- [ ] On the inbound web-service (e.g. "ATS iMIS Bridge"), the service account's
  role holds `moodle/user:create`, `moodle/user:update`, `moodle/user:viewdetails`,
  `enrol/manual:enrol`, `enrol/manual:unenrol`, **and `moodle/role:assign`** (the
  last is required by `enrol_manual_enrol_users` and is easy to miss).
- [ ] Service set to **authorised users only**, with the service account added; not
  exposed on the mobile service.
- [ ] Web services + the **REST protocol** enabled; the REST endpoint
  (`/webservice/rest/server.php`) reachable from ATS (allowlist ATS egress IPs if
  inbound is restricted).
- [ ] End-to-end: ATS pushes a registration → a **new** user is created and
  enrolled into the mapped course with the Student role, **including a hidden
  course** (the core function avoids the vendor's `require_login` trap).
- [ ] Re-send for an **existing** user → confirm it does not error-and-strand the
  enrol (decision 3).

## D. ATS-side repoint (iMIS `MOODLE_SETTINGS`)

- [ ] `FN_*` mappings changed from the `local_imis_*` names to the core names
  (`core_user_get_users`, `core_user_create_users`, `core_user_update_users`,
  `enrol_manual_enrol_users`, `enrol_manual_unenrol_users`).
- [ ] `WEBSERVICE_TOKEN` → the new token on the new service.
- [ ] `REST_ENDPOINT` unchanged (`/webservice/rest/server.php`).
- [ ] The outbound `wsmoodle` endpoint + AuthToken aligned with the new plugin's
  settings.

## E. Cutover — avoid double-writes (critical)

- [ ] **Only one plugin's observers active at a time.** With both installed,
  `course_completed` fires **both** observers and produces duplicate iMIS records.
  **Disable or uninstall the vendor `local_imis` plugin** (or disable its
  observers) before enabling the new plugin's writes.
- [ ] Enable the new plugin's write toggles.
- [ ] Decide on the login/nightly syncs: if ATS now pushes enrolments inbound, the
  new plugin's login/nightly **pull** syncs may be redundant or conflicting —
  enable only what you intend to use.

## F. Post-cutover verification (first 24–48 hours)

- [ ] Audit log shows both directions succeeding, **no errors** and **no duplicate**
  activity records.
- [ ] Spot-check one real registration (create + enrol) and one real completion
  (writeback) end to end in iMIS.
- [ ] SAML login still works (`auth_saml2`, unchanged by the plugin swap).
- [ ] Revoke the **old** `local_imis` token and disable its service.

## G. Rollback

Config-only — both plugins' tables are logs/tracking, so there is no data to
migrate:

- [ ] Re-enable the vendor plugin's observers and its token/service.
- [ ] Disable the new plugin's write toggles.
- [ ] Repoint ATS `FN_*` back to the `local_imis_*` names and the old token.

---

## Sign-off

A changeover is confirmed successful when: **B** passes (new records match vendor
records and the existing history), **C** passes (inbound create/enrol works,
including hidden courses and existing users), **E** holds (no double-writes), the
two coverage gaps (score trigger, MOODLENOTE trail) are a conscious accepted
decision, and **F** shows a clean audit log in both directions with no duplicates.
