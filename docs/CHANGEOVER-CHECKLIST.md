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
| Module completions | `course_module_completion_updated` → MOODLENOTE | **opt-in** via `push_module_completions` (default off, **graded modules only**) |
| Inbound provisioning | `local_imis_*` web-service functions (in-plugin) | **none in-plugin** → Moodle **core** functions on a hand-built service |
| Identity field | `$user->idnumber` | `imis_id_source` setting (set to **ID number**) |
| productID | numeric Moodle course id | numeric Moodle course id — **same** |

Verified from the code: the new plugin's observers are `user_loggedin`,
`course_completed`, `mod_quiz\event\attempt_submitted`, and (opt-in)
`course_module_completion_updated`; its only web-service function is
`local_imisbridge_get_completions_since` (a read-only completions pull on a
restricted service) — it exposes **no** create/get/update/enrol/unenrol functions.

---

## Three parity decisions that determine success

Resolve these before cutover — they are behaviour changes, not bugs:

1. **Score coverage narrows.** The vendor pushed on **any** `user_graded`
   (every gradebook activity); the new plugin pushes on **quiz submission only**.
   If any course awards credit via a non-quiz graded activity (assignment, SCORM,
   lesson, …), that score will **not** flow to iMIS. Decide whether quiz-only is
   acceptable, or add a `user_graded` observer / make the trigger configurable.
2. **The MOODLENOTE audit trail is opt-in and partial.** The vendor wrote
   supplementary `MOODLENOTE` records for module completions **and** per-grade
   notes. The new plugin can write the **module-completion** notes — enable
   `push_module_completions` (default off, graded modules only) — but does **not**
   reproduce the per-`user_graded` notes. Decide whether the module-completion
   trail is needed (if so, turn the toggle on and mind the volume), and whether the
   missing per-grade notes matter to any iMIS report.
