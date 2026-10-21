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

> **Never change `mdl_course.id` to line up with iMIS.** The course id is a primary
> key referenced across ~30 tables (enrolments, context, modules, grades,
> completions); editing it directly in the DB orphans the enrolments and breaks the
> course (it vanishes from "My courses"). Align on the **iMIS** side instead: the
> `MOODLE_PRODUCTS` row maps `Description = <numeric Moodle course id>` →
> `Code = <iMIS product code>`. Let Moodle assign the course id and map it in iMIS;
> or, if you need the plugin to send something other than the raw id, add a
> product-identifier-source setting rather than touching the id.

- [ ] Submit a graded quiz and confirm the score lands correctly.
- [ ] Confirm each call in the **audit log** (operation, status, any fault). As of
  1.11.0 a populated `Exception` in the SOAP `ResultsBase` is surfaced as an error
  (logged and retried), and setting **SOAP success ResultCodes** makes an
  unexpected `ResultCode` a failure too. But until those success codes are
  configured, a non-exception `ResultCode` rejection can still log "success" — so
  still **confirm the activity actually appears in iMIS**, don't rely on the log
  alone.

## C. Inbound provisioning — verify the core-functions service

> **`local_imisbridge` provides no inbound functions by design.** Inbound
> provisioning (create user, enrol, unenrol) is done by **core Moodle web-service
> functions** (`core_user_*`, `enrol_manual_*`) exposed on an external service, with
> ATS's `FN_*` mappings repointed to those core names (§D). The vendor's
> `local_imis_*` wrappers are *replaced by configuration*, not re-implemented in the
> new plugin — and core `enrol_manual_enrol_users` avoids the vendor's broken
> hidden-course handling **provided the service account can access the course**:
> its `validate_context()` still runs `require_login()`, so the account needs
> `moodle/course:view` (+ `viewhiddencourses` for hidden courses) — see the recipe
> below. A single-plugin alternative (adding external
> functions + a `db/services.php` to `local_imisbridge` and pointing `FN_*` at them)
> is heavier — more code and capability surface. Prefer the core route **unless**
> the existing-user test below shows ATS strands the enrol when
> `core_user_create_users` throws on an already-existing user (parity decision 3):
> a custom wrapper can treat "already exists" as success so processing continues,
> which avoids that outage pattern and core cannot. Decide from that test.

> **Cancellation — suspend instead of unenrol.** For a cancelled order the
> requirement is to **keep the learner's data**. Core `enrol_manual_unenrol_users`
> **hard-unenrols**, deleting that enrolment's grades and completion — the wrong
> outcome. Point the ATS `FN_ENROL_MANUAL_UNENROL_USERS` mapping at
> **`local_imisbridge_cancel_enrolment`** instead. It takes
> `enrolments: [{userid, courseid, roleid?}]`, and for each entry **suspends** every
> enrolment the user has in the course (row, grades and completion all kept; access
> removed) and **unassigns the role that enrolment grants** — the enrolment
> instance's own default role (`enrol.roleid`), or the `roleid` you pass — so another
> role, such as a manual teacher/manager override, is left in place. It never
> unenrols or deletes, and each entry is transactional, so an error cannot lose or
> half-apply data. `roleid` is optional: 0/omitted targets each suspended enrolment's
> default role; a specific id removes just that role.
>
> Grant the service-account role **`local/imisbridge:cancelenrolment`** and add the
> function to the inbound service (it is also on the plugin's own `iMIS bridge`
> service).
>
> **On the live `invalidparameter` error (unconfirmed root cause):** the ATS logs
> `User:<id> Course:<id>` for its unenrol call, but the actual ATS→Moodle REST
> payload has not been captured, so do **not** assume remapping alone resolves it.
> On Moodle 5.1 `enrol_manual_unenrol_users` accepts `enrolments[{userid, courseid}]`
> and ignores a user not in the manual instance rather than rejecting it, so the
> error likely comes from the real payload's schema. Capture that request and
> reconcile its schema with the chosen function before relying on the remap to fix
> the error. The suspend-not-unenrol behaviour above is the function's purpose
> regardless of that diagnosis.

**Build recipe** (what was set up on dev; repeat on prod at cutover):

1. Site admin → Advanced features → **Enable web services**; then Server → Web
   services → **Manage protocols** → enable **REST**.
2. Server → Web services → **External services** → add a service (e.g.
   "ATS iMIS Bridge"): **Enabled**, **Authorised users only**, not on the mobile
   service.
