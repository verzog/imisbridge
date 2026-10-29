# iMIS plugin changeover checklist

> This is SCCA's runbook, with SCCA's iMIS names and steps. For setting the plugin
> up on another iMIS, see `SETUP.md`.

Confirming a successful switch from the vendor `local_imis` plugin to
`local_membershipbridge` on the live Moodle. Use alongside `TEST-CHECKLIST.md`
(setup/connectivity), `TEST-PLAN.md` (the per-feature test matrix) and
`VENDOR-ARCHITECTURE.md` (what the vendor did).

The crux of "successful" is: (B) the new plugin writes the **same** iMIS records
the vendor did, (C) inbound create/enrol still works, and (E) **only one** plugin
writes at a time. Everything else supports those.

**Rehearse on the Catalyst staging copy first (§S):** a recent copy of live Moodle
with a refreshed iMIS staging lets every sync and push be tested on realistic,
non-production data before the cutover, with ATS kept for pushes and syncs (REST for
tutorial groups) or any other provider combination.

**Tutorial group sync (§T) is a separate go-live.** It is new functionality the
vendor never had (iMIS tutorial groups → Moodle course groups, over direct REST),
so it has no parity to prove and does not depend on the vendor swap. It can go
live before, with, or after the changeover; §T has its own checks.

**The plugin was renamed in 2.0.0**, from `local_imisbridge` to
`local_membershipbridge`. A site that already has `local_imisbridge` installed
(including staging copies of it) moves across first, using §M.

## At a glance

In working order. Section letters refer to the full checklist below.

1. **Make the parity decisions.**
   - Which activity types send scores.
   - Whether module-completion notes are needed.
   - That ATS doesn't strand an existing user when create fails.
   - Whether each course sends the welcome email (§R4).
2. **Rehearse on the Catalyst staging copy (§S).**
   - Before the first cron run, Catalyst forces the staging endpoints and credentials
     (including `service_endpoint`) and sets `noemailever`.
   - Writes off.
   - Use the default providers: ATS, with REST for tutorial groups.
   - Run the syncs as a dry run, then live.
   - Test pushes against real records and compare them with the vendor.
3. **Pre-cutover on live, writes off (§A).**
   - Pause cron.
   - Turn every write toggle off *before* entering any endpoint.
   - Transport = ATS.
   - iMIS ID source = ID number.
   - Then set the WSDL, token and `service_endpoint`.
4. **Outbound parity (§B).** Completion, quiz score and activity score records match
   the vendor's. Check them in iMIS, not only in the audit log.
5. **Inbound service (§C).**
   - Core create/enrol functions plus `local_membershipbridge_cancel_enrolment` on a
     restricted service.
   - A service-account role with the starred capabilities, allowed to assign Student.
   - A new token.
   - End-to-end tests, including a hidden course and an existing user.
6. **Repoint ATS (§D).**
   - Point the `FN_*` mappings at the core functions, with unenrol going to the cancel
     function.
   - Use the new token.
   - Check that `REST_ENDPOINT` points at the right Moodle.
   - Keep `GROUP_IQA_NAME` empty.
7. **Tutorial group sync, a separate go-live (§T).**
   - Set up the REST service account and the lookup IQA.
   - Run as a dry run, then live.
   - Confirm the removal guard and the empty-read abort.
   - It runs nightly at 02:45.
8. **REST registration sync, optional, retires ATS enrolment (§R).**
   - Set up the registrations IQA.
   - Run as a dry run, then live.
   - Keep a single writer: switch off the iDMS push once it's live.
9. **Cutover (§E), or replace the vendor plugin outright (§E2).**
   - Stop the vendor plugin (its own setting, or move its folder out of Moodle), but
     keep it installed.
   - Enable the new plugin's write toggles.
   - Keep only one system enrolling.
10. **First 24–48 hours (§F).**
    - Clean audit log, with no duplicates.
    - Spot-check a registration, a completion, a cancellation and SAML login.
    - Only then retire the vendor plugin.
11. **Rollback if needed (§G).**
    - Turn the new plugin's toggles off first.
    - Re-enable the vendor plugin.
    - Point the `FN_*` mappings back at the vendor's functions.

---

## What is the same, and what differs

| Concern | Vendor `local_imis` | New `local_membershipbridge` |
|---|---|---|
| Completion → iMIS | `course_completed` → `MoodleUpdate` (canonical MOODLE record) | `course_completed` → `MoodleUpdate` — **same** |
| Score → iMIS | `user_graded` → `createActivity` (MOODLENOTE) | Quiz: `quiz_attempt_submitted` → `CreateActivity` (MOODLENOTE, `Note2 = score`). Other activities (1.20.0): `user_graded` → `CreateActivity` (MOODLENOTE, `Note2 = score`, per activity) for the types ticked in **Push activity scores** — same record type; **opt-in per activity type** |
| Module completions | `course_module_completion_updated` → MOODLENOTE | **opt-in** via `push_module_completions` (default off, **graded modules only**) |
| Inbound provisioning | `local_imis_*` web-service functions (in-plugin) | **create/enrol: none in-plugin** → Moodle **core** functions on a hand-built service; **cancel:** `local_membershipbridge_cancel_enrolment` (suspend, keeps data) |
| Tutorial groups | none | **new:** nightly REST pull of the `MoodleTutorialGroup` panel (§T) |
| Identity field | `$user->idnumber` | `imis_id_source` setting (set to **ID number**) |
| productID | numeric Moodle course id | numeric Moodle course id — **same** |

Verified from the code: the new plugin's observers are `user_loggedin`,
`course_completed`, `mod_quiz\event\attempt_submitted`, and (opt-in)
`course_module_completion_updated` and `core\event\user_graded`. Its web-service functions are
`local_membershipbridge_get_completions_since` (a read-only completions pull) and
`local_membershipbridge_cancel_enrolment` (suspend-not-unenrol cancellation, §C), both on
a restricted service — it exposes **no** create/get/update/enrol functions.

---

## Three parity decisions that determine success

Resolve these before cutover — they are behaviour changes, not bugs:

