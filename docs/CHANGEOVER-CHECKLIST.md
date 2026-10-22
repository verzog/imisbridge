# iMIS plugin changeover checklist

Confirming a successful switch from the vendor `local_imis` plugin to
`local_imisbridge` on the live Moodle. Use alongside `TEST-CHECKLIST.md`
(setup/connectivity), `TEST-PLAN.md` (the per-feature test matrix) and
`VENDOR-ARCHITECTURE.md` (what the vendor did).

The crux of "successful" is: (B) the new plugin writes the **same** iMIS records
the vendor did, (C) inbound create/enrol still works, and (E) **only one** plugin
writes at a time. Everything else supports those.

**Tutorial group sync (§T) is a separate go-live.** It is new functionality the
vendor never had (iMIS tutorial groups → Moodle course groups, over direct REST),
so it has no parity to prove and does not depend on the vendor swap. It can go
live before, with, or after the changeover; §T has its own checks.

---

## What is the same, and what differs

| Concern | Vendor `local_imis` | New `local_imisbridge` |
|---|---|---|
| Completion → iMIS | `course_completed` → `MoodleUpdate` (canonical MOODLE record) | `course_completed` → `MoodleUpdate` — **same** |
| Score → iMIS | `user_graded` → `createActivity` (MOODLENOTE) | `quiz_attempt_submitted` → `CreateActivity` (MOODLENOTE, `Note2 = score`) — same record type, **quiz only** |
| Module completions | `course_module_completion_updated` → MOODLENOTE | **opt-in** via `push_module_completions` (default off, **graded modules only**) |
| Inbound provisioning | `local_imis_*` web-service functions (in-plugin) | **create/enrol: none in-plugin** → Moodle **core** functions on a hand-built service; **cancel:** `local_imisbridge_cancel_enrolment` (suspend, keeps data) |
| Tutorial groups | none | **new:** nightly REST pull of the `MoodleTutorialGroup` panel (§T) |
| Identity field | `$user->idnumber` | `imis_id_source` setting (set to **ID number**) |
| productID | numeric Moodle course id | numeric Moodle course id — **same** |

Verified from the code: the new plugin's observers are `user_loggedin`,
`course_completed`, `mod_quiz\event\attempt_submitted`, and (opt-in)
`course_module_completion_updated`. Its web-service functions are
`local_imisbridge_get_completions_since` (a read-only completions pull) and
`local_imisbridge_cancel_enrolment` (suspend-not-unenrol cancellation, §C), both on
a restricted service — it exposes **no** create/get/update/enrol functions.

---

## Three parity decisions that determine success

Resolve these before cutover — they are behaviour changes, not bugs:

1. **Score coverage narrows.** The vendor wrote a score note on **any**
   `user_graded` (every gradebook activity); the new plugin writes one on **quiz
   submission only**. Both write the same kind of record — an append-only
   `MOODLENOTE`, never the canonical `MOODLE` completion record. If any course awards
   credit via a non-quiz graded activity (assignment, SCORM, lesson, …), that score
   will **not** flow to iMIS. Decide whether quiz-only is acceptable, or add a
   `user_graded` observer / make the trigger configurable.
