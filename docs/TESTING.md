# Testing iMIS Bridge

This guide covers configuring and testing `local_imisbridge` against an iMIS
environment, including how to test as safely as possible against a **production**
iMIS when no staging iMIS is available.

## Requirements

- Moodle 5.1 to 5.3 LTS, PHP 8.2 or later (PHP 8.3+ on Moodle 5.2+).
- The PHP **SOAP extension** (`ext-soap`) enabled — the client instantiates
  `\SoapClient`; without it every path fails with a missing-class error.
- Network access from the Moodle server to the ATS bridge WSDL endpoint.
- An ATS-issued API AuthToken (format `MO-xxxxxx`) for the secured methods.
- For the tutorial group sync or the direct REST provider: network access to the
  iMIS site and a dedicated iMIS REST service account. Its go-live checks are in
  `CHANGEOVER-CHECKLIST.md` §T.
- **iMIS contact ID source.** Every event-driven operation — login sync,
  course-completion push, and quiz-score push — sends a Moodle user field directly
  as the iMIS contact ID (`userId` for `MoodleUpdate`, `ContactID` for
  `CreateActivity`). The field is configurable
  (_iMIS contact ID source_: username, ID number, or email; default **username**)
  and is normally populated by SAML2. For manual testing, create Moodle test
  users whose configured field holds valid iMIS contact IDs. A value that
  coincidentally matches an unrelated iMIS ID will update the **wrong** contact.

## 1. Lock the plugin down before connecting it

Do this **first**, before entering any endpoint or token. The WSDL setting is
blank on a fresh install, but every write toggle except the module-completion push
defaults to **enabled** (an unset toggle is treated as enabled too), and the
enrolment/cancellation/group syncs do not use the AuthToken — so the moment a
production WSDL is saved, a nightly cron run can trigger an **all-user production**
sync and a single user login triggers a **production** sync scoped to that one
user. On an **upgrade**, the existing WSDL is kept, so the same applies immediately.

1. On the test Moodle, **pause Moodle cron** and avoid user logins during setup.
2. Install the plugin (`git clone` into `local/imisbridge`, then _Site
   administration > Notifications_).
3. Immediately go to _Site administration > Plugins > Local plugins > iMIS
   Bridge_ and **disable every automatic toggle** (the three login toggles, the
   completion and quiz push toggles, and the three scheduled-task toggles) — see §5.
   Leave the module-completion push and the tutorial group sync **off** (their
   default) unless you are testing them.

Only once writes are disabled should you configure the endpoint (§2).

## 2. Configure the plugin

Under _Site administration > Plugins > Local plugins > iMIS Bridge_:

| Setting | Value |
| --- | --- |
| Connection provider | **ATS SOAP bridge** (default) for everything in this guide. *Direct iMIS REST* is a separate path (`DIRECT-REST-ARCHITECTURE.md`). |
| iMIS WSDL URL | The ATS `wsmoodle.asmx?WSDL` endpoint (production is the `<ats-prod>` host; ask ATS for the staging URL, which is a **different ATS host** — and note the ATS host is not the iMIS/AMS site). Real hostnames live in the internal runbook. |
| Service endpoint URL (override) | Optional. When set, requests go to this `.asmx` URL (no `?WSDL`) instead of the address embedded in the WSDL. **Set it to your staging endpoint before any write test** if the WSDL's embedded `soap:address` points at a different environment (see the trap below). Blank = use the WSDL's address. |
| ATS API AuthToken | The `MO-xxxxxx` token issued by ATS. This is the credential for every secured call. |
| Web service timeout | Seconds to wait on iMIS before giving up (default 30). |
| Default credit type | Direct REST provider only (default `CEU`). The SOAP `MoodleUpdate` sends the fixed `type=Completion` and no credit — iMIS derives the CEU from the product. |
| Credit value course field | Direct REST provider only: short name of the course custom field holding the credit value. Ignored by SOAP. |
| iMIS REST connection (base URL, username, password) | Leave blank for SOAP testing. Only needed for the REST provider or the tutorial group sync — which uses these settings **whatever the provider** (`CHANGEOVER-CHECKLIST.md` §T). |

Keep the WSDL URL and AuthToken from the **same iMIS environment** together —
never pair a staging token with a production WSDL, or vice versa. Testing against
production iMIS from an isolated Moodle (§5) is expected to use production
credentials; the isolation comes from the separate Moodle instance and the
disabled write toggles, not from mixing environments.

