# iMIS Bridge — Pre-Testing Checklist

Work top-down: each section gates the next. There is no point testing sync
before connectivity is green, or connectivity before the plugin installs.

This plugin **writes to a live iMIS instance** over SOAP. The write operations
(`SendNewOrdersToMoodle`, `SendCancelledOrdersToMoodle`, `UpdateMoodleGroups`,
`MoodleUpdate`, `CreateActivity`) **create and modify records in the iMIS behind
the WSDL**, and
the ATS bridge also pushes enrolments/groups **into whatever Moodle it is
configured to call back** — which may be your *production* Moodle. A disposable
iMIS contact does **not** isolate either side, and repointing only the bridge's
Moodle callback does **not** protect the iMIS data behind the WSDL. So:

- **Read-only** checks (the "Test connection" button — `GetBridgeSettings` and
  `MoodleGetUserProfile`) are safe against the production WSDL.
- **Write** checks (§4 login sync, §5 manual sync, §7 nightly tasks) must run
  against a **genuinely staging iMIS / bridge (a staging WSDL endpoint)** whose
  callback Moodle is also staging — **never the production bridge/WSDL**. If you
  only have the production WSDL, do §3 (read-only) and stop there.
- §7's nightly tasks sync **all users** (`sync_orders(null)` etc.), so they are
  the highest-risk write of all — only run them against a staging iMIS/bridge,
  or keep those tasks disabled and skip §7.

Take a **DB + `moodledata` backup** before you begin.

---

## 0. Before you start

- [ ] Take a **DB + `moodledata` backup** of the target site.
- [ ] Confirm the target is a **staging/test Moodle on Moodle 5.1–5.3 LTS**, not production.
- [ ] For the write tests (§4, §5, §7): confirm you have a **genuinely staging iMIS / bridge (a staging WSDL endpoint)** whose callback Moodle is also staging. Repointing only the Moodle callback is **not** enough — the write ops still mutate the iMIS behind the WSDL. If you only have the production WSDL, do §3 (read-only connection test) and stop there.
- [ ] Have ready: the **WSDL URL**, the **ATS API AuthToken** (`MO-…`), and a **known test member's iMIS ID**.
- [ ] Decide which iMIS records are *safe to touch* (ideally a disposable test contact in iMIS).

## 1. Install / upgrade

- [ ] Fresh install: place the plugin in `local/imisbridge`, run the upgrade — **no errors**; it appears under *Site admin → Plugins → Local plugins*.
- [ ] Upgrade path: install an older version first, then upgrade — no errors, and the plugin version afterwards matches `version.php`. The upgrade steps: `2026101400` creates **`local_imisbridge_log`**; `2026101901` drops stale queued push tasks; `2026102204` widens the log's `operation` column to 255 characters (dropping and re-adding its index — the 1.19.3 attempt without that failed with `ddldependencyerror`).
- [ ] Verify the `local_imisbridge_log` table exists in the database.

## 2. Configuration

- [ ] Settings page loads.
- [ ] **Connection provider = ATS SOAP bridge** (the default) for this checklist.
- [ ] On a **fresh install**, the **WSDL default is blank** (no hard-coded URL). *(On an upgrade this does not apply — see the upgrade check below.)*
- [ ] On an **upgrade** from an install that had a WSDL set, the settings page **retains the existing WSDL endpoint** (configuration is preserved, not reset to blank).
- [ ] Set the **WSDL URL** (a staging iMIS/bridge endpoint for the write tests — see the safety note above).
- [ ] Set the **ATS API AuthToken** (`MO-…`). This is **required** for the completion/quiz pushes and for the contact-lookup part of the connection test — they call `MoodleUpdate` / `CreateActivity` / `MoodleGetUserProfile`, which fail with `noauthtoken` if it is blank. *(Login enrolment/cancellation/group sync does not use the AuthToken.)*
- [ ] Set **iMIS ID source** to match your SAML2 reality (**ID number** for this deployment: SAML `mdlattr = ID number` matches `UNIQUEID` against Moodle `idnumber`, so that is the authoritative field).
- [ ] *(Credit settings: not needed for SOAP — `MoodleUpdate` sends no credit; iMIS derives it from the product. They apply to the direct REST provider only.)*
- [ ] Audit-log settings visible: `log_enabled`, `log_retention_days` (default 90), `capture_payloads` (default **off**), and the **Open log** button.

