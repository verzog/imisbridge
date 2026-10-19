# iMIS Bridge — improvement plan

Synthesises the vendor-incident lessons (`DESIGN-NOTES.md`) and the reconstructed
vendor architecture (`VENDOR-ARCHITECTURE.md`) into a prioritised roadmap for
`local_imisbridge`. The goal: match what the vendor bridge does, avoid every
mistake it made, and be fully observable.

Each work item is sized to be **one concern per PR**. Items marked ✅ are already
in the plugin; the rest are proposed, in priority order.

---

## What we already do right (baseline)

- ✅ **Restricted service.** The "iMIS bridge" external service is
  `restrictedusers => 1`, not on the mobile service, and the one function on it
  enforces `validate_parameters → validate_context → require_capability` with the
  capability checked in the function body (DESIGN-NOTES §1, §6).
- ✅ **Reliable outbound push.** Completion/quiz results queue an adhoc task and
  retry with backoff — no fire-and-forget data loss like the vendor.
- ✅ **Outbound audit log.** Every SOAP call is logged with credential redaction,
  UTC Unix timestamps shown via `userdate()`, bounded retention (DESIGN-NOTES §4).
- ✅ **Capability carries `RISK_PERSONAL`.**

---

## Phase 1 — Cutover alignment (do before replacing the vendor)

Nothing below is worth shipping if the data doesn't line up with the 6 months of
records the vendor already wrote to iMIS.

### 1.1 ProductID = numeric Moodle course id — DONE (1.8.0)
- **Why:** the vendor sends the **numeric Moodle course id** as `ProductID`, and
  iMIS maps courses on it (`MOODLE_PRODUCTS.Description` = numeric course id, per
  the authoritative ATS bridge spec). Our outbound previously sent the course
  **idnumber** (fallback shortname), so our activity records would have landed
  under a different ProductID and not matched history.
- **Done:** `observer::course_number()` now returns the numeric Moodle course id
  (`$course->id`). The ATS spec is unambiguous that this is the only correct
  value, so it is sent directly rather than via a configurable source (no footgun
  option to mis-set). If a site ever needs a different mapping, add a
  `productid_source` setting then.
- **Verify:** test C6 — a completion from our plugin writes the same ProductID the
  vendor would have, confirmed against an existing iMIS record.

### 1.2 Confirm the SOAP method name against the live WSDL — STILL OPEN
- **Why:** we call `MoodleUpdate`; the vendor was described as calling
  `ATSMoodleUpdate`. If they are not the same operation, our outbound writes fail.
- **Status:** the authoritative ATS bridge spec lists the operation as
  **`MoodleUpdate`** (section 6), which matches our client — but this must still
  be confirmed against the **live WSDL** for this installation. Removing the
  unrelated dead wrappers in 1.8.0 does **not** close this gate.
- **Do:** verify `__getFunctions()` on the live endpoint (tests C1/C2); align if
  it differs. (Investigation; code change only if they differ.)

---

## Phase 2 — Inbound provisioning (the functional gap that broke for the vendor)

A REST web service, **iMIS → Moodle push**, replacing the vendor's
`create/update/enrol/unenrol` functions — built to avoid every §2/§3 trap.
Likely two PRs: (2a) users, (2b) enrolment.

### 2a. `create_users` / `update_users` / `get_users`
- Match existing users on `idnumber`/`username` (the iMIS ID); **"already exists"
  is success-and-continue**, not an error (DESIGN-NOTES §3).
- `update_users` is the opt-in overwrite path; creation never overwrites an
  existing user unless update is explicitly requested.
- Optional profile fields (e.g. `city`) are `PARAM_TEXT` default `''` — a sparse
  record still creates (DESIGN-NOTES §7).
- New SSO accounts get a **long random password** (auth is SAML2; password is
  never used) — never `password = username = idnumber`.
- Bulk-capable (array of users) so iMIS isn't forced into per-user calls.
- Specific error codes (never `invalidparameter` for "already exists").

### 2b. `enrol_users` / `unenrol_users`
- **Never call `require_login($course)`.** Build the course context and check
  `enrol/manual:enrol` (+ `moodle/role:assign`) **in that context** directly, so a
  least-privilege service role works and **hidden courses enrol by design**
  (DESIGN-NOTES §2 — the root cause).
- **Decoupled from create:** enrol runs independently; a create failure/skip never
  blocks enrol (DESIGN-NOTES §3).
- Role on enrol is configurable (default Student), mirroring `ROLE_ID`.
- Specific errors naming the course and reason (e.g. "course 2172: manual enrol
  instance disabled"), never Moodle's generic `requireloginerror`.
- New capabilities (`local/imisbridge:provisionusers`, `:enrolusers`), granted to
  no role by default, each with the right risk flags; on the restricted service,
  never on mobile; checked in the correct context.

---

## Phase 3 — Observability (so the next outage is a glance, not a multi-hour dig)

### 3.1 Log both directions, successes and failures
- Add a `direction` (inbound/outbound) and `action` column to the audit log;
  route the inbound WS functions (and `get_completions_since`) through the same
  logger. **Log failures especially** (DESIGN-NOTES §4). Requires a small DB
  upgrade (version bump + `upgrade.php`).
- Continue scrubbing secrets; **never accept or log the token in a URL/query
  string** — document that iMIS should send `wstoken` in the POST body.

### 3.2 Admin status page ("is it working?")
- One page showing: the **token's account and last use**, **last successful sync
  per direction**, **last error**, and a **"last heard from"** timestamp for the
  externally-triggered inbound path (DESIGN-NOTES §1, §4, §5, §8).
- Record the inbound "last heard from" on every inbound call so absence is
  detectable (can't tell "broken" from "quiet week" otherwise).

---

## Phase 4 — Operability & docs

- README + an admin settings note documenting **exactly which account holds the
  token** and the **minimal service role** (the precise capability list), so the
  operator never grants `manager` to make it work.
- Document enabling web services + the REST protocol, and the recommended SSO/
  token handling (token in POST body, dedicated non-personal `imisbridge`
  account).
- Note the throttle expectation (`API_DELAY_MILLISECONDS`-style) is unnecessary
  with our bulk/paged calls, but bulk endpoints are provided so iMIS need not loop
  per user.

---

## Sequencing summary

| Phase | Item | Size | Blocks cutover? |
|------|------|------|-----------------|
| 1 | ProductID = numeric course id — **DONE (1.8.0)** | S | **Yes** |
| 1 | Removed non-bridge wrappers (getToken/checkAuthorization/asiEncrypt/asiDecrypt) — **DONE (1.8.0)** | S | No |
| 1 | Confirm live update op name is `MoodleUpdate` (§1.2) — **OPEN** (spec says MoodleUpdate; verify on the live WSDL via C1/C2) | S (investigation) | **Yes** |
| 2a | create/update/get users (REST) | M | Yes (for provisioning) |
| 2b | enrol/unenrol users (REST) | M | Yes (for provisioning) |
| 3.1 | Both-direction logging | S–M | No, but do before 2 ships to prod |
| 3.2 | Admin status page | M | No |
| 4 | Docs & operability | S | No |

Recommended order: **1 → 3.1 → 2a → 2b → 3.2 → 4.** (Logging goes in before the
inbound functions so they are observable from their first call.)