> **Trap: the WSDL decides where calls go, not the URL you typed.** PHP's
> `SoapClient` sends requests to the `<soap:address location="…">` embedded in
> the WSDL's `<service>` block — **not** to the host you fetched the WSDL from. A
> staging WSDL (the `<ats-sandbox>` host) can embed the
> **production** address (`<ats-prod>`), so pointing the WSDL URL at
> staging would still send every call — including writes — to **production**.
> Before any write test: open the WSDL in a browser and check its
> `<soap:address location>`; if it doesn't match the environment you intend, set
> the **Service endpoint URL (override)** to the correct `.asmx` endpoint. The
> connection test prints which endpoint requests will go to, so confirm it there.

**ProductID = numeric Moodle course id.** The completion and quiz pushes send the
course's **numeric Moodle course id** (the `id` in `course/view.php?id=…`) as the
iMIS `productID`; iMIS maps it to the iMIS product code via the `MOODLE_PRODUCTS`
lookup table (Code = iMIS product code, Description = numeric course id). The
course **ID number / short name are not used**. For a completion/quiz test to
target the right iMIS product, make sure the (staging) iMIS `MOODLE_PRODUCTS`
table maps the intended product code to this course's numeric id.

## 3. Verify connectivity (read-only, safe anywhere)

The connection test calls only non-mutating operations, so it is safe to run
against production as a first check.

```bash
# Connectivity + WSDL only (does not exercise the AuthToken):
php local/imisbridge/cli/test_connection.php

# Also verify credentials via an authenticated MoodleGetUserProfile call:
php local/imisbridge/cli/test_connection.php --imisid=12345
```

Admin page: _Open sync admin_ from the plugin settings, then use the **Test
connection** button. Enter a contact ID first to exercise the AuthToken as well.

Note: `GetBridgeSettings` is unauthenticated. Without a contact ID the test
confirms connectivity and the WSDL only — it does **not** prove the AuthToken is
valid. Supply an iMIS contact ID to run an authenticated call.

**Tip:** the **audit log** (_iMIS Bridge_ settings → _Open iMIS audit log_) records
every call made to iMIS, so after any test you can see the operation, status and
any fault. To inspect the exact SOAP request/response XML (for example to check
the field casing and date format), turn on _Capture full request/response_ while
testing, then turn it off again — it stores personal data, and credentials are
redacted either way.

## 4. Understand what writes, and where

| Operation | Trigger | Sends AuthToken? | Effect |
| --- | --- | --- | --- |
| `GetBridgeSettings`, `MoodleGetUserProfile`, `getActivityByIDAndType`, `getIQARows` | Connection test / API | Read: some yes | Read only |
| Completion push (`MoodleUpdate`) | Course completion | Yes | Writes status, score and the start/completion/grant dates to the canonical `MOODLE` activity record (credit is derived by iMIS from the product) |
| Quiz score push (`CreateActivity`) | Graded quiz submission | Yes | Creates an append-only `MOODLENOTE` record ("Moodle course N score", `Note2 = score`). Never `MoodleUpdate`, so a re-attempt cannot overwrite the course's completion record (since 1.16.0) |
| Activity score note (`CreateActivity`) | An activity of a type ticked in _Push activity scores_ is **graded** (none ticked by default; quiz excluded — it has its own push) | Yes | Creates an append-only `MOODLENOTE` ("Score: <activity>", `Note2 = score`) with the raw grade; an unchanged regrade is not sent again (1.20.0) |
| Module note (`CreateActivity`) | Module completion, or the _Push activity note (test)_ control — **both** require _Push module completions_ on (default off; graded modules only) | Yes | Creates an append-only `MOODLENOTE` record (`Note2 = modulecompletion`) |
| Enrolment sync (`SendNewOrdersToMoodle`) | Login, nightly task, manual | No | Creates iMIS activity records **and** pushes an enrol notice to the bridge's configured Moodle |
| Cancellation sync (`SendCancelledOrdersToMoodle`) | Login, nightly task, manual | No | Marks iMIS activity records cancelled **and** pushes an unenrol notice to the bridge's configured Moodle |
| Group sync (`UpdateMoodleGroups`) | Login, nightly task, manual | No | Pushes group changes to the bridge's configured Moodle. Does **not** write iMIS records |
| Tutorial group sync (REST `GET`s only) | Nightly task, only when enabled (default off) | n/a — REST service account | **Reads** iMIS only; writes Moodle course groups on **this** Moodle. Never writes to iMIS |