1. **Choose which activities send scores.** The vendor wrote a score note on
   **any** `user_graded` (every gradebook activity). The new plugin always sends
   quiz scores (on submission, *Push quiz scores*), and since 1.20.0 sends scores for
   other activity types **when they are graded** — tick them in **Push activity
   scores** (none ticked by default). The list is built from every gradable activity
   installed (including third-party ones such as `mod_casestudy`), so tick each type
   that awards credit — e.g. *Assignment*, *Case study*. Each is an append-only
   `MOODLENOTE` ("Score: <activity>", `Note2 = score`), never the canonical `MOODLE`
   completion record. Unlike the vendor, an **unchanged** regrade is not sent again,
   so a bulk rescale cannot flood iMIS. Only activities that write their grades
   through Moodle's gradebook API can send scores; standard activities all do.
2. **The module-completion MOODLENOTE trail is opt-in.** The vendor also wrote
   `MOODLENOTE` records for module completions. The new plugin can too — enable
   `push_module_completions` (default off, graded modules only). Decide whether
   that trail is needed by any iMIS report (if so, turn the toggle on and mind the
   volume). With decision 1, the plugin can now write every kind of note the vendor
   wrote. A tutor-marked activity is better covered by decision 1 than by this one:
   its completion note goes out when the learner completes it, often before it is
   marked (score 0), while its score note goes out when it is marked.