## 3. Connectivity (read-only — safe against production)

- [ ] Confirm the site can reach the WSDL host (network policy allows it).
- [ ] **Test connection** with **no contact ID** → runs `GetBridgeSettings` (the AuthToken is included when configured) and succeeds, proving reachability.
- [ ] **Test connection** with the **test member's contact ID** → additionally runs `MoodleGetUserProfile` (uses the AuthToken), proving credentials. This should produce a **success** row in the audit log.
- [ ] Force a **genuine SOAP fault** to exercise error logging and redaction:
  1. Turn **`capture_payloads` on** first — otherwise no request/response is stored, and a SOAP fault need not echo the token, so there may be nothing to redact.
  2. With a **reachable** WSDL, set the AuthToken to a present-but-**invalid** value.
  3. Run Test connection with a contact ID → `MoodleGetUserProfile` faults server-side **inside** the logged call → an **error** row appears. With capture on, the stored request (which carried the invalid `MO-…` token) is **redacted** (`MO-[redacted]`).
  4. *(Note: an unreachable/bad WSDL instead throws in the client constructor **before** any call is logged, so it produces no audit row — the request just fails.)*
- [ ] **Restore the valid AuthToken** (and turn `capture_payloads` back off if you want the §6 default-off check clean) **before moving on** — otherwise the invalid token left here makes §4's completion/quiz pushes (`MoodleUpdate` / `CreateActivity`) fail and a healthy observer will look broken. Re-run one read-only Test connection with a contact ID to confirm it succeeds again.

## 4. Observer-driven sync (core — staging iMIS/bridge only)

Observers **queue adhoc tasks**; the SOAP calls happen when **cron** runs, not
at the moment of the event. So after each trigger, **run cron** (or run the
pending adhoc tasks) before checking the audit log.

Before triggering completion/quiz, confirm the (staging) iMIS `MOODLE_PRODUCTS`
table maps the intended safe product code to this course's **numeric Moodle
course id** — the observer sends `course.id` as `productID`, so the record lands
on whatever product that numeric id is mapped to (the course ID number / short
name are not used).

Verify both the iMIS-side effect and an audit-log row for each:

- [ ] **Login** (`user_loggedin`) → queues the per-user sync; after cron, the **enabled** login operations run: `SendNewOrdersToMoodle`, `SendCancelledOrdersToMoodle`, `UpdateMoodleGroups` (whichever login toggles are on). *(Not `MoodleUpdate`.)*
- [ ] **Course completion** (`course_completed`, with `push_completions` on) → after cron, `MoodleUpdate` fires with the course's **numeric id** as `productID` and the AuthToken.
- [ ] **Quiz submit** (`quiz_attempt_submitted`, with `push_quiz_scores` on) → after cron, `CreateActivity` fires, writing a `MOODLENOTE` (Description "Moodle course N score", Note "Score : Moodle course N") with the score/status — **not** `MoodleUpdate`, so the course's completion record is untouched. *(Preview attempts and ungraded quizzes are skipped by design.)*
- [ ] The iMIS ID used matches the configured **source** (not blindly `$user->username` if you changed it).
- [ ] A user **with no iMIS ID** (blank username/idnumber/email) is handled gracefully — no task queued, no bogus call.

## 5. Manual sync (`admin/sync.php` — staging iMIS/bridge only)

- [ ] Page requires `moodle/site:config` (an account lacking it → **denied**).
- [ ] Submitting shows the **confirmation step** (`$OUTPUT->confirm()`) *before* any write.
- [ ] Per-user sync: enter the test member → confirm → the sync operations run → audit rows recorded.
- [ ] Sync with a **blank Member ID** → refused with "Member ID required"; **no** call is made (1.19.1 — all-user syncs are only the nightly tasks). *Test connection* still runs without a Member ID.
- [ ] Sesskey check holds (stale/absent sesskey → rejected).