Writes fire automatically from four sources: the **login observer** (any user
login), **course-completion** events, **graded-quiz submissions**, and the
**nightly scheduled tasks**. The nightly enrolment/cancellation tasks run for
**all users** — never let them run against production. The manual sync page runs
for **one member only**: since 1.19.1 a blank Member ID is refused ("Member ID
required") rather than meaning all users.

**Direction matters.** The completion, quiz-score and module-note pushes write
iMIS activity records directly (these carry the AuthToken). The order and group operations
instead tell the ATS bridge to act: they send a notice back to **whichever Moodle
the bridge is configured to call back** (via `SetupBridge` / `GetBridgeSettings`)
— not necessarily the Moodle that made the request — and the two order operations
additionally create/modify iMIS activity records. So calling these against a
production bridge can enrol, unenrol or re-group users on the **production**
Moodle even when you triggered them from a test site.

## 5. Write controls (kill-switches)

Under _iMIS Bridge_ settings, every automatic write has an enable/disable
checkbox:

- **Automatic sync controls:** sync enrolments / cancellations / groups on
  login; push course completions; push quiz scores (all default **on**); push
  module completions (default **off**); push activity scores — a checklist of
  activity types (none ticked by default).
- **Scheduled tasks:** the nightly enrolment / cancellation / group sync tasks
  (default **on**).
- **Tutorial group sync:** its own enable switch (default **off**) plus a dry-run
  switch (default **on**).

The login toggles and the scheduled-task toggles are **independent**: disabling
_Sync enrolments on login_ does **not** disable the nightly enrolment task, and
vice versa. To stop an operation entirely, disable **both** its login toggle and
its task toggle. The completion and quiz push toggles each gate their operation
everywhere it runs (the observer and the queued adhoc task). The manual sync
buttons are deliberate admin actions and are **not** gated by any toggle, but
each prompts for confirmation before it runs and requires a Member ID — a manual
sync never runs for all users.

## 6. Testing against a production iMIS

Follow the lock-down in §1 first. Without a staging iMIS, only the **read-only
connection test is safe** against production. Every sync and push either writes
to production iMIS or drives the production bridge's callback — none is made safe
merely by running it from a test Moodle.

1. Use a **fresh, isolated test Moodle** — never your live Moodle — with only
   your own test account, cron paused, and all write toggles disabled (§1).
2. Run the **connection test** (§3). This is read-only and safe; fix
   connectivity and credentials here.
3. **Do not run a sync or push against a production bridge as a "safe" test.**
   None of them are isolated by your Moodle instance:
   - Completion pushes (`MoodleUpdate`) and quiz-score / module-note pushes
     (`CreateActivity`) write directly to production iMIS activity records (these
     carry the AuthToken).
   - Enrolment/cancellation syncs write iMIS activity records **and** push
     enrol/unenrol notices to the bridge's configured (production) Moodle.
   - Group sync pushes group changes to the bridge's configured (production)
     Moodle (no iMIS record write, but it still modifies that Moodle).
4. Choose the confinement that actually applies to what you want to test —
   these are not interchangeable:
   - **Completion/quiz pushes** are token-bearing, so a **scoped test AuthToken
     / test product** confines them to a throwaway iMIS contact and product.
     You can trigger the push through the event itself (have your test user
     complete the test course or submit the graded quiz) or with the **Push
     completion (test)** control on the manual sync page, which sends one
     completion for a Member ID and course on demand; both honour the _Push
     course completions_ setting, and both are token-bearing. The manual control
     writes to whatever Member ID you enter, so use a throwaway/dev contact. The
     push sends the user's **configured iMIS ID field** (_iMIS contact ID source_:
     username, ID number, or email), so set **that field** — not necessarily the
     username — on the test user to the throwaway iMIS contact ID, and confirm it
     before triggering; otherwise the push can hit the user's real production
     contact. Verify in iMIS, and delete the test records afterwards.
   - **Enrolment / cancellation / group syncs carry no AuthToken**, so a scoped
     token does **not** constrain them. The only safe way to exercise these is a
     **staging or repointed bridge configured to call back your test Moodle**.
     Confirm the bridge's callback target with ATS before running any of them.
5. Whatever you run, use a throwaway contact ID for manual syncs (the page refuses
   a blank one) and know your rollback (how to delete test activity records in
   iMIS) before you start.

## 7. Scheduled tasks

The plugin's scheduled tasks run via Moodle cron, at these default times (in the
server timezone — _Site administration > General > Location settings > Default
timezone_; each is editable under _Site administration > Server > Scheduled
tasks_, which shows the actual next run):

- 02:00 — enrolment sync (all users)
- 02:15 — cancellation sync (all users)
- 02:30 — group sync (incremental)
- 02:45 — tutorial group sync (only when enabled; reads iMIS over REST)
- 03:45 — audit-log cleanup (local only, always safe)

The group sync only requests changes since its own last successful run, tracked
independently of Moodle's task scheduler so a disabled interval does not skip
changes. The three sync tasks can be disabled from the _iMIS Bridge_ settings (§5)
or from _Scheduled tasks_. View run output under _Site administration > Server >
Task logs_.

## Scope

The bridge writes learning records (completions, scores, credit) back to iMIS as
**activity records**. Creating iMIS orders or **event registrations** from a
Moodle-side purchase is not implemented and is not planned; the ATS
`wsmoodle.asmx` service exposes no order/registration write operation.
