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

> **Two different "SOAP/REST" things — don't conflate them.** The **PHP `soap`
> extension** (P1) powers the plugin's *outbound* calls (`\SoapClient` → iMIS)
> and is always required. **Moodle's web services (the REST/SOAP server, P3)** is
> for *inbound* calls **to** Moodle and is used by the pull WS
> (`get_completions_since`), future inbound provisioning, **and the ATS bridge's
> own callback into Moodle**. **For the baseline (outbound completions + SSO),
> leave Moodle web services / REST OFF** — nothing in the baseline uses them, and
> off = smaller attack surface. Turn them on only when you reach the
> updated-version phase (§H and later provisioning).
>
> **Why the sync operations are *not* baseline:** `SendNewOrdersToMoodle`,
> `SendCancelledOrdersToMoodle` and `UpdateMoodleGroups` are outbound SOAP calls
> from us, but their documented effect is to make the **ATS bridge call *back*
> into Moodle** (create/enrol/group changes) over the Moodle REST callback. With
> REST OFF the outbound SOAP call can still succeed while the Moodle-side change
> silently fails — and our plugin does not yet ship the inbound functions that
> callback targets. So every order/enrolment/cancellation/group **sync** row
> below (C3–C5, D1, E3/E4, F2) is an **updated-version-phase** test that needs
> REST enabled *and* the inbound provisioning functions — **not** part of the
> REST-off baseline. The baseline is outbound completions (`MoodleUpdate`, D2/D3)
> plus SSO only.

| ID | Check | Expected | Status | Notes |
|----|-------|----------|--------|-------|
| P1 | **PHP `soap` extension enabled** (baseline) | `php -m` lists `soap` (or under *Site admin → Server → PHP info*) | ☐ | The entire *outbound* path uses `\SoapClient`; without it every iMIS call throws at construction. Distinct from Moodle's web-service protocols. **#1 thing to check on a packaged Moodle.** |
| P2 | **Moodle cron runs every minute** (baseline) | Recent runs under *Site admin → Server → Tasks → Task processing* | ☐ | Completion/quiz/login sync are queued adhoc tasks — no cron, nothing reaches iMIS |
| P3 | **Web services + REST protocol** — **leave OFF for the baseline** | Enable only for §H / provisioning: *Advanced features → Enable web services*; REST on under *Server → Web services → Manage protocols* | ☐ | Not used by outbound completions or SSO; enabling it is an updated-version step, not a baseline one |
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
| C6 | **ProductID = numeric Moodle course id** lands on the right iMIS product | Record matches an existing vendor-created record's ProductID | ☐ | Numeric course id is the **required** default (the vendor used it); confirm, don't re-decide |

## D. Observer-driven push (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| D1 | Login, then run cron | Per-user sync tasks run (`SendNewOrders`/`SendCancelled`/`UpdateGroups` per enabled toggles) | ☐ | Observers queue adhoc tasks. **Updated-version phase — these syncs drive the bridge's REST callback into Moodle; needs REST + inbound functions, not baseline.** The baseline login path is SSO only |
| D2 | Course completion (toggle on) + cron | `MoodleUpdate` fires with course product + AuthToken; audit row | ☐ | |
| D3 | Quiz submit (graded, non-preview) + cron | `MoodleUpdate` fires with score/status; audit row | ☐ | Preview/ungraded skipped |
| D4 | User with no iMIS ID | No task queued; no bogus call | ☐ | |
| D5 | Transient SOAP failure on a push | Adhoc task retries (no data loss) | ☐ | Our advantage vs vendor fire-and-forget |

## E. Manual sync admin page (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| E1 | Access requires `moodle/site:config` | Denied without it | ☐ | |
| E2 | Submit shows confirmation before any write | Confirm step appears | ☐ | |
| E3 | Per-user sync | Runs; audit rows | ☐ | **Updated-version phase — the order/enrolment/group sync drives the bridge's REST callback; needs REST + inbound functions, not baseline** |
| E4 | All-users (blank contact ID) | All-users warning on confirm | ☐ | **Updated-version phase — same REST callback dependency as E3** |
| E5 | Sesskey enforced | Stale/absent sesskey rejected | ☐ | |
| E6 | Single heading (no duplicate title) | One heading renders | ☐ | Fixed in #12 |

## F. Nightly scheduled tasks (staging iMIS only)

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| F1 | Tasks listed under Server → Scheduled tasks | enrolment/cancellation/group + cleanup present | ☐ | |
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

## K. Rollback

| ID | Test | Expected | Status | Notes |
|----|------|----------|--------|-------|
| K1 | Clear WSDL stops all SOAP | No calls made | ☐ | |
| K2 | Uninstall drops the log table | Clean | ☐ | |

---

## Pending decisions (resolve before the relevant tests)

- **ProductID source (C6):** the required default is the **numeric Moodle course
  id** — the vendor sent it and iMIS's existing history is keyed on it, so the
  current idnumber-based value would land records under a different product
  (`IMPROVEMENT-PLAN.md` §1.1). C6 **confirms** this against an existing
  vendor-created record; it is not a numeric-vs-idnumber decision to re-open.
- **`MoodleUpdate` success `ResultCode` (C2):** get the success value from ATS so
  we can wire `ResultCode`/`Exception`-based failure detection and logging.

## Not yet built (tests to be added when these land — see IMPROVEMENT-PLAN.md)

- Inbound create/update/enrol/unenrol web service (Phase 2).
- Both-direction logging + admin status page + "last heard from" (Phase 3).

## Plan revision log

| Date | Change |
|------|--------|
| 2026-10-15 | Initial plan: install/upgrade, config/connectivity, SOAP write-path contract (#17), observers, manual sync, nightly tasks, audit log/privacy, pull WS, cleanup, security, rollback. |
| 2026-10-15 | Review fixes: require write lockdown before a production WSDL (B0); split the bad-WSDL case into uncached (no row) vs cached-outage (error row); move `SetupBridge` to the staging-only §C as a config/setter; make numeric ProductID the required default (confirm, don't re-decide); fix the redaction expectation (values gone, tags remain); scope the sesskey check to write/action requests. |
| 2026-10-19 | Add section P: staging environment prerequisites (PHP soap extension, Moodle cron, web services + REST, outbound HTTPS to the bridge, supported versions) — the silent-failure checks to run before install. |
| 2026-10-19 | Clarify section P: the PHP soap extension (outbound) is distinct from Moodle web services (inbound); leave web services / REST OFF for the baseline and enable only for the updated-version phase (pull WS / provisioning). |
