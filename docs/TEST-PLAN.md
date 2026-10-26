# iMIS Bridge — test plan (living document)

The master, continuously-updated test plan for `local_imisbridge`. Every change
is validated on a **test/staging environment before production**. This file is
updated on each PR that adds or changes behaviour — see the revision log at the
bottom.

- Environment setup, prerequisites and the **staging-iMIS safety rules** live in
  [`TEST-CHECKLIST.md`](TEST-CHECKLIST.md); read that first.
- **Golden rule:** the write/sync and provisioning paths mutate the iMIS behind
  the WSDL. Run them **only against a staging iMIS/bridge**, never production,
  until signed off here.

**Status key:** ☐ not tested · 🔄 in progress · ✅ pass · ❌ fail · ⛔ blocked · n/a

| Env | Moodle version | iMIS/bridge | Tester | Date |
|-----|----------------|-------------|--------|------|
| _(fill in per run)_ | | staging / prod | | |

---

## P. Staging environment prerequisites (check before section A)

The plugin installs on the **Moodle** site only; the iMIS instance and the ATS
bridge are configured on their side. On a freshly provisioned or packaged Moodle
(e.g. a distro/appliance build) these fail **silently**, so verify them first.

> **Three layers, three different sites — map them before you test.**
> "Staging" is ambiguous here because *three independent systems* are involved,
> and **the ATS test environment is not the same as our dev iMIS**. The real
> hostnames for this deployment live in the **internal runbook** (this repo is
> public); the roles below are what matter:
>
> | Layer | Dev / test role | Production role | What lives there |
> |-------|-----------------|-----------------|------------------|
> | **Moodle** (plugin runs here) | `<moodle-staging>` (5.3) | `<moodle-prod>` | The plugin + its outbound `\SoapClient` |
> | **ATS middleware** (`wsmoodle.asmx`) | `<ats-sandbox>` | `<ats-prod>` | The SOAP bridge the plugin calls |
> | **iMIS** (AMS data) | `<dev-imis>` (ours) | `<prod-imis>` | Contacts, `MOODLE_PRODUCTS` mappings, activity records |
>
> **The trap:** the ATS *test* middleware (`<ats-sandbox>`) is ATS's own sandbox,
> backed by **ATS test iMIS data — not our `<dev-imis>`**. And `<dev-imis>`
> itself currently references the **production** ATS middleware (`<ats-prod>`).
> Consequences:
> - **Contract / connectivity tests** (B, C1–C5 mechanics) → run against the ATS
>   sandbox (`<ats-sandbox>`), **but only after clearing the write gates below.**
>   The sandbox never touches our iMIS data, but "sandbox" is **not**
>   automatically safe — see the gates.
> - **Data-matching validation — test C6** (our numeric `productID` lands on the
>   right iMIS product) → can **only** be done against **our own iMIS**, because
>   only it holds our real `MOODLE_PRODUCTS` course↔product mappings and history.
>   The ATS sandbox won't have them.
> - There is **no confirmed `wsmoodle.asmx` endpoint wired to `<dev-imis>`** yet.
>   Until ATS provisions one, the only place with our real mappings is
>   **production**, so **C6 against production is READ-ONLY**: inspect an existing
>   vendor-created record's ProductID and compare it to what our plugin *would*
>   send. **Do not write to production** — a disposable contact/product does not
>   stop a wrong id, mapping or credential from modifying a real record. The
>   **write half of C6 stays blocked** until a `<dev-imis>`-wired endpoint exists.
> - **`<ats-prod>` = PRODUCTION.** Every write to it is a production write,
>   regardless of which Moodle sends it.
> - **The `service_endpoint` override (1.7.0) selects the host, not the iMIS
>   behind it.** It cannot make `<ats-sandbox>` reach our `<dev-imis>` data —
>   that requires ATS wiring on their side.
>
> **Write gates — clear ALL of these before any C1–C5 write, even "to the
> sandbox":**
> 1. **Effective address.** Run the connection test and confirm it reports the
>    request endpoint is actually the sandbox host — the `<ats-sandbox>` WSDL may
>    embed the **production** `<soap:address>`, in which case `\SoapClient` sends
>    to production unless you set the `service_endpoint` override (§B, 1.7.0).
> 2. **Sandbox credentials + fixtures.** Use an **AuthToken issued for the
>    sandbox** (a prod token against the sandbox fails — token and endpoint must
>    share an environment), and have sandbox fixtures to hit: a known contact id,
>    and (for C3–C6) a product/course mapping, order and group. Our normal SCCA
>    test contact/mappings do **not** exist in the ATS sandbox, so a
>    data-dependent call failing there is a fixture gap, not a contract bug.
> 3. **Callback Moodle (C3–C5 only).** `SendNewOrdersToMoodle`,
>    `SendCancelledOrdersToMoodle` and `UpdateMoodleGroups` make the bridge call
>    **back into whichever Moodle its callback configuration names** — which may
>    be **production** Moodle. Isolated sandbox *iMIS* data does not protect
>    production *Moodle* users. Confirm the sandbox bridge's Moodle callback
>    targets a **staging Moodle** before running C3–C5, or they may enrol,
>    unenrol or regroup production users.
>
> **Open item for ATS:** provision (or identify) a `wsmoodle.asmx` endpoint wired
> to our **`<dev-imis>`** dev iMIS, so C6 and write tests can run against our own
> data without touching production. (See the ATS questions.)