3. Add its **functions**: `core_user_get_users`, `core_user_create_users`,
   `core_user_update_users`, `enrol_manual_enrol_users`, and — for cancellation —
   **`local_imisbridge_cancel_enrolment`** (suspend + unassign role, keeping data; see
   the cancellation callout above). Add core `enrol_manual_unenrol_users` as well only
   if some flow still needs a hard unenrol. (`local_imisbridge_get_completions_since`
   can ride the same service if one token also serves the outbound bulk pull;
   `core_webservice_get_site_info` is handy for a connectivity check.)
4. Create a dedicated **service-account** user and add it as an **authorised user**
   of the service.
5. Give that account a role (system context) holding these capabilities. The
   **starred** ones are **not** in the functions' declared "required capabilities"
   and are the usual cause of a silent access/enrol failure:
   - ★ **`webservice/rest:use`** — required to call the REST protocol at all; without
     it every request made with the token fails with an access-control exception.
   - `moodle/user:create`, `moodle/user:update`, `moodle/user:viewdetails`,
     `moodle/user:viewhiddendetails`, `moodle/course:useremail`
   - ★ **`moodle/user:viewalldetails`** — without it (Moodle 5.1+)
     `core_user_get_users` can omit `idnumber`/`username`, so ATS fails to match an
     existing member, calls create, and hits the already-exists error. (The
     "expose `idnumber` via *Show user identity*" alternative also needs
     `moodle/site:viewuseridentity` on this role, so granting `viewalldetails` is
     simpler.)
   - `enrol/manual:enrol`, `enrol/manual:unenrol`
   - ★ **`local/imisbridge:cancelenrolment`** — required by
     `local_imisbridge_cancel_enrolment` (the suspend-not-unenrol cancellation
     function). Checked in the course context, so this system-context role covers
     every course. Granted to no role by default.
   - ★ **`moodle/course:view`** — "View courses without participation". Without it,
     `enrol_manual_enrol_users` → `validate_context()` → `require_login($course)`
     fails for a service account that isn't enrolled, with
     `requireloginerror: Course or activity not accessible` — **even on a visible
     course**. (Confirmed on the dev end-to-end test: this was the final blocker.)
   - ★ **`moodle/course:viewhiddencourses`** (and `moodle/category:viewhiddencategories`)
     — so the same access check also passes when the target course/category is
     **hidden** (the usual prod case: courses hidden until launch).
   - ★ **`moodle/role:assign`** — needed by `enrol_manual_enrol_users` to assign the
     Student role (`ROLE_ID`).
   - `local/imisbridge:readcompletions` — only if this token also serves the pull.
6. **Allow this role to assign Student.** `moodle/role:assign` alone is not enough:
   go to Site admin → Users → Permissions → **Define roles → Allow role assignments**
   and tick that the service-account role may assign the **Student** role
   (`ROLE_ID`), or enrolment fails with `wsusercannotassign`.
7. Server → Web services → **Manage tokens** → create a token for the service account
   on this service. That token is `WEBSERVICE_TOKEN` in §D; rotate it if exposed.

Then verify:

- [ ] Service-account role has the full capability set from the recipe above —
  especially the non-declared ones (**`webservice/rest:use`**, **`moodle/role:assign`**,
  **`moodle/user:viewalldetails`**) and the **Allow role assignments** mapping for
  Student.
- [ ] Service set to **authorised users only**, with the service account added; not
  exposed on the mobile service.
- [ ] Web services + the **REST protocol** enabled; the REST endpoint
  (`/webservice/rest/server.php`) reachable from ATS (allowlist ATS egress IPs if
  inbound is restricted).
- [ ] End-to-end: ATS pushes a registration → a **new** user is created and
  enrolled into the mapped course with the Student role, **including a hidden
  course** (needs `moodle/course:view` + `viewhiddencourses` on the service
  account — see the recipe; this was the final blocker on the dev test).
- [ ] Re-send for an **existing** user → confirm it does not error-and-strand the
  enrol (decision 3).

## D. ATS-side repoint (iMIS `MOODLE_SETTINGS`)

- [ ] `FN_*` mappings changed from the `local_imis_*` names to the core names
  (`core_user_get_users`, `core_user_create_users`, `core_user_update_users`,
  `enrol_manual_enrol_users`), with **`FN_ENROL_MANUAL_UNENROL_USERS` →
  `local_imisbridge_cancel_enrolment`** so a cancellation suspends and removes the
  role instead of hard-unenrolling (see the cancellation callout in §C). Leave it on
  core `enrol_manual_unenrol_users` only if a hard unenrol is genuinely wanted.
- [ ] `WEBSERVICE_TOKEN` → the new token on the new service.
- [ ] `REST_ENDPOINT` unchanged (`/webservice/rest/server.php`).
- [ ] The outbound `wsmoodle` endpoint + AuthToken aligned with the new plugin's
  settings.

### Observed bridge config (`MOODLE_SETTINGS`) & environment gotchas