2. **The module-completion MOODLENOTE trail is opt-in.** The vendor also wrote
   `MOODLENOTE` records for module completions. The new plugin can too — enable
   `push_module_completions` (default off, graded modules only). Decide whether
   that trail is needed by any iMIS report (if so, turn the toggle on and mind the
   volume). Together with decision 1, the only notes the vendor wrote that the new
   plugin cannot are score notes for non-quiz graded activities.
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
  `local_imisbridge_cancel_enrolment`) to the external service the ATS token uses
  (Site admin → Server → Web services → External services → *service* → Functions),
  and confirm the token user is an authorised user of it. When mapping to
  **`local_imisbridge_cancel_enrolment`**: it only exists after
  the plugin is **deployed and upgraded** (its `db/services.php` then registers it and
  adds it to the plugin's own `iMIS bridge` service), and the service-account role
  must hold **`local/imisbridge:cancelenrolment`** (checked inside the function; a
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

`local_imisbridge` owns:

- the **outbound** push — completions via `MoodleUpdate`, quiz scores (and opt-in
  module notes) via `CreateActivity`;
- the inbound **cancellation** function `local_imisbridge_cancel_enrolment` (called
  by ATS, suspend-not-unenrol — §C);
- **tutorial group membership** — the §T scheduled task, which makes Moodle course
  groups match the iMIS `MoodleTutorialGroup` panel over direct REST.

ATS's own `UpdateMoodleGroups` remains a no-op while `GROUP_IQA_NAME` is empty —
keep it that way, so tutorial groups have **one** writer (§T).

## T. Tutorial group sync go-live (direct REST — independent of the vendor swap)

A nightly scheduled task (`\local_imisbridge\task\sync_tutorial_groups`, 02:45 by
default) reads the iMIS `MoodleTutorialGroup` panel and makes each course's groups
match it: it creates the groups (under the grouping **Tutorials (iMIS)**), adds the
people iMIS lists, and removes the people iMIS no longer lists. It runs as cron
using Moodle's internal group API, so it needs **no** Moodle web-service user or
group capabilities — only an **iMIS** account that can read the panel and the
course-lookup IQA. It only ever touches groups whose idnumber starts `imis:` and
memberships it created itself (component `local_imisbridge`), so groups and members
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

### T2. Moodle side

- [ ] **Direct iMIS REST** settings filled in — even with `transport = soap`:
  - **REST base URL** = the iMIS **site root** (e.g. `https://<imis-host>`), with
    **no** `/api` suffix;
  - **REST username / password** = the T1 service account.
- [ ] Outbound HTTPS from the Moodle server to the iMIS host is open.
- [ ] **iMIS contact ID source = ID number** (the sync matches panel members to
  Moodle users through this setting; §A).
- [ ] Members are **enrolled** in the course before their group row is processed —
  the sync skips anyone not enrolled (it never enrols). Enrolment comes from the
  iDMS push (§C), so a brand-new registrant can take one nightly cycle to appear in
  their group.

### T3. First run — dry run, then live

- [ ] Tick **Enable tutorial group sync**; leave **Tutorial group sync: dry run**
  **on** (the default).
- [ ] Run the task now: *Site admin → Server → Scheduled tasks →* "Membership
  Bridge: sync tutorial groups" *→ Run now* (or from the CLI:
  `php admin/cli/scheduled_task.php --execute='\local_imisbridge\task\sync_tutorial_groups'`).
- [ ] Read the task log. The summary line reports courses, groups created, members
  added/removed, rows skipped and removal guards; each skipped row says why:
  - `no Moodle course mapped` → missing/wrong `MOODLE_PRODUCTS` row (T1);
  - `no unique Moodle account for iMIS id …` → no Moodle user (or more than one)
    with that iMIS ID in the configured source field;
  - `… not enrolled; skipped` → the member is not enrolled in that course yet (T2).
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
- [ ] The audit log records the REST calls (`POST /token`, `GET
  /api/MoodleTutorialGroup`, `GET /api/iqa?…`) with status **success** and no
  "value too long" warnings in the task log.
- [ ] **Rollback:** untick *Enable tutorial group sync*. That stops all further
  changes; existing groups and memberships stay as they are. (Clearing the WSDL does
  **not** stop this feature — it uses the REST settings.)

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

> If the vendor plugin was already **uninstalled**, rollback is **not** config-only:
> Moodle purged its code, configuration and tables, so it must be **reinstalled and
> reconfigured** first. This is exactly why §E keeps it installed-but-disabled until
> sign-off.

Rolling back the vendor swap does **not** require turning off the tutorial group
sync (§T) — the vendor never managed those groups. Roll that back separately, by
unticking *Enable tutorial group sync* (§T5).

---

## Sign-off

A changeover is confirmed successful when: **B** passes (new records match vendor
records and the existing history), **C** passes (inbound create/enrol works,
including hidden courses and existing users), **E** holds (no double-writes), the
two coverage gaps (score trigger, MOODLENOTE trail) are a conscious accepted
decision, and **F** shows a clean audit log in both directions with no duplicates.

The tutorial group sync is signed off separately when **T3** (a live run matches
iMIS, including a removal) and **T4** (empty-read abort and removal guard
understood) pass and **T5** shows a clean nightly run.