> **Pre-flight with a raw SOAP client (Bruno / Postman / the `.asmx` test page)
> — do this before the plugin.** Hitting `wsmoodle.asmx` directly, one
> **read-only** request at a time, confirms the open questions with full raw-XML
> visibility and **zero** risk from plugin toggles or cron:
> - `GET …/wsmoodle.asmx?WSDL` → read the `<soap:address location>` for the
>   **effective endpoint** (gate 1 — is a "test" WSDL actually pointing at prod?).
> - `GetBridgeSettings` → returns the bridge config including the **Moodle
>   callback (`REST_ENDPOINT`)** and `COURSE_URL`, so you can see **which Moodle
>   the bridge calls back into** (gate 3) and confirm the environment.
> - `MoodleGetUserProfile` (a known contact id) → confirms **which iMIS** is
>   behind the endpoint and that the **AuthToken is valid / correctly scoped**
>   (gate 2).
> - `getActivityByIDAndType` (contact + the `MOODLE` activity type) → read
>   existing activity records and their **ProductID** values: this is the
>   **read-only half of C6**, done safely per contact.
>
> Keep Bruno to those read operations. A **write/sync** op from Bruno
> (`MoodleUpdate`, `SendNewOrdersToMoodle`, `SendCancelledOrdersToMoodle`,
> `UpdateMoodleGroups`, `createActivity`) against a production-backed endpoint is
> still a production write — Bruno does not make it safe. (`.asmx` = SOAP 1.1;
> each method's exact request envelope and `SOAPAction` header are on the
> endpoint's own test page.)

> **Three different "SOAP/REST" things — don't conflate them.**
> 1. **PHP `soap` extension** (P1) — powers the plugin's *outbound* calls
>    (`\SoapClient` → the ATS bridge) and is **always required, baseline
>    included**. Check with `php -m`. The existing completions channel already
>    depends on it, so it is almost certainly present — but verify.
> 2. **Moodle REST protocol** (`webservice_rest`) — an *inbound* channel (calls
>    **to** Moodle). Used by the pull WS (`get_completions_since`, §H), future
>    inbound provisioning, and the ATS bridge's callback into Moodle.
> 3. **Moodle SOAP protocol** (`webservice_soap`) — also *inbound*, and **not the
>    same thing as the PHP soap extension**. Our plugin doesn't **use** this
>    transport, so leave it **OFF** — a disabled Moodle SOAP protocol does
>    **not** affect our outbound completions (those use the PHP soap extension in
>    #1, which is unrelated). **Caveat (security):** Moodle external functions
>    are not transport-specific, so if the Moodle SOAP protocol is ever enabled
>    for another integration, our `get_completions_since` pull WS — a **bulk
>    personal-data export** — becomes reachable over SOAP too, exactly as over
>    REST. It's an unused/unsupported transport for us, not a nonexistent surface:
>    keep SOAP off, and if it's on for any reason, include the pull WS in
>    protocol and security testing (§H, §J).
>
> **Live environment (confirmed 2026-10-19):** under *Server → Web services →
> Manage protocols*, **REST is enabled** (the still-live vendor plugin needs it
> for its inbound create/enrol) and the **Moodle SOAP protocol is disabled**.
> That is the correct posture — keep it. **Do not enable the Moodle SOAP
> protocol**, and **do not turn REST off** while the vendor plugin is still
> running.
>
> **What the baseline actually needs:** the Phase-1 baseline is **outbound
> pushes (completions via `MoodleUpdate`, D2; quiz scores via `CreateActivity`, D3)
> + SSO only**. It uses *no* Moodle
> web-service protocol, so REST being on (for the vendor) neither helps nor harms
> it — the baseline needs only the PHP soap extension (P1), cron (P2) and
> outbound HTTPS (P4). On a clean Moodle with no vendor plugin you *could* leave
> web services off entirely for a smaller surface; on the live/mirror site you
> leave the vendor's REST as-is.
>
> **Why the sync operations are still *not* baseline:** `SendNewOrdersToMoodle`,
> `SendCancelledOrdersToMoodle` and `UpdateMoodleGroups` are outbound SOAP calls
> from us, but their documented effect is to make the **ATS bridge call *back*
> into Moodle** (create/enrol/group changes) over the REST callback. REST being
> enabled is **necessary but not sufficient**: that callback targets *inbound
> provisioning functions*. Until cutover the bridge's callback lands on the
> **vendor's** `local_imis_*` functions; after cutover it lands on the **core
> functions service** built in `CHANGEOVER-CHECKLIST.md` §C (core create/enrol +
> our `local_imisbridge_cancel_enrolment`). So every order/enrolment/cancellation/
> group **sync** row below (C3–C5, D1, E3, F2) is an **updated-version-phase**
> test that needs that inbound service in place — **not** part of the Phase-1
> baseline. (On this install inbound is driven by the iDMS push, so these pull
> syncs stay off anyway — `CHANGEOVER-CHECKLIST.md`, *Division of labour*.)

| ID | Check | Expected | Status | Notes |
|----|-------|----------|--------|-------|
| P0 | **Map each endpoint → iMIS AND clear the write gates** (ask ATS; confirm read-only first) | For every `wsmoodle.asmx` URL: you know which iMIS it reads/writes; the connection test (or a raw SOAP client — see pre-flight below) confirms the **effective request host**; you hold a **sandbox-scoped AuthToken + fixtures**; and (for C3–C5) the bridge's **Moodle callback targets staging** | ☐ | See the three-layer topology note + the three write gates above. **Do all of this before ANY write test** — the hostname alone does not tell you |
| P1 | **PHP `soap` extension enabled** (baseline) | `php -m` lists `soap` (or under *Site admin → Server → PHP info*) | ☐ | The entire *outbound* path uses `\SoapClient`; without it every iMIS call throws at construction. **Distinct from the Moodle SOAP *protocol*** (a different thing — see the §P note). **#1 thing to check on a packaged Moodle.** |
| P2 | **Moodle cron runs every minute** (baseline) | Recent runs under *Site admin → Server → Tasks → Task processing* | ☐ | Completion/quiz/login sync are queued adhoc tasks — no cron, nothing reaches iMIS |
| P3 | **Moodle web-service protocols** — leave as the vendor set them | Live/mirror: **REST enabled** (vendor inbound), **Moodle SOAP protocol disabled** — keep both as-is. Clean Moodle with no vendor: web services may be off entirely | ☐ | Inbound layer; unused by outbound completions or SSO. Our plugin needs REST only for §H / provisioning (updated-version phase). **Leave the Moodle SOAP protocol off** — we don't use it; but external functions aren't transport-specific, so if it's enabled for another integration the `get_completions_since` pull WS is reachable over SOAP too and must be tested. Outbound uses the PHP soap extension (P1), not this |
| P4 | **Outbound HTTPS to the ATS bridge host** is open from the Moodle server | The **Test connection** button loads `GetBridgeSettings` | ☐ | Confirms reachability + the WSDL loads |
| P5 | **Supported Moodle/PHP** | Moodle 5.1–5.3; PHP 8.2–8.4 | ☐ | CI covers these |

---

## A. Install / upgrade

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| A1 | Fresh install into `local/imisbridge` | Installs with no errors; appears under Local plugins | ☐ | Folder must be `imisbridge`, not `imis_bridge` |
| A2 | Upgrade from a prior version | `local_imisbridge_log` table created; version advances; stored settings retained | ☐ | |
| A3 | Install from the release zip | Correct `imisbridge/` root folder; installs cleanly | ☐ | |
| A4 | Uninstall | Plugin removed; `local_imisbridge_log` dropped | ☐ | |

## B. Configuration & connectivity (read-only — safe against production *after lockdown*)

> **Lock down writes before saving a production WSDL.** All three login-sync
> toggles, both observer push toggles, and all three nightly-sync task toggles
> default **on**, and the scheduled tasks are registered enabled — so once a
> production WSDL is saved, a login or a cron run will **write to production**,
> even if you only intend the read-only tests below. Do B0 first. Only
> `GetBridgeSettings` and the profile lookup are production-safe; everything that
> writes or **reconfigures the bridge** (including `SetupBridge`, now in §C) is
> staging-only.

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| B0 | **(Production only) Lock down first:** disable all login-sync, push and nightly-sync toggles, and pause the plugin's scheduled tasks / cron | No automatic write can fire once the WSDL is set | ☐ | Writers + tasks default enabled |
| B1 | Settings page loads; WSDL blank on fresh install | No hard-coded endpoint | ☐ | |
| B2 | Set WSDL + ATS API AuthToken + iMIS ID source | Saved | ☐ | Staging WSDL for write tests; production only after B0 |
| B3 | **Test connection**, no contact ID → `GetBridgeSettings` | Succeeds; audit success row | ☐ | Sends AuthToken when configured (1.6.1) |
| B4 | **Test connection** with a contact ID → `MoodleGetUserProfile` | Returns the contact; audit success row | ☐ | Exercises the AuthToken |
| B5 | Bad/unreachable WSDL, **never previously reached** (cache empty) | Constructor throws before any call; task fails cleanly; **no** audit row | ☐ | Clear the WSDL cache / use a fresh endpoint to test this |
| B6 | Previously-reached endpoint goes **offline** (WSDL cached on disk) | Constructor uses the cached WSDL; the SOAP call fails inside `call()` → an **error** audit row **is** written | ☐ | `WSDL_CACHE_DISK` — this is correct behaviour, not a failure |

## C. Outbound write / config path — SOAP contract (⚠ CRITICAL, staging iMIS/bridge only)

Validates the **1.6.1 WSDL alignment (#17)** — run only once that version is
installed; on an earlier build these describe code that isn't present yet.
**A single live call per row is enough**, and every call here writes to or
**reconfigures** the iMIS/bridge, so **staging only**.

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| C0 | `SetupBridge` via `setup_bridge()` (not yet in UI) | Returns log lines | ☐ | **Config/setter — can redirect the bridge's Moodle callback; never run against production** |
| C1 | **One live `MoodleUpdate`** for a test contact/course | iMIS activity record created/updated for the **correct contact and product** | ☐ | Proves `userId`/`productId` casing binds; was `UserID`/`productID` |
| C2 | Inspect the `MoodleUpdate` response | `ResultsBase.ResultCode`/`ResultMessage` readable | ☐ | Confirm the success `ResultCode` value with ATS → feeds failure detection |
| C3 | `SendNewOrdersToMoodle` (per-contact) | Succeeds with AuthToken; `OrdersSent`/`NumOrders` returned | ☐ | AuthToken now sent (1.6.1). **Updated-version phase — triggers the bridge's REST callback into Moodle; needs REST + inbound functions, not baseline** |
| C4 | `SendCancelledOrdersToMoodle` (per-contact) | Succeeds with AuthToken | ☐ | **Updated-version phase — same REST callback dependency as C3** |
| C5 | `UpdateMoodleGroups` (per-contact) | Succeeds; `CourseId`/`LastUpdated` accepted; `ResultsBase` returned | ☐ | Casing fixed (1.6.1). **Updated-version phase — same REST callback dependency as C3** |
| C6 | **ProductID = numeric Moodle course id** lands on the right iMIS product | An existing vendor-created record's ProductID equals the numeric course id our plugin would send | ☐ | Numeric course id is the **required** default (the vendor used it); confirm, don't re-decide. Validate only against **our own iMIS** (the ATS sandbox lacks our `MOODLE_PRODUCTS` mappings — see §P topology). **On production this is READ-ONLY** — inspect an existing record (e.g. via a raw SOAP `getActivityByIDAndType`) and compare; **do not write**. The write check waits for a dev-iMIS-wired endpoint |

## D. Observer-driven push (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| D1 | Login, then run cron | Per-user sync tasks run (`SendNewOrders`/`SendCancelled`/`UpdateGroups` per enabled toggles) | ☐ | Observers queue adhoc tasks. **Updated-version phase — these syncs drive the bridge's REST callback into Moodle; needs REST + inbound functions, not baseline.** The baseline login path is SSO only |
| D2 | Course completion (toggle on) + cron | `MoodleUpdate` fires with course product + AuthToken; audit row | ☐ | |
| D3 | Quiz submit (graded, non-preview) + cron | `CreateActivity` writes a `MOODLENOTE` ("Moodle course N score") with score/status; audit row; the course's `MOODLE` completion record is **not** changed | ☐ | Preview/ungraded skipped. Never `MoodleUpdate` (1.16.0) |
| D6 | Module completion with `push_module_completions` on (default off) + cron | `CreateActivity` writes a `MOODLENOTE` (`Note2 = modulecompletion`) for a graded module; ungraded modules skipped | ☐ | Also testable with the *Push activity note (test)* control, which likewise needs the toggle on |
| D7 | Tick *Assignment* (and/or *Case study*) in **Push activity scores**; mark one + cron | `CreateActivity` writes a `MOODLENOTE` "Score: <activity>" (`Note2 = score`) with the raw grade and PASS/FAIL against the pass mark (COMPLETE with none) | ☐ | 1.20.0. Sent when **marked**, even after the learner completed it |
| D8 | Save the same mark again; then change it; then clear it before cron runs | No new note for the unchanged mark; one for the changed mark; clearing cancels the queued note (and re-entering the mark later sends it) | ☐ | A generation per learner + activity: only the latest queued push is sent, so a burst (A, B, A) sends one note |
| D9 | An unticked activity type, a quiz, or a manual gradebook item is graded | No score note from this path | ☐ | Quiz uses its own push (D3); totals and manual items are never sent |
| D4 | User with no iMIS ID | No task queued; no bogus call | ☐ | |
| D5 | Transient SOAP failure on a push | Adhoc task retries (no data loss) | ☐ | Our advantage vs vendor fire-and-forget |

## E. Manual sync admin page (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| E1 | Access requires `moodle/site:config` | Denied without it | ☐ | |
| E2 | Submit shows confirmation before any write | Confirm step appears | ☐ | |
| E3 | Per-user sync | Runs; audit rows | ☐ | **Updated-version phase — the order/enrolment/group sync drives the bridge's REST callback; needs REST + inbound functions, not baseline** |
| E4 | Blank Member ID | Refused with "Member ID required"; **no** call made | ☐ | 1.19.1: a manual sync never runs for all users. *Test connection* still works without a Member ID |
| E5 | Sesskey enforced | Stale/absent sesskey rejected | ☐ | |
| E6 | Single heading (no duplicate title) | One heading renders | ☐ | Fixed in #12 |

## F. Nightly scheduled tasks (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| F1 | Tasks listed under Server → Scheduled tasks | enrolment (02:00) / cancellation (02:15) / group (02:30) / tutorial group (02:45) / cleanup (03:45) present | ☐ | Tutorial group sync is tested in §L |
| F2 | Nightly enrolment/cancellation/group sync run | Complete cleanly (all-user writes!) | ☐ | Keep disabled on prod-backed bridge. **Updated-version phase — these syncs drive the bridge's REST callback into Moodle; needs REST + inbound functions, not baseline** |

## G. Audit log & privacy

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| G1 | `admin/log.php` loads; site-admin only | Denied without `moodle/site:config` | ☐ | |
| G2 | Rows appear after calls; filters + paging work | Correct data, filterable | ☐ | |
| G3 | Capture off (default) | request/response empty | ☐ | |
| G4 | Capture on → redaction | Credential **values** gone (no `MO-…`/session secrets) and `[redacted]` present; the `<AuthToken>`/`<Token>` **tags remain** (redact keeps the XML structure) | ☐ | Tags staying is correct, not a failure |
| G5 | Error row from a fault (capture on) | token redacted (`MO-[redacted]`) | ☐ | |
| G6 | Ambiguous iMIS ID | row stored userid 0 | ☐ | |
| G7 | Privacy registry shows log fields + external iMIS link | Present | ☐ | |
| G8 | Export / delete for a test user | Rows exported / deleted | ☐ | |

## H. Bulk completions/grades pull web service (`get_completions_since`)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| H1 | Enable WS + REST; issue a token to a WS user with the capability | Token works; others rejected | ☐ | Cap at **system context**; service is restricted-users |
| H2 | Call with `since` | Returns changed completions/grades + `nextafterid`/`hasmore` | ☐ | |
| H3 | Paging across >1 page | Stable, no overlap/gaps | ☐ | id cursor |
| H4 | `imisid` resolves per configured source | Matches username/idnumber/email | ☐ | |
| H5 | Deleted/suspended users excluded | Not returned | ☐ | |
| H6 | Missing capability | Rejected | ☐ | |

## I. Cleanup task / retention

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| I1 | `cleanup_log_task` listed (daily) | Present | ☐ | |
| I2 | Run with retention 90 | Old rows pruned, recent kept | ☐ | |
| I3 | Retention 0 | Nothing deleted; "disabled" message | ☐ | |

## J. Security spot-checks

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| J1 | Entry points enforce login → capability, with **sesskey on write/action requests only** | Read-only pages (`admin/log.php`, the initial `admin/sync.php` view) need no sesskey; submitted sync actions require `confirm_sesskey()` | ☐ | Don't add sesskey to navigational GETs |
| J2 | Output escaped (try `<b>` in a value) | Rendered as text | ☐ | |
| J3 | No secret in logs even with capture on | Confirmed | ☐ | |
| J4 | Pull WS is transport-agnostic | If the Moodle SOAP protocol is enabled, `get_completions_since` is reachable over SOAP as well as REST; the capability + restricted-users service still gate it on every protocol | ☐ | Don't assume REST-only — a bulk personal-data export; test each enabled protocol |

## K. Rollback

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| K1 | Clear WSDL stops all SOAP | No SOAP calls made | ☐ | Does **not** stop the tutorial group sync (REST) — see L9 |
| K2 | Uninstall drops the log table | Clean | ☐ | |

## L. Tutorial group sync (REST read of iMIS → Moodle groups)

Reads iMIS only, so it is safe against a production iMIS **for iMIS**; it does
change groups on the Moodle it runs on. Go-live steps and prerequisites are in
`CHANGEOVER-CHECKLIST.md` §T.

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| L1 | Sync disabled (default) | Task logs "disabled … skipping"; no REST call | ☐ | |
| L2 | Enabled, REST base URL blank | Task logs "REST connection not configured; skipping" | ☐ | Needed even with `transport = soap` |
| L3 | Enabled, dry run on (default) | Log names each group it would **create**, then one `would add` / `would remove user N (iMIS …)` line per member, for new **and** existing groups; plus skips, guard trips and the summary totals; **nothing** changes in Moodle | ☐ | Per-member lines since 1.19.7. Removals held back by the guard are not listed individually |
| L4 | Dry run off | Groups created under grouping *Tutorials (iMIS)*; members match the panel | ☐ | |
| L5 | Remove a member in iMIS, re-run | Removed from the Moodle group; still enrolled in the course | ☐ | |
| L6 | Panel row for an unmapped code / unknown iMIS ID / unenrolled user | Row skipped with the matching reason in the log; others proceed | ☐ | Course lookup sends `&Parameter={code}/REG` (1.19.2) |
| L7 | Panel read returns no rows | Run aborts, **no** changes | ☐ | Protects against an outage emptying every group |
| L8 | A run would remove ≥10 members and more than the guard % (default 50) of one group | That group's removals skipped and logged; additions still made | ☐ | Small removals are never blocked |
| L9 | Untick *Enable tutorial group sync* | Next run changes nothing; existing groups stay | ☐ | The rollback for this feature |
| L10 | Hand-made group / hand-added member in a course | Left untouched by the sync | ☐ | It owns only `imis:`-idnumber groups and its own memberships |
| L11 | Audit log after a run | `GET /api/MoodleTutorialGroup` and `GET /api/iqa?…` rows, success; no "value too long" | ☐ | Needs ≥1.19.4. A successful token request is **not** logged; a `token` row appears only when authentication fails |

## M. Cancellation function (`local_imisbridge_cancel_enrolment`)

Called by the ATS bridge in place of `enrol_manual_unenrol_users`
(`CHANGEOVER-CHECKLIST.md` §C/§D). Staging Moodle only.

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| M1 | Call for an enrolled user + course | Every enrolment the user has in the course is **suspended**; grades and completion kept; the enrolment's default role unassigned | ☐ | |
| M2 | Call with an explicit `roleid` | Only that role is unassigned; other roles (e.g. a manual teacher role) stay | ☐ | |
| M3 | Token user lacks `local/imisbridge:cancelenrolment` | Rejected (`nopermissions`) | ☐ | |
| M4 | Function not in the token's service | `accessexception` before the function runs | ☐ | See the §C troubleshooting entry |
| M5 | Hidden course | Works when the service account has `moodle/course:view` + `viewhiddencourses` | ☐ | `validate_context()` still runs `require_login()` |

## N. Sync provider and REST registration sync (1.21.0)

Writes Moodle enrolments on the Moodle it runs on; reads iMIS only. Go-live steps are
in `CHANGEOVER-CHECKLIST.md` §R.

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| N1 | Sync provider blank (default), connection provider SOAP | Syncs behave exactly as before | ☐ | |
| N2 | Connection provider REST, Sync provider ATS SOAP bridge | Pushes go over REST; manual sync buttons, login and nightly syncs use ATS | ☐ | Option A |
| N3 | Sync provider REST, Registrations IQA blank | Sync tasks skip ("no configured sync provider"); buttons hidden | ☐ | No enrolling on upgrade |
| N4 | Sync provider REST, IQA set, dry run on | Report lists would-enrol / reactivate / suspend / create; no changes | ☐ | |
| N5 | Dry run off: active registration | Member enrolled with Student; a suspended enrolment is reactivated | ☐ | |
| N6 | Registrant with no account | Account created (username and ID number = iMIS ID), then enrolled; skipped with a reason when name/email missing | ☐ | |
| N7 | Only cancelled registrations for a course | Enrolment suspended, grades kept; an active registration for the same course prevents it | ☐ | |
| N8 | Member with no rows; event ended before the look-back; IsActiveRegistration false | No change | ☐ | |
| N9 | IQA returns no rows | Run aborts with no changes | ☐ | |
| N10 | Run would suspend 10+ and >50% of a course | That course's suspensions skipped and logged | ☐ | |
| N11 | Manual one-member sync with *Include events outside the look-back window* ticked, for a cancellation on an event that ended before the window | Confirmation mentions the override; report shows `lookbackdays => 0`; enrolment suspended (or would be, in dry run). Unticked, and in the nightly tasks, the row stays out of window | ☐ | Late override |
| N12 | *Course mapping overrides* `ACCD_26=<test course>` on staging | ACCD_26 registrations (and its tutorial groups) resolve to the test course with no `MoodleCourseLookup` call for that code; a mapping to a missing course is "no Moodle course mapped" | ☐ | Staging only |
| N13 | *Push course id overrides* `19=2152` on staging; push a completion from course 19 for a member with an existing iMIS result for 2152 | The push carries course 2152; over REST the member's existing MOODLE row for 2152 is updated in place (same SEQN), not duplicated; notes are appended as MOODLENOTE | ☐ | Staging only |
| N14 | `cli/backfill.php --course=ID --type=all`, then again with `--execute` | Dry run lists each completer (real completion date) and each unsent score of a ticked type, and queues nothing; `--execute` queues them; a second `--execute` queues no scores (already sent). Respects *Push completions*, the ticked types and push course id overrides | ☐ | Back-fill |

---

## Pending decisions (resolve before the relevant tests)

- **ProductID source (C6):** the required default is the **numeric Moodle course
  id** — the vendor sent it and iMIS's existing history is keyed on it
  (`IMPROVEMENT-PLAN.md` §1.1, done in 1.8.0). C6 **confirms** this against an
  existing vendor-created record; it is not a decision to re-open.
- **`MoodleUpdate` success `ResultCode` (C2):** get the success value from ATS and
  enter it in *SOAP success ResultCodes*, so an unexpected `ResultCode` is logged as
  a failure (a populated `Exception` already is, since 1.11.0).

## Not yet built (tests to be added when these land — see IMPROVEMENT-PLAN.md)

- In-plugin create/update/enrol functions (Phase 2) — **superseded** by the core
  functions service (`CHANGEOVER-CHECKLIST.md` §C); build only if parity decision 3
  shows ATS strands existing users.
- Both-direction logging + admin status page + "last heard from" (Phase 3).
- Direct-REST registration pull (`DIRECT-REST-ARCHITECTURE.md` §6).

## Plan revision log

| Date | Change |
|------|--------|
| 2026-10-15 | Initial plan: install/upgrade, config/connectivity, SOAP write-path contract (#17), observers, manual sync, nightly tasks, audit log/privacy, pull WS, cleanup, security, rollback. |
| 2026-10-15 | Review fixes: require write lockdown before a production WSDL (B0); split the bad-WSDL case into uncached (no row) vs cached-outage (error row); move `SetupBridge` to the staging-only §C as a config/setter; make numeric ProductID the required default (confirm, don't re-decide); fix the redaction expectation (values gone, tags remain); scope the sesskey check to write/action requests. |
| 2026-10-19 | Add section P: staging environment prerequisites (PHP soap extension, Moodle cron, web services + REST, outbound HTTPS to the bridge, supported versions) — the silent-failure checks to run before install. |
| 2026-10-19 | Clarify section P: the PHP soap extension (outbound) is distinct from Moodle web services (inbound); leave web services / REST OFF for the baseline and enable only for the updated-version phase (pull WS / provisioning). |
| 2026-10-19 | Record the confirmed live web-service-protocol posture (REST **enabled** for the vendor's inbound create/enrol, Moodle SOAP protocol **disabled**) and reframe §P accordingly: the Phase-1 baseline uses no Moodle web-service protocol, so leave the vendor's REST on and the Moodle SOAP protocol off; name the three distinct SOAP/REST concepts (PHP soap extension vs Moodle REST protocol vs Moodle SOAP protocol); note REST-on is necessary-but-not-sufficient for the callback syncs, which also need our own inbound functions. |
| 2026-10-19 | Codex #21: correct the SOAP framing — Moodle external functions aren't transport-specific, so describe the Moodle SOAP protocol as an unused/unsupported transport, not a nonexistent surface. If it's enabled for another integration, `get_completions_since` (a bulk personal-data export) is reachable over SOAP too; added security caveat in §P and test J4. |
| 2026-10-19 | Add the three-layer topology note to §P (Moodle / ATS middleware / iMIS as distinct sites): the ATS test middleware sandbox is ATS's own, not our dev iMIS, so contract tests run against the sandbox but data-matching (C6) can only be validated against our own iMIS. Added prerequisite P0 and annotated C6. |
| 2026-10-19 | Codex #24: harden the §P topology for safety — keep C6 read-only on production (no throwaway writes); add three write gates (verify the effective SOAP address, use a sandbox-scoped AuthToken + fixtures, and confirm the bridge's Moodle callback targets staging before C3–C5); add a raw-SOAP (Bruno) read-only pre-flight; and genericise the real hostnames to role placeholders (real values live in the internal runbook; this repo is public). |
| 2026-10-22 | Documentation consistency review (1.19.6): D3 corrected to `CreateActivity`/MOODLENOTE (the quiz-score path since 1.16.0); added D6 (module notes); E4 now expects a blank Member ID to be refused (1.19.1); F1 lists all five tasks; new §L (tutorial group sync) and §M (`local_imisbridge_cancel_enrolment`); §P inbound framing updated to the core-functions service; "Not yet built" marks in-plugin provisioning as superseded. |
| 2026-10-22 | 1.19.7: L3 now expects per-member `would add` / `would remove` dry-run lines for new and existing groups. |
| 2026-10-22 | 1.20.0: add D7–D9 for the activity score push (`Push activity scores` checklist, sent on grading, unchanged regrades not re-sent). |
| 2026-10-23 | 1.21.0: add §N for the Sync provider setting and the REST registration sync. |
| 2026-10-23 | 1.22.0: N11 for the one-member look-back override; the default look-back is now 7 days. |
| 2026-10-25 | 1.23.0: N12 for the course mapping overrides. |
| 2026-10-25 | 1.24.0: N13 for the push course id overrides. |
| 2026-10-26 | 1.25.0: N14 for the results back-fill. |