3. **Inbound behaviour changes.** Moodle's core `core_user_create_users`
   **throws when the user already exists** (the vendor returned "already exists =
   success"), and the core functions have no idempotency or create/enrol
   decoupling. Confirm ATS's create+enrol sequencing will not strand existing
   members on a create error — this was the original vendor outage pattern.

---

## A. Pre-cutover — new plugin configured, all writes OFF

Order matters here — disable writes **before** entering any endpoint.

- [ ] `local_imisbridge` installed on the live Moodle; **pause Moodle cron** for the
  configuration window.
- [ ] **Disable every write toggle FIRST, before saving any endpoint.** The eight
  automatic writes default to enabled (and an *unset* value is treated as enabled),
  so saving a WSDL first would make the new plugin live while the vendor is still
  active. Turn off: completion push, quiz push, module-completion push, the three
  login syncs, and the three nightly tasks.
- [ ] **Transport = ATS SOAP bridge** (the default) for this changeover — the ATS
  test/prod bridge is SOAP. If `transport = rest`, the WSDL / `service_endpoint`
  settings below are **ignored** and writes go to `rest_base_url` instead (a
  different environment) — not what you want here. The direct iMIS REST transport is
  a separate path (`DIRECT-REST-ARCHITECTURE.md`).
- [ ] **iMIS ID source = ID number** (SAML matches `UNIQUEID` → `idnumber`, the
  authoritative field; see `DIRECT-REST-ARCHITECTURE.md` §3).
- [ ] Only now set the **WSDL URL + ATS AuthToken** for the intended iMIS/bridge
  environment; set the `service_endpoint` override if the WSDL embeds a different
  environment's address (the trap in `TESTING.md`).
- [ ] The three parity decisions above are resolved.

## B. Outbound parity — the new plugin must write the SAME record

- [ ] On a test course, complete it and confirm the iMIS activity created matches
  what the vendor produced: **same productID (numeric course id), status, score,
  start/completion/grant dates, credit**, under the right contact (`idnumber`).
  Compare against an existing vendor-written record for continuity with the
  history already in iMIS.
- [ ] Submit a graded quiz and confirm the score lands correctly.
- [ ] Confirm each call in the **audit log** (operation, status, any fault) — but
  **do not treat a logged "success" as proof the record landed.** The audit status
  is transport-level (the SOAP call returned without throwing); it does **not**
  inspect the ATS `ResultsBase` ResultCode / ResultMessage / Exception, so an
  application-level rejection in a valid SOAP response still logs "success" and the
  task will not retry. Confirm the activity actually appears in iMIS.

## C. Inbound provisioning — verify the core-functions service

- [ ] On the inbound web-service (e.g. "ATS iMIS Bridge"), the service account's
  role holds `moodle/user:create`, `moodle/user:update`, `moodle/user:viewdetails`,
  **`moodle/user:viewalldetails`**, `enrol/manual:enrol`, `enrol/manual:unenrol`,
  **and `moodle/role:assign`**. Two easy-to-miss ones:
  - `moodle/role:assign` — required by `enrol_manual_enrol_users` to assign the
    Student role (not shown in the function's "required capabilities").
  - `moodle/user:viewalldetails` — without it (Moodle 5.1+) `core_user_get_users` can
    omit `username` / `idnumber` from the result and then drop the match, so ATS
    concludes an existing member is **absent**, calls create, and hits the exact
    already-exists failure this is meant to prevent. (Alternatively, configure
    `idnumber` as a displayed identity field.)
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
  **Disable the vendor `local_imis` plugin's observers** before enabling the new
  plugin's writes — but **do NOT uninstall it yet.** Keep it installed (disabled)
  through the rollback window so a switch-back stays config-only (see G); uninstall
  only after post-cutover sign-off (F).
- [ ] Enable the new plugin's write toggles.
- [ ] Decide on the login/nightly syncs: if ATS now pushes enrolments inbound, the
  new plugin's login/nightly **pull** syncs may be redundant or conflicting —
  enable only what you intend to use.

## F. Post-cutover verification (first 24–48 hours)

- [ ] **Outbound:** the plugin **audit log** shows completion/quiz writes
  succeeding, **no errors** and **no duplicate** activity records (with the
  ResultsBase caveat from B — confirm the records in iMIS, not just the log status).
- [ ] **Inbound** create/enrol calls run through Moodle's **core** functions and
  **bypass the plugin audit log entirely** — verify them via Moodle's web-service /
  event logs (Site administration → Reports → Logs, and → Server → Web services →
  monitor) and/or ATS-side records, not the plugin log.
- [ ] Spot-check one real registration (create + enrol) and one real completion
  (writeback) end to end in iMIS.
- [ ] SAML login still works (`auth_saml2`, unchanged by the plugin swap).
- [ ] **Only after the rollback window has closed and the changeover is confirmed:**
  revoke the **old** `local_imis` token, disable its service, and uninstall the
  vendor plugin.

## G. Rollback

Config-only **provided the vendor plugin is still installed** (keep it
installed-but-disabled through the rollback window — §E/§F). Both plugins' tables
are logs/tracking, so there is no data to migrate. Do it **in this order** so no
event is handled by both plugins during the switch-back (the single-writer
invariant):

- [ ] **First, disable the new plugin's write toggles.**
- [ ] Then re-enable the vendor plugin's observers and its token/service.
- [ ] Repoint ATS `FN_*` back to the `local_imis_*` names and the old token.

> If the vendor plugin was already **uninstalled**, rollback is **not** config-only:
> Moodle purged its code, configuration and tables, so it must be **reinstalled and
> reconfigured** first. This is exactly why §E keeps it installed-but-disabled until
> sign-off.

---

## Sign-off

A changeover is confirmed successful when: **B** passes (new records match vendor
records and the existing history), **C** passes (inbound create/enrol works,
including hidden courses and existing users), **E** holds (no double-writes), the
two coverage gaps (score trigger, MOODLENOTE trail) are a conscious accepted
decision, and **F** shows a clean audit log in both directions with no duplicates.