The ATS bridge reads these keys from an iMIS General Lookup. Traps seen on a dev
bridge, to check before an inbound test:

- **`REST_ENDPOINT` (the real safety gate)** — the Moodle web-service the bridge
  actually calls to create/enrol/unenrol. This, with `WEBSERVICE_TOKEN`, is what can
  change a Moodle, so **before any order/group sync verify it points at the dev
  Moodle** (`https://<dev-moodle>/webservice/rest/server.php`), not production. Found
  on the dev bridge pointing at the **iMIS** host instead, where no Moodle WS
  answers — so inbound reached no Moodle at all until fixed.
- **`COURSE_URL`** — only the generated **course-link** pattern (links / emails /
  SSO redirects); it does **not** control where enrol/unenrol/group callbacks go.
  Repointing it to the dev Moodle stops production links being emitted, but does
  **not** by itself make a sync safe — the gate is `REST_ENDPOINT` above. Found on
  the dev bridge pointing at production.
- **`WEBSERVICE_TOKEN`** — the Moodle token for that endpoint. Treat as a secret and
  rotate it if exposed. It must belong to the **same** Moodle `REST_ENDPOINT` points
  at, on a service account holding the §C capabilities.
- **`GROUP_IQA_NAME`** — empty → `UpdateMoodleGroups` is a no-op (no group query
  configured). Expected unless group sync is set up.
- **New-order selection is an IQA** under `IQA_FOLDER_NAME` (the bridge's Moodle IQA
  folder). `SendNewOrdersToMoodle` returning **"No orders found"** for a known
  registration means that IQA returns nothing — verify the order's **product code
  matches the `MOODLE_PRODUCTS` `Code`** (a *copied* event usually gets a new code)
  and that the order meets the IQA's state / "unsent" criteria. Open and run the IQA
  in iMIS to see exactly what it returns.
- **SSO URLs** (`LOGIN_URL` / `NOT_AUTHORIZED_URL`) point at the iMIS instance the
  ATS host is wired to — a reliable way to confirm dev vs prod when the outbound
  hostname alone is ambiguous.
- Reference values: `AUTH_MODE = saml2`, `ROLE_ID = 5` (Student enrol role),
  `ACTIVITYTYPE = MOODLE`.

### Inbound troubleshooting (from the dev end-to-end test)

The order → enrol path was proven end-to-end on dev. The gotchas hit along the
way, each with the symptom that identifies it:

- **`FN_*` transposition.** It is easy to swap `FN_CORE_USER_CREATE_USERS` and
  `FN_CORE_USER_GET_USERS` (map create → `core_user_get_users` and vice versa).
  The create/get calls then run backwards. Double-check each `FN_*` value matches
  its key.
- **Push trigger must be server-side and IP-allowlisted.** The ATS `wsmoodle`
  service only accepts calls from **allowlisted IPs** — a call from a workstation
  browser is denied with `ResultCode 2` / "Error checking security header …
  WebService Access is denied" (the IP is named in the message). The Moodle
  server is allowlisted (so the plugin's push works); for the scheduled push,
  **iDMS runs server-side** and the **iMIS/iDMS server's egress IP must also be
  allowlisted on `wsmoodle`**. There is no way to trigger it from a browser.
- **403 from Moodle = a host-layer block, not Moodle perms.** A
  `(403) Forbidden` on the `/webservice/rest/server.php` callback means the
  request was blocked **before reaching Moodle** (Moodle permission/token errors
  come back HTTP 200 with an XML `<EXCEPTION>`). On the dev host (YunoHost /
  `*.nohost.me`), the **SSO portal (SSOwat)** blocks unauthenticated external
  requests — make the webservice path **public** for the Moodle app; generally,
  allowlist the ATS egress IP / open a WAF rule for `/webservice/rest/server.php`.
- **`requireloginerror: Course or activity not accessible`** on enrol means the
  service account cannot access the target course — it needs **`moodle/course:view`**
  (and `viewhiddencourses` for a hidden course), per §C. This is true even for a
  **visible** course, because the account is not enrolled.
- **REST only, no SOAP.** The service account needs `webservice/rest:use`, not
  `webservice/soap:use` — the bridge calls Moodle over REST; the only SOAP is the
  plugin acting as a SOAP *client* to ATS, which uses no Moodle web service.

### Division of labour on this install

Inbound (user create + enrol/unenrol) is driven by **iDMS push → `wsmoodle` →
Moodle core web-service functions** — *not* by the plugin's `SendNewOrdersToMoodle`
SOAP pull. So on this install the plugin's **order/cancellation/group sync is not
the enrolment mechanism** and its login/nightly sync toggles stay **off**;
`local_imisbridge` owns only the **outbound** completion/score push.

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