3. **Inbound behaviour changes.** Moodle's core `core_user_create_users`
   **throws when the user already exists** (the vendor returned "already exists =
   success"), and the core functions have no idempotency or create/enrol
   decoupling. Confirm ATS's create+enrol sequencing will not strand existing
   members on a create error — this was the original vendor outage pattern.

---

## A. Pre-cutover — new plugin configured, all writes OFF

Order matters here — disable writes **before** entering any endpoint.

- [ ] `local_membershipbridge` installed on the live Moodle; **pause Moodle cron** for the
  configuration window.
- [ ] **Disable every write toggle FIRST, before saving any endpoint.** Eight
  automatic writes default to **enabled** (and an *unset* value is treated as
  enabled), so saving a WSDL first would make the new plugin live while the vendor
  is still active. Turn off: completion push, quiz push, the three login syncs, and
  the three nightly-task toggles. (Module-completion push defaults **off** and the
  tutorial group sync is **opt-in**, off by default — confirm both are still off.)
- [ ] **Transport = ATS SOAP bridge** (the default) for this changeover — the ATS
  test/prod bridge is SOAP. If `transport = rest`, the WSDL / `service_endpoint`
  settings below are **ignored** and writes go to `rest_base_url` instead (a
  different environment) — not what you want here. The direct iMIS REST transport is
  a separate path (`DIRECT-REST-ARCHITECTURE.md`). **Exception:** the tutorial group
  sync (§T) always uses the REST settings, **whatever the transport** — so filling
  in the REST settings with `transport = soap` enables only that feature, not REST
  pushes.
- [ ] **iMIS ID source = ID number** (SAML matches `UNIQUEID` → `idnumber`, the
  authoritative field; see `DIRECT-REST-ARCHITECTURE.md` §3).
- [ ] Only now set the **WSDL URL + ATS AuthToken** for the intended iMIS/bridge
  environment; set the `service_endpoint` override if the WSDL embeds a different
  environment's address (the trap in `TESTING.md`).
- [ ] The three parity decisions above are resolved.

## B. Outbound parity — the new plugin must write the SAME record

- [ ] On a test course, complete it and confirm the iMIS activity created matches
  what the vendor produced: **same productID (numeric course id), status, score,
  start/completion/grant dates**, under the right contact (`idnumber`). (Credit is
  not sent over SOAP — `MoodleUpdate` carries the fixed `type=Completion` and an
  empty `value`, and iMIS derives the CEU from the product, exactly as with the
  vendor; so the credit on the record should match too.) Compare against an existing
  vendor-written record for continuity with the history already in iMIS.
- [ ] That first successful completion also closes the open gate in
  `IMPROVEMENT-PLAN.md` §1.2 — it proves the live WSDL's update operation is
  `MoodleUpdate`. If the call instead fails as an unknown operation, stop: the live
  operation name differs and the client must be aligned before cutover.

> **Never change `mdl_course.id` to line up with iMIS.** The course id is a primary
> key referenced across ~30 tables (enrolments, context, modules, grades,
> completions); editing it directly in the DB orphans the enrolments and breaks the
> course (it vanishes from "My courses"). Align on the **iMIS** side instead: the
> `MOODLE_PRODUCTS` row maps `Description = <numeric Moodle course id>` →
> `Code = <iMIS product code>`. Let Moodle assign the course id and map it in iMIS;
> or, if you need the plugin to send something other than the raw id, add a
> product-identifier-source setting rather than touching the id.

- [ ] For each type ticked in **Push activity scores**, grade one activity (e.g. mark
  a case study) and confirm a **`MOODLENOTE`** "Score: <activity>" lands with the
  learner's raw grade, and that saving the same mark again does **not** add another.
- [ ] Submit a graded quiz and confirm the score lands as a **`MOODLENOTE`** record
  ("Moodle course N score") — and that the course's `MOODLE` completion record is
  **unchanged** by it (a re-attempt after completion must never overwrite it).
- [ ] Confirm each call in the **audit log** (operation, status, any fault). As of
  1.11.0 a populated `Exception` in the SOAP `ResultsBase` is surfaced as an error
  (logged and retried), and setting **SOAP success ResultCodes** makes an
  unexpected `ResultCode` a failure too. But until those success codes are
  configured, a non-exception `ResultCode` rejection can still log "success" — so
  still **confirm the activity actually appears in iMIS**, don't rely on the log
  alone.

## C. Inbound provisioning — verify the core-functions service

> **`local_membershipbridge` provides no inbound functions by design.** Inbound
> provisioning (create user, enrol, unenrol) is done by **core Moodle web-service
> functions** (`core_user_*`, `enrol_manual_*`) exposed on an external service, with
> ATS's `FN_*` mappings repointed to those core names (§D). The vendor's
> `local_imis_*` wrappers are *replaced by configuration*, not re-implemented in the
> new plugin — and core `enrol_manual_enrol_users` avoids the vendor's broken
> hidden-course handling **provided the service account can access the course**:
> its `validate_context()` still runs `require_login()`, so the account needs
> `moodle/course:view` (+ `viewhiddencourses` for hidden courses) — see the recipe
> below. A single-plugin alternative (adding external
> functions + a `db/services.php` to `local_membershipbridge` and pointing `FN_*` at them)
> is heavier — more code and capability surface. Prefer the core route **unless**
> the existing-user test below shows ATS strands the enrol when
> `core_user_create_users` throws on an already-existing user (parity decision 3):
> a custom wrapper can treat "already exists" as success so processing continues,
> which avoids that outage pattern and core cannot. Decide from that test.

> **Cancellation — suspend instead of unenrol.** For a cancelled order the
> requirement is to **keep the learner's data**. Core `enrol_manual_unenrol_users`
> **hard-unenrols**, deleting that enrolment's grades and completion — the wrong
> outcome. Point the ATS `FN_ENROL_MANUAL_UNENROL_USERS` mapping at
> **`local_membershipbridge_cancel_enrolment`** instead. It takes
> `enrolments: [{userid, courseid, roleid?}]`, and for each entry **suspends** every
> enrolment the user has in the course (row, grades and completion all kept; access
> removed) and **unassigns the role that enrolment grants** — the enrolment
> instance's own default role (`enrol.roleid`), or the `roleid` you pass — so another
> role, such as a manual teacher/manager override, is left in place. It never
> unenrols or deletes, and each entry is transactional, so an error cannot lose or
> half-apply data. `roleid` is optional: 0/omitted targets each suspended enrolment's
> default role; a specific id removes just that role.
>
> Grant the service-account role **`local/membershipbridge:cancelenrolment`** and add the
> function to the inbound service (it is also on the plugin's own `Membership bridge`
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
   **`local_membershipbridge_cancel_enrolment`** (suspend + unassign role, keeping data; see
   the cancellation callout above). Add core `enrol_manual_unenrol_users` as well only
   if some flow still needs a hard unenrol. (`local_membershipbridge_get_completions_since`
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
   - ★ **`local/membershipbridge:cancelenrolment`** — required by
     `local_membershipbridge_cancel_enrolment` (the suspend-not-unenrol cancellation
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
   - `local/membershipbridge:readcompletions` — only if this token also serves the pull.
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
  `local_membershipbridge_cancel_enrolment`** so a cancellation suspends and removes the
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
- **`accessexception: Access control exception` on cancel/unenrol** (e.g.
  `MoodleClass.unenrolMoodleUser ERROR ... Error:accessexception`, `ResultCode 10`)
  is a **`webservice_access_exception`** from Moodle's web-service layer, raised
  **before the function runs** — so it is **not** a course-context capability failure
  (those report `nopermissions` / "Sorry, but you do not currently have permissions"
  from *inside* the function). Adding course capabilities (`course:view`,
  `viewhiddencourses`, `enrol/manual:unenrol`) therefore does **not** fix it, and the
  course being hidden or visible is irrelevant. Moodle raises this exception for
  several service-level reasons: the called function is **not in the token's external
  service**, the service is **disabled**, the token has a **IP restriction** or has
  **expired**, the token user is **not an authorised user** of a restricted-user
  service, or the service declares a **required capability** the token user's role
  lacks. Narrow it down first by checking Moodle's detailed web-service log/error
  (Site admin → Reports → Logs, or the debug detail in the response). The common
  case here — and the tell-tale that points straight at a **missing function** — is
  that enrol (`SendNewOrdersToMoodle`) **succeeds on the same token** while cancel
  does not: same token, same source, so IP/expiry/disabled-service/restricted-user are
  already ruled out and only the function list differs. If cancel is your *first or
  only* inbound test, you cannot assume that yet — confirm an enrol works on the token
  first, or read the log. Fix (missing-function case): add the function that
  `FN_ENROL_MANUAL_UNENROL_USERS` maps to (core `enrol_manual_unenrol_users`, or
  `local_membershipbridge_cancel_enrolment`) to the external service the ATS token uses
  (Site admin → Server → Web services → External services → *service* → Functions),
  and confirm the token user is an authorised user of it. When mapping to
  **`local_membershipbridge_cancel_enrolment`**: it only exists after
  the plugin is **deployed and upgraded** (its `db/services.php` then registers it and
  adds it to the plugin's own `Membership bridge` service), and the service-account role
  must hold **`local/membershipbridge:cancelenrolment`** (checked inside the function; a
  missing one surfaces as `nopermissions`, not `accessexception`). The function also
  calls `validate_context()`, so it still needs `course:view` (+ `viewhiddencourses`
  for a hidden course) as in §C.
- **`Could not connect to host` on a sync** (while **Test connection** still passes)
  is on the **ATS → Moodle** leg, not Moodle → ATS. Test connection only does reads
  (ATS answers them from its own side); a sync additionally makes **ATS call back into
  Moodle** via the ATS-side `REST_ENDPOINT`, so a connection error there is that
  callback failing. **Distinguish transient from configuration by whether it clears
  on its own:**
  - **Self-clears on retry** → a **reachability blip**: the Moodle web server/PHP-FPM
    was momentarily down (e.g. restarting during a **PHP or package update**), or a
    dynamic-DNS host's public IP / port-forward changed on reboot and then settled.
  - **Persists across retries** → a **configuration fault on the callback**, *not*
    transient: a wrong/stale `REST_ENDPOINT`, or DNS, proxy, firewall or WAF on the
    **ATS/iDMS network path** blocking it. This reproduces indefinitely, so do not
    just keep retrying — fix the endpoint or the network path.

  Diagnose from the **right side**: reaching Moodle from your workstation only proves
  Moodle is up, not that ATS can reach it. Test from the **ATS/iDMS host** to the
  exact `REST_ENDPOINT` the ATS uses (`curl -v <REST_ENDPOINT>` should return HTTP 200
  with an `<EXCEPTION>invalidtoken</EXCEPTION>` body). A workstation check is a
  fallback only — and run it **off-box** from Moodle, since on the Moodle server the
  name may resolve to `::1`/localhost. Then re-run the sync.
- **Recurring overnight `Error Fetching http headers` / `Could not connect to host`
  on `SendCancelledOrdersToMoodle` *specifically*, with no contact id (`-`), while
  every *per-contact* call succeeds.** The SOAP wrapper only records and rethrows the
  transport error, so the symptom alone does **not** name the cause — weigh three, and
  tell them apart by **how long the failing call ran** and by **testing the ATS→Moodle
  path during the failure window**, not by assuming batch size:
  - **The all-user batch is too slow for the timeout** — likely when the failing calls
    run close to `ws_timeout` (default **30s**). `sync_cancelled_orders(null)` asks ATS
    to process **every** cancellation in one synchronous call; that, plus its per-user
    Moodle callbacks, can exceed the read timeout. The nightly `sync_cancellations_task`
    is a **scheduled** task, so Moodle's **`fail_delay`** backs it off and retries — the
    2:36 / 2:53 / 3:27 / 4:32 cluster is one failing run retrying, not five faults. Per-
    contact calls finish well under the timeout, so they pass.
  - **Moodle is unreachable only in the overnight window** (backups, cron, a web/PHP
    restart): the ATS→Moodle callback can't connect even though per-contact calls pass
    when *tested by day*. Here the calls fail **fast**, not at ~30s. Confirm Moodle
    answers the `REST_ENDPOINT` reliably at 2–6am **from the ATS host**.
  - **One callback inside the batch hangs**, stalling the whole synchronous call.
  Fixes: **(1)** where the plugin's pull sync is **not** the enrolment mechanism (see
  *Division of labour* — iDMS push is), the nightly enrolment/cancellation tasks should
  be **off**; a running-but-failing batch is just noise to silence. **(2)** Only if the
  pull sync *is* used **and** the calls are hitting the timeout (slow, ~30s), raise
  **`ws_timeout`** (Connection timeout) to ~120–300s. **(3)** If they fail **fast**, it
  is reachability, not duration — fix the callback path, do not raise the timeout.
- **Group operations — who needs which permission.** The **1.19.0 tutorial group
  sync** is a Moodle **scheduled task** (cron) that uses the internal group API, so it
  needs **no** web-service user and **no** `moodle/course:managegroups`; it only needs
  the **iMIS REST account** to *read* the `MoodleTutorialGroup` panel and the
  `MoodleCourseLookup` IQA (iMIS-side security, not Moodle). The separate **ATS
  `UpdateMoodleGroups`** path *does* touch Moodle groups via `core_group_*`, so the
  token's role needs **`moodle/course:managegroups`** (+ `moodle/course:view` and
  `viewhiddencourses`) and those functions in the token's external service. A green
  `UpdateMoodleGroups` (ResultCode 0) does **not** by itself confirm either is in
  place: it is a successful **no-op** when `GROUP_IQA_NAME` is unset or the IQA returns
  no group changes, so it never calls `core_group_*`. Only a run that **applies a real
  Moodle group change** confirms the functions are in the service *and* the role holds
  `managegroups` — so verify a known iMIS group change actually lands in the course.
- **REST only, no SOAP.** The service account needs `webservice/rest:use`, not
  `webservice/soap:use` — the bridge calls Moodle over REST; the only SOAP is the
  plugin acting as a SOAP *client* to ATS, which uses no Moodle web service.

### Division of labour on this install

Inbound (user create + enrol/unenrol) is driven by **iDMS push → `wsmoodle` →
Moodle core web-service functions** — *not* by the plugin's `SendNewOrdersToMoodle`
SOAP pull. So on this install the plugin's **order/cancellation/group sync is not
the enrolment mechanism** and its login/nightly sync toggles stay **off**.

`local_membershipbridge` owns:

- the **outbound** push — completions via `MoodleUpdate`, quiz scores (and opt-in
  module notes) via `CreateActivity`;
- the inbound **cancellation** function `local_membershipbridge_cancel_enrolment` (called
  by ATS, suspend-not-unenrol — §C);
- **tutorial group membership** — the §T scheduled task, which makes Moodle course
  groups match the iMIS `MoodleTutorialGroup` panel over direct REST.

ATS's own `UpdateMoodleGroups` remains a no-op while `GROUP_IQA_NAME` is empty —
keep it that way, so tutorial groups have **one** writer (§T).

## T. Tutorial group sync go-live (direct REST — independent of the vendor swap)

A nightly scheduled task (`\local_membershipbridge\task\sync_tutorial_groups`, 02:45 by
default) reads the iMIS `MoodleTutorialGroup` panel and makes each course's groups
match it: it creates the groups (under the grouping **Tutorials (iMIS)**), adds the
people iMIS lists, and removes the people iMIS no longer lists. It runs as cron
using Moodle's internal group API, so it needs **no** Moodle web-service user or
group capabilities — only an **iMIS** account that can read the panel and the
course-lookup IQA. It only ever touches groups whose idnumber starts `imis:` and
memberships it created itself (component `local_membershipbridge`), so groups and members
that teachers add by hand are left alone.

Requires **1.19.4 or later** (1.19.2 fixed the course lookup; 1.19.4 fixed the audit
log on long REST URLs).

### T1. iMIS side

- [ ] A **dedicated iMIS REST service account** (not a person's login, not
  SysAdmin) with read access to the `MoodleTutorialGroup` panel and the course-lookup
  IQA. Confirm the licence position (`DIRECT-REST-ARCHITECTURE.md` §4). **Rotate any
  credential used during setup or testing** before go-live.
- [ ] The course-lookup IQA (default `$/_SCCA/Events/MoodleCourseLookup`) has
  **"Available via REST API"** ticked and its **required `Code` prompt** in place.
  The plugin sends the prompt positionally as `&Parameter={code}/REG`.
- [ ] Every event that has tutorial groups has a `MOODLE_PRODUCTS` row whose
  `Code` is the full product code (`{event code}/REG`) and whose `Description` is
  the **numeric Moodle course id**. (A *copied* event usually gets a new code — add
  its row.)
- [ ] The panel rows carry the member's iMIS ID, the event code and the group name.
- [ ] **Tutors (2.1.0):** the panel has a **Tutor** property (Yes/No or text),
  ticked on each tutor's allocation row. Its property name must match the plugin's
  *Tutor field* setting (default `Tutor`). The REST service account can read it.
- [ ] **Existing rows have a Tutor value, not NULL.** Adding `Tutor` to a panel
  that already has rows leaves them NULL. iMIS then returns HTTP 500 when it reads,
  saves or deletes one of those rows on its own, so Mark as tutor, moves and Remove
  fail for those people. (This was found on sccadev, 28/10/2026.) Straight after
  adding the property, either set `Tutor = 0` where it is NULL and default the
  column to 0, or make `Tutor` a text property. Then check that you can Mark as
  tutor on someone who was allocated before the change. See `imis/README.md`.
- [ ] The event dashboard's **Tutorial Groups** tab is the current
  `imis/tutorial-groups.html`, which adds *Mark as tutor / Unmark tutor* and keeps a
  tutor's tick when they move group. See `imis/README.md`.

### T2. Moodle side

- [ ] **iMIS REST connection** settings filled in — even with `transport = soap`
  (before 1.19.7 this heading reads *Direct iMIS REST provider*, and before 1.19.6 its
  help text wrongly says the settings apply only to that provider — they are still
  required for this feature):
  - **REST base URL** = the iMIS **site root** (e.g. `https://<imis-host>`), with
    **no** `/api` suffix;
  - **REST username / password** = the T1 service account.
- [ ] Outbound HTTPS from the Moodle server to the iMIS host is open.
- [ ] **iMIS contact ID source = ID number** (the sync matches panel members to
  Moodle users through this setting; §A).
- [ ] Members are **enrolled** in the course before their group row is processed —
  the sync skips anyone not enrolled (it never enrols learners). Enrolment comes
  from the iDMS push (§C), so a brand-new registrant can take one nightly cycle to
  appear in their group.
- [ ] **Tutors:** *Tutor field* matches the panel's checkbox, and *Tutor role* is
  set; the default is Non-editing teacher, which can grade but not edit the course.
  - A ticked row gives that person the tutor role in the course and adds them to the
    group. Tutors who aren't enrolled yet are enrolled through the course's manual
    enrolment method.
  - Unticking the row removes the role, but the enrolment stays; remove it by hand
    if needed.
  - If no row at all is ticked (for example the checkbox was dropped from the panel),
    tutor roles are left as they are.
  - Set the course's group mode to **Separate groups** so each tutor sees only their
    own groups' learners.

### T3. First run — dry run, then live

- [ ] Tick **Enable tutorial group sync**; leave **Tutorial group sync: dry run**
  **on** (the default).
- [ ] Run the task now: *Site admin → Server → Scheduled tasks →* "Membership
  Bridge: sync tutorial groups" *→ Run now* (or from the CLI:
  `php admin/cli/scheduled_task.php --execute='\local_membershipbridge\task\sync_tutorial_groups'`).
- [ ] Read the task log. The summary line reports courses, groups created, members
  added/removed, rows skipped and removal guards; each skipped row says why:
  - `no Moodle course mapped` → missing/wrong `MOODLE_PRODUCTS` row (T1);
  - `no unique Moodle account for iMIS id …` → no Moodle user (or more than one)
    with that iMIS ID in the configured source field;
  - `… not enrolled; skipped` → the member is not enrolled in that course yet (T2);
  - `a group named '…' already exists with a different idnumber` → a hand-made group
    has the same name; rename one of them.
- [ ] Review the dry-run output: each group it would **create**, then one line per
  member it would add or remove —
  `group 'Tutorial A' (imis:…): would add user 123 (iMIS 20001)` — for new and
  existing groups alike, plus every skip and guard trip and the summary totals.
  (Per-member lines need 1.19.7; earlier versions show only the totals for groups
  that already exist.)
- [ ] When the dry-run output is what you expect, turn **dry run off** and run again.
- [ ] In each course, *Participants → Groups*: the groups exist under **Tutorials
  (iMIS)** with exactly the members iMIS lists.
- [ ] Remove one person from a tutorial group in iMIS, run again → they leave the
  Moodle group (and stay enrolled in the course).

### T4. Safety behaviour (confirm, don't just trust)

- [ ] **Empty read = no changes.** If the panel read returns no rows at all, the run
  aborts without touching anything (`ABORTED (no changes)`), so an outage or a
  permissions slip can never empty every group.
- [ ] **Removal guard.** When a run would remove **10 or more** members from one
  group *and* that is more than the guard percentage (default **50%**) of the group,
  those removals are skipped and logged (additions still happen). Small moves are
  never blocked.
- [ ] A failed REST read throws, so the task is marked failed and Moodle retries it
  with backoff; no partial changes are made from a partial read.

### T5. Schedule, logging, rollback

- [ ] Schedule is **nightly at 02:45** by default (1.19.5). Staff can change it in
  *Site admin → Server → Scheduled tasks*; check it there after the upgrade — a task
  Moodle has flagged as customised keeps its old (hourly) timing until reset.
- [ ] The audit log records the REST reads (`GET /api/MoodleTutorialGroup`, `GET
  /api/iqa?…`) with status **success** and no "value too long" warnings in the task
  log. (A successful token request is not logged; a `token` error row means the
  service-account login failed.)
- [ ] **Rollback:** untick *Enable tutorial group sync*. That stops all further
  changes; existing groups and memberships stay as they are. (Clearing the WSDL does
  **not** stop this feature — it uses the REST settings.)

## R. Registration sync over REST (optional — replaces ATS for enrolment, 1.21.0)

With **Sync provider = Direct iMIS REST**, Moodle reads a registrations IQA itself and
enrols or suspends locally, with no ATS involved. This is what lets ATS be retired.
Until it is proven, keep the iDMS push (§C/§D) as the enrolment mechanism. The
**Sync provider** setting can also keep the ATS syncs while records are pushed over
REST.

### R1. iMIS side

- [ ] A **registrations IQA**, *Available via REST API*, **no required prompts**, one
  row per registration, active **and** cancelled. Columns (matched ignoring case,
  spaces and underscores): `ID` (registrant's iMIS ID), `EventCode`, `Status`
  (blank = active), and optionally `RegistrationKey` (e.g.
  `CONCAT(OrderNumber, '-', ShipToId)`), `IsActiveRegistration`, `EndDate`, plus
  `FirstName`, `LastName`, `Email` for account creation. Use the contact's own email,
  not the order's. Join the contact on `ShipToId` as a required join.
- [ ] **Guests excluded** in the IQA (they must not be enrolled). Not needed where
  every registrant has their own iMIS account.
- [ ] Each event code has its `MOODLE_PRODUCTS` row (`{EventCode}/REG` → course id),
  as for the tutorial sync (§T1). The course id is the numeric Moodle course id of
  the site iMIS talks to. On staging, *Course mapping overrides* can point an event at
  a test course instead, and *Push course id overrides* can make that course's pushes
  report the course id iMIS holds results for (leave both empty in production).
- [ ] The REST service account can read this IQA.

### R2. Moodle side

- [ ] **Registrations IQA** set to the IQA path; **Registration sync: dry run** on.
- [ ] **Cancelled status codes** match iMIS (default `C`: a `Status` containing `C`
  is cancelled; blank is active). Any other status is reported as not recognised
  and neither enrols nor suspends, so check the dry-run report for those lines.
- [ ] **Look-back** (default 7 days after the event's `EndDate`). Registrations close
  before an event ends, so a short window still catches every normal change and the
  rare late cancellation. Anything later: run a one-member manual sync with
  *Include events outside the look-back window* ticked. Rows with no `EndDate`
  always count.
- [ ] **Create accounts** on (default) if registrants may have no Moodle account yet.
- [ ] **Login syncs off** to start (*Sync enrolments on login*, *Sync cancellations on
  login*, *Sync groups on login*): under REST each login sync reads the whole IQA.
  The nightly tasks cover everyone, and the manual sync page handles an urgent
  member. Turn them on later only if *Test connection* shows a small, fast IQA.
- [ ] **Sync provider = Direct iMIS REST**.

### R3. Dry run, then live

- [ ] *Test connection* (manual sync page) with a member ID lists that member's rows.
  An HTTP 400 shows iMIS's reason after it (also in the audit log); the usual
  causes are a filter still set to prompt, *Available via REST API* not ticked, or
  a mistyped IQA path.
- [ ] Run the enrolment and cancellation tasks (or a one-member manual sync) and read
  the report: who would be enrolled, reactivated, suspended or created, plus every
  skipped row and its reason.
- [ ] Compare against current enrolments. Then turn **dry run off** and run again.
- [ ] **Single writer:** once the REST sync is live, switch the **iDMS push** off for
  enrolment and cancellation, so only one system enrols and suspends.

### R4. Safety and rollback

- [ ] An empty IQA read changes nothing; a member and course with no rows (or with an
  unrecognised status) is never suspended; a run that would suspend 10+ members and more than the guard percentage
  of a course skips that course's suspensions.
- [ ] **Rollback:** set **Sync provider** back to *ATS SOAP bridge* or *Same as
  connection provider*, or clear the Registrations IQA, and turn the iDMS push back on.
- [ ] **Course welcome email.** If a course's *Manual enrolments* method has *Send
  course welcome message* on, Moodle emails a learner each time they are enrolled
  through it. The registration sync sends it once per new enrolment: learners already
  active are skipped, and since 1.26.4 a cancelled learner who registers again is
  reactivated without a second welcome. The ATS bridge and the iDMS push enrol through
  Moodle's `enrol_manual_enrol_users` web service, which sends the message on **every**
  call, even for a learner already enrolled, so a re-sent order means a second email.
  Decide per course before the first full sync, which can enrol hundreds at once.

## S. Staging rehearsal on the Catalyst copy (before the production cutover)

After the move to Catalyst, they provide a **recent copy of live Moodle** as a staging
site, and iMIS staging can be **refreshed** from production on request. Course ids and
`MOODLE_PRODUCTS` then match live, so the syncs and pushes can be rehearsed end to end
on realistic, non-production data, with **no course overrides**.

### S1. Before the copy's first cron run (ask Catalyst)

A copy of live carries live's plugin settings: the production iMIS REST URL and
service account, the ATS WSDL and token, and every push switched on. Its first cron
run could write to **production iMIS**.

- [ ] Catalyst pins the endpoints and credentials in the staging `config.php`, so the
  copy can never reach production (forced settings cannot be changed in the UI):
  ```php
  $CFG->forced_plugin_settings['local_membershipbridge'] = [
      'rest_base_url' => 'https://<imis-staging-host>',
      'rest_username' => '<staging service account>',
      'rest_password' => '<staging password>',
      'wsdl_url'         => '<ATS staging WSDL URL>',
      'service_endpoint' => '<ATS staging endpoint URL>',
      'auth_token'       => '<ATS staging token>',
  ];
  $CFG->noemailever = true;   // the copy holds real member emails
  ```
  Force `service_endpoint` too, even if live leaves it blank: when set it overrides the
  address in the WSDL, so a production value carried over on the copy would still send
  ATS requests to production after the WSDL is changed. Use the staging bridge's
  `.asmx` address (the WSDL URL without `?WSDL`).
- [ ] The vendor plugin (`local_imis`) on the copy is disabled, or pointed at staging,
  for the same reason.
- [ ] ATS staging's bridge settings point at the **staging** Moodle and iMIS staging
  (§D), so its syncs and the plugin's ATS pushes stay inside staging.
- [ ] iMIS staging has been refreshed, and the registrations IQA, `MoodleCourseLookup`
  and `MoodleTutorialGroup` exist there with *Available via REST API* and no required
  prompts (§R1, §T1).
- [ ] **Shell access:** a managed host may not offer one. Ask Catalyst to enable
  *Run now* for scheduled tasks (needs *Path to PHP CLI*), or to run named tasks on
  request. The results back-fill (`cli/backfill.php`) is command-line only.

### S2. First login: switch writes off, configure

- [ ] Upgrade `local_membershipbridge` to the current release.
- [ ] Push toggles **off** to start: *Push completions*, *Push quiz scores*, *Push
  module completions*, and no types ticked in *Push activity scores*.
- [ ] Login syncs **off** (*Sync enrolments / cancellations / groups on login*).
- [ ] Choose the provider combination. **Default, while ATS stays in use:**
  *Connection provider* = **ATS SOAP bridge** (pushes via `MoodleUpdate` /
  `CreateActivity`, as the vendor did) and *Sync provider* = **Same as connection
  provider** (ATS enrolment, cancellation and group syncs). Direct REST is then used
  only by the tutorial group sync. Other combinations, switchable later by settings:

  | Combination | Connection provider | Sync provider | Registrations IQA |
  |---|---|---|---|
  | ATS for everything, REST for tutorial groups (**default**) | ATS SOAP bridge | Same as connection provider | blank |
  | REST pushes, ATS syncs | Direct iMIS REST | ATS SOAP bridge | blank |
  | Everything over REST (retire ATS) | Direct iMIS REST | Same as connection provider | set (§R) |

  Only one system may enrol and suspend: with ATS syncs, leave the *Registrations
  IQA* blank (the REST registration sync stays off).
- [ ] Only for the all-REST combination: *Registrations IQA* set; *look-back* 7;
  *Registration sync: dry run* **on**.
- [ ] *iMIS ID format*: **Digits only**.
- [ ] *Tutorial group sync*: enabled, *dry run* **on**.
- [ ] *Course mapping overrides* and *Push course id overrides*: **empty**.
- [ ] *Test connection* with a known member lists their registrations, with end dates.

### S3. Syncs: dry run, then live

- [ ] **ATS syncs (default):** run *Sync enrolments*, *Sync cancellations* and *Sync
  groups*, and a one-member manual sync, with ATS staging; check the audit log shows
  `SendNewOrdersToMoodle` / `SendCancelledOrdersToMoodle` / `UpdateMoodleGroups`
  succeeding and the resulting enrolments, suspensions and groups in Moodle.
- [ ] **REST registration sync (all-REST only):** run *Sync enrolments* and *Sync
  cancellations* in dry run. Review the report: would-create and would-enrol should be
  **small** (live already holds most accounts and enrolments); no unexpected
  suspensions; unmapped events are only non-Moodle events; no "status not recognised"
  lines.
- [ ] Run *Sync tutorial groups* in dry run; review the would-add / would-remove lines.
- [ ] Turn the dry run(s) **off**, run again, and spot-check a few courses
  (enrolments, new accounts' details and SAML2 sign-in, `imis:` groups and members).

### S4. Pushes against real existing records

- [ ] Turn *Push completions* on. *Push completion* (sync page) for a member who
  already has a `MOODLE` row for that course, then for one without, then repeat:
  - **ATS pushes (default):** the audit log shows `MoodleUpdate` succeeding; check in
    iMIS that ATS updated the existing row rather than adding one (as for the vendor),
    and created a row for the member without one.
  - **REST pushes:** the audit log shows a `PUT` to the existing row's `SEQN` (updated
    in place, **no duplicate**), a `POST` for the member without a row, and still one
    row after the repeat.
- [ ] Turn *Push module completions* and *Push quiz scores* on. *Push activity note*,
  a student quiz submission and a module completion each add a `MOODLENOTE` in the
  *Course Notes* panel, in the vendor's format ("Module completed : …" / "Score : …");
  the `MOODLE` row is untouched. Over ATS these are `CreateActivity` calls; over REST,
  `POST /api/Activity`.
- [ ] Optional: tick a type in *Push activity scores* (e.g. Assignment), grade one,
  then re-grade with the same mark: one "Score : …" note, nothing on the re-grade.
- [ ] Back-fill one member, then one course (`cli/backfill.php --course=ID
  [--member=IMISID]`, dry run first): completions arrive with their real dates;
  non-numeric IDs are skipped; a second run sends no scores again.
- [ ] Audit log: no errors other than ones you can explain.

### S5. Compare with the vendor, then sign off

- [ ] For a few members, the records the plugin wrote on staging match what the
  vendor wrote on production for the same course: same row, status and grade
  columns, and notes in the same columns.
- [ ] Note anything that differs, decide it, and record it below; then plan the
  production cutover (§A to §G, and §R for the registration sync).

## E. Cutover — avoid double-writes (critical)

- [ ] **Only one plugin's observers active at a time.** With both installed,
  `course_completed` fires **both** observers and produces duplicate iMIS records.
  **Disable the vendor `local_imis` plugin's observers** before enabling the new
  plugin's writes — but **do NOT uninstall it yet.** Keep it installed (disabled)
  through the rollback window so a switch-back stays config-only (see G); uninstall
  only after post-cutover sign-off (F).
  - Moodle has **no on/off switch for a local plugin**: while `local/imis` is on disk,
    its observers run. Stop it with the vendor plugin's own enable setting, if it has
    one. Otherwise **move the `local/imis` folder out of the Moodle tree** (don't
    uninstall it): Moodle then lists it as *missing from disk*. Its code stops
    running, and its settings, logs and token stay in the database. Rollback is
    moving the folder back and purging caches.
  - Moving the folder out also removes its inbound functions (`local_imis_*`), so
    re-point the ATS `FN_*` mappings (§D) **in the same window**.
- [ ] Enable the new plugin's write toggles.
- [ ] Decide on the login/nightly syncs: if ATS now pushes enrolments inbound, the
  new plugin's login/nightly **pull** syncs may be redundant or conflicting —
  enable only what you intend to use.

### E2. Alternative: replace the vendor plugin outright

Instead of keeping `local_imis` installed but stopped, you can uninstall it and set
up `local_membershipbridge` and the bridge in one window. The two plugins don't
depend on each other, and the records already in iMIS are unaffected. Suited to
staging and the Catalyst copy, where it also rehearses the cutover. On live, prefer
§E unless you accept the slower rollback below.

What it costs:
- **Data:** the vendor plugin's settings, its log (`local_imis_logs`), and its own
  service and token are deleted.
- **Inbound:** ATS calls to `local_imis_*` fail from the uninstall until §C and §D
  are done, so do them in the same window.
- **Rollback:** restoring the database backup and the vendor folder (§G), not a
  config switch.

> **Uninstalling does not remove the plugin's folder.** If `local/imis` is still on
> disk, Moodle reinstalls the vendor plugin with **default settings** at the next
> upgrade check, and every completion is then written twice. Remove the folder as
> part of the uninstall.

- [ ] **Keep copies first:**
  - a database backup;
  - the `local/imis` folder;
  - screenshots of the vendor plugin's settings and of ATS `MOODLE_SETTINGS`
    (`FN_*`, `WEBSERVICE_TOKEN`, `ROLE_ID`).
- [ ] Pause cron and enable maintenance mode.
- [ ] Uninstall `local_imis` (*Plugins overview → Uninstall*, or
  `php admin/cli/uninstall_plugins.php --plugins=local_imis --run`).
- [ ] Move `local/imis` out of the Moodle tree.
  - **Check:** it no longer appears in *Plugins overview*.
- [ ] Install `local_membershipbridge` and configure it with **all writes off**
  (§A): provider, WSDL and service endpoint, AuthToken, iMIS ID source = ID number.
- [ ] Inbound service and token (§C), then re-point ATS (§D): `FN_*` to the core
  functions, unenrol to `local_membershipbridge_cancel_enrolment`, and the new
  `WEBSERVICE_TOKEN`.
- [ ] Turn the pushes on.
  - **Outbound (§B):** a completion and a quiz score reach iMIS.
  - **Inbound (§C):** a registration enrols someone and a cancellation suspends
    them.
- [ ] Resume cron, disable maintenance mode, then follow §F.

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
- [ ] Spot-check one real **cancellation**: the learner's enrolment is
  **suspended** (grades and completion kept), not deleted (§C).
- [ ] If the tutorial group sync is live (§T): the first nightly run's task log is
  clean and a known tutorial group matches iMIS.
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

> If the vendor plugin was already **uninstalled** (including by the §E2 route),
> rollback is **not** config-only: Moodle purged its configuration and tables. Either
> restore the database backup and the `local/imis` folder taken before the uninstall,
> or reinstall and reconfigure it from the saved settings. This is exactly why §E
> keeps it installed-but-stopped until sign-off.

Rolling back the vendor swap does **not** require turning off the tutorial group
sync (§T) — the vendor never managed those groups. Roll that back separately, by
unticking *Enable tutorial group sync* (§T5).

## M. Moving a site from `local_imisbridge` to `local_membershipbridge` (2.0.0)

Moodle treats the renamed component as a new plugin. On install it carries the old
one's site over (`classes/migration.php`):
- settings;
- audit log and sent-score records, with their ids, so no score is sent twice;
- each role's grants of the old capabilities;
- the tutorial-group memberships the old plugin manages;
- pushes it queued but had not sent. On a version before 1.8.0, queued activity
  pushes still carry the course idnumber or shortname, so they are dropped
  instead; re-send those completions with the back-fill;
- the cancel function on any hand-built service, such as the ATS inbound one.

It then turns the old plugin's pushes and syncs **off**, so the two never both
write.

> **Never uninstall `local_imisbridge` while its folder is still on disk, or before
> `local_membershipbridge` is installed with its settings.** Uninstalling deletes the
> old settings, audit log, sent-score records, capability grants and service
> functions. If the old code is still in `local/imisbridge`, Moodle then reinstalls
> it with **default** settings at the next upgrade check. The carry-over has nothing
> left to copy, and everything must be re-entered by hand. (This happened on the
> staging sandbox, where the code swap had silently failed.)

Do it in a maintenance window, checking each step before the next:

- [ ] **Back up the database.** It is the only way back if a step goes wrong.
- [ ] Pause cron and enable maintenance mode.
- [ ] Unpack the 2.0.0 code to a folder named `membershipbridge`. The release zip
  unpacks to that name; GitHub's *Download ZIP* unpacks to `membershipbridge-main`,
  so rename it.
  - **Check:** `membershipbridge/version.php` exists and contains
    `$plugin->component = 'local_membershipbridge';`. It must not be one folder
    deeper.
- [ ] Copy it to `local/membershipbridge` (owned by the web server user), then move
  `local/imisbridge` **out of** the Moodle tree. Keep it as a backup; don't delete it.
  - **Check:** `local/membershipbridge/version.php` exists, and `local/imisbridge`
    does not.
- [ ] Install: *Site administration → Notifications*, or
  `php admin/cli/upgrade.php --non-interactive` (in Moodle 5.1+ the CLI scripts are
  in `admin/cli`, outside `public/`).
  - **Check:** `php admin/cli/cfg.php --component=local_membershipbridge
    --name=version` prints `2026102800` (not "No such configuration variable"),
    and `local_imisbridge` shows as *missing from disk* in *Plugins overview*.
- [ ] Check the settings carried over (*Plugins → Local plugins → Membership
  Bridge*): connection, REST and ATS settings, the iMIS ID source, and *Push
  completions* and the other toggles as they were. The audit log shows the old
  entries.
- [ ] **Only now** uninstall `local_imisbridge` (*Plugins overview → Uninstall*, or
  `php admin/cli/uninstall_plugins.php --plugins=local_imisbridge --run`). This
  deletes its old settings, tables, capabilities, its own *iMIS bridge* service and
  that service's tokens.
- [ ] **Tokens and services.**
  - The plugin's own service is now *Membership bridge*. Add its authorised users
    and create new tokens there if anything used the old *iMIS bridge* service
    (e.g. a completions pull).
  - A hand-built inbound service keeps its token and now also has
    `local_membershipbridge_cancel_enrolment`.
- [ ] **ATS `MOODLE_SETTINGS`:** `FN_ENROL_MANUAL_UNENROL_USERS` →
  `local_membershipbridge_cancel_enrolment`, and `WEBSERVICE_TOKEN` if a token
  changed.
- [ ] The inbound service account's role has
  `local/membershipbridge:cancelenrolment` (copied from the old grant; check it).
- [ ] *Scheduled tasks*: the new tasks run at their default times. Re-apply any
  custom schedule.
- [ ] Purge caches, disable maintenance mode, resume cron. Then run *Test
  connection*, a one-member manual sync and a test cancellation, and check the
  audit log.

---

## Sign-off

A changeover is confirmed successful when: **B** passes (new records match vendor
records and the existing history), **C** passes (inbound create/enrol works,
including hidden courses and existing users), **E** holds (no double-writes), the
note choices — which activity types send scores and whether module-completion notes
are sent (decisions 1–2) — are made deliberately, and **F** shows a clean audit log
in both directions with no duplicates.

The tutorial group sync is signed off separately when **T3** (a live run matches
iMIS, including a removal) and **T4** (empty-read abort and removal guard
understood) pass and **T5** shows a clean nightly run.