## 6. Audit log (new in 1.5.0 — test hard)

- [ ] `admin/log.php` loads; an account **lacking `moodle/site:config` → denied**.
- [ ] After the §§3–4 tests (and cron), rows appear: time, user link, operation, iMIS ID, product, status badge, detail.
- [ ] **Filters** work — by operation and by status (success/error).
- [ ] **Pagination** works with >50 rows.
- [ ] **Default (capture off):** request/response are **empty** — only metadata stored.
- [ ] Turn **`capture_payloads` on**, trigger an event + cron → payloads appear in the collapsible detail, **redacted**: no `MO-…` tokens, no `<AuthToken>` / `<Token>` / `<getTokenResult>` values.
- [ ] Error row from the §3 fault test (capture on, reachable WSDL + invalid AuthToken) shows status = error, and its stored request has the token **redacted** (`MO-[redacted]`).
- [ ] Ambiguous iMIS ID (two users share the configured source value) → row stored with **userid 0** (unattributed), not mis-attributed.

## 7. Scheduled tasks

The `cleanup_log_task` below is local-only and always safe. The **nightly sync
tasks are all-user writes** (`sync_orders(null)` / `sync_cancelled_orders(null)`
→ the whole membership) against the iMIS behind the WSDL — the highest-risk
operation in the plugin. **Only run them against a staging iMIS/bridge**; on a
production-backed bridge, keep these tasks disabled and skip that check.

- [ ] `cleanup_log_task` is listed under *Site admin → Server → Scheduled tasks* (daily, ~03:45).
- [ ] Run it manually with retention = 90 → old rows pruned, recent kept (`mtrace` reports the count).
- [ ] Set `log_retention_days` = **0** → task reports "retention disabled; keeping all rows," nothing deleted.
- [ ] **(Staging iMIS/bridge only)** The nightly sync scheduled tasks run clean. On a production-backed bridge, leave them disabled and skip this.
- [ ] The **tutorial group sync** task is listed (nightly, 02:45) and, while *Enable tutorial group sync* is off (the default), a run just logs "disabled … skipping". It only **reads** iMIS (over REST) and changes groups on this Moodle; test it with `CHANGEOVER-CHECKLIST.md` §T.

## 8. Privacy

- [ ] *Site admin → Privacy → Plugin privacy registry* shows `local_imisbridge` with the log-table fields **and** the external iMIS link described.
- [ ] Run an **export** for the test user → audit rows come out.
- [ ] Run a **delete** for the test user → their rows go; others remain.

## 9. Security / permissions spot-checks

- [ ] Entry points (`sync.php`, `log.php`) enforce `require_login` → `require_capability('moodle/site:config')` → sesskey (for writes). Test denial with an account that **lacks** `moodle/site:config` (don't assume "non-admin" — a role granted that capability is *meant* to get in).
- [ ] Output is escaped — try a member value containing `<b>` and confirm it renders as text, not markup.
- [ ] No secrets in the log even with capture on (re-confirm redaction on a real token).

## 10. Rollback plan (have it ready; don't test on production)

- [ ] Know how to disable fast: clear the WSDL (stops all SOAP), or uninstall. Clearing the WSDL does **not** stop the tutorial group sync — untick *Enable tutorial group sync* for that.
- [ ] Confirm uninstall drops the log table cleanly.

---

## Build-specific gotchas

- The wire format (field casing, dates) is the **production-proven** one. If a value looks "wrong" next to the WSDL camelCase, that is expected — do not change it mid-test.
- **Observers queue adhoc tasks**; nothing hits iMIS until cron runs. If an audit row doesn't appear, run cron before concluding anything is broken.
- A **bad WSDL** fails in the client constructor (the adhoc task errors in cron, the user's request is unaffected) — it does **not** create an audit row. To see an *error* row, use a reachable WSDL with a faulting operation.
- Observers fire on **every** login. On a busy staging site that is a lot of queued tasks; enable `capture_payloads` only briefly while debugging, then turn it off.
- `capture_payloads` stores full XML. Leave it **off** except when actively investigating a specific call.
