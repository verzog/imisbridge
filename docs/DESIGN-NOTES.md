# Membership Bridge — design notes from the vendor-bridge incident (14–15 Oct 2026)

Lessons captured while diagnosing a production outage on SCCA's existing vendor
iMIS→Moodle bridge (`local_imis`, built by ATS). Each item is written as a
requirement or caution for our own `local_membershipbridge` plugin. The thread running
through all of it: when the vendor bridge broke, nothing in Moodle could tell us
why, because the parts that mattered weren't logged, weren't owned properly, and
weren't observable.

> Provenance note: these are sanitised internal notes. Individual names and the
> production hostname have been generalised because this repository is intended
> for public release and git history is permanent. Never commit tokens,
> credentials or personal data here.

## 1. Authentication & the service account

What happened: the bridge authenticated as a web service token on a real
person's account (a senior staff member, since departed). The token went missing
(deleted, not expired — Moodle returned `invalidtoken` / "token not found").
Moodle does not log token deletions anywhere queryable, so we could not see when
or why it vanished. Diagnosis took hours because the token's account identity was
never documented.

A further trap: when we moved the bridge to a new, tightly-scoped service
account, enrolment broke (see §2) because the vendor's enrol function relies on
the calling account having manager-level course access. The old account worked
only because it was a manager.

For our plugin:

- Ship with `restrictedusers => 1` in `db/services.php` so the service is
  Authorised users only by default. (The vendor service was open to all users,
  which auto-issued tokens to five ordinary members who opened their
  security-keys page — privileged create/enrol functions exposed to anyone.)
- Document, in the README and ideally in an admin settings page, exactly which
  account the token belongs to. Never leave the operator guessing.
- The service account must be dedicated and non-personal (e.g. `membershipbridge`),
  never a staff member's SSO account. A departing employee must not be able to
  silently break the integration, and the audit trail must not read "created by
  <random person>".
- Expose the token's account and last-use on an admin status page so a
  missing/expired token is visible at a glance, not a multi-hour dig.

## 2. Enrolment must not require `require_login()` on the course ← root cause

What happened: this was the hard one. User creation worked but enrolment failed
for every member with: `requireloginerror: Course or activity not accessible`.
We confirmed the service account had `enrol/manual:enrol`, `moodle/role:assign`,
`moodle/course:viewhiddencourses` and `moodle/category:viewhiddencategories`,
that the enrol function was on the service, that the token authenticated, and
that the target courses accepted manual enrolments (52 historically). Everything
was in place, yet enrolment failed.

The resolution: the historical (working) enrolments were all made by the old
account, which held the `manager` role at system level. The vendor's
`enrol_users` function calls `require_login($course)` internally. For a hidden
course, `require_login()` runs access checks that a full manager passes but a
minimal web-service role does not — even with `viewhiddencourses` granted.
Least-privilege broke the vendor plugin's hidden assumption. Fix for the vendor
bridge: give the service account `manager`.

For our plugin (do it differently):

> **Status (Oct 2026):** cutover uses Moodle's **core** `enrol_manual_enrol_users`
> rather than an in-plugin enrol function (`CHANGEOVER-CHECKLIST.md` §C). Core
> *does* run `require_login()` via `validate_context()`, but unlike the vendor's
> function it passes for a least-privilege account holding `moodle/course:view` +
> `moodle/course:viewhiddencourses` — no `manager` role needed. The requirements
> below still govern any in-plugin enrol function, should one be built.

- The enrol function must NOT call `require_login($course)` on the target
  course. A service enrolling a user does not need to be able to "enter" the
  course the way a student does.
- Instead, build the course context and check `enrol/manual:enrol` (and
  `moodle/role:assign` for the role assignment) in that context, directly.
  Nothing more.
- This means a minimal, least-privilege service role works — the operator does
  not have to grant `manager` to make enrolment function. That is the whole
  point of a well-built web service: tight role, predictable behaviour.
- Enrolling into hidden courses must work by design (SCCA deliberately runs
  hidden courses with members enrolled and waiting). Don't block on visibility;
  check the enrol capability in context and proceed.
- If enrolment genuinely can't proceed, return a specific error naming the
  course and reason (e.g. "course 2172: manual enrol instance disabled"), not
  Moodle's generic `requireloginerror`, which sent us chasing the wrong thing
  for two rounds.

## 3. Create and enrol must be independent (no fatal coupling)

What happened: the vendor bundles create + enrol into one "order". A failed
create aborts the whole order (`OrdersSent=false`, `NumOrders=0`), so enrol never
runs. During the multi-day outage, members kept self-creating via SAML2 SSO login
— so on recovery, almost every member already existed, every create failed as
"already exists", and therefore nobody could be enrolled. The config flag
`ALLOW_UPDATE_USERS=FALSE` guaranteed this: the bridge couldn't update-or-skip an
existing user, it could only error.

For our plugin:

> **Status (Oct 2026):** core `core_user_create_users` **throws** on an existing
> user, so with the core-functions cutover this requirement is not met by default.
> It is tracked as parity decision 3 in `CHANGEOVER-CHECKLIST.md`: test a re-send
> for an existing member, and build an in-plugin wrapper only if ATS strands the
> enrol.

- Treat "user already exists" as success-and-continue, not a fatal error. On
  recovery after any outage, "already exists" is the normal case (SSO
  self-creation), not the exception.
- Decouple create from enrol. A create failure (or skip) must never prevent the
  enrol step from running. Process them as independent operations, or per-user
  rather than per-batch-order, so one bad record doesn't strand the rest.
- Default behaviour on an existing user: match by `idnumber`/`username`, skip
  creation, proceed to enrol. Offer an opt-in "update existing user fields" mode
  (equivalent of `ALLOW_UPDATE_USERS=TRUE`) — but off by default, since it lets
  iMIS overwrite Moodle profile fields.
- Use the correct error code. "User already exists" is not `invalidparameter` —
  the vendor returns `invalidparameter` for it, which is misleading and cost us
  real diagnostic time.

## 4. Logging & observability (this is what cost the most time)

What happened: the vendor's `local_imis_logs` table logged only the outbound
direction (Moodle→iMIS: completions, grades). The inbound direction
(iMIS→Moodle: create/enrol) wasn't logged by the plugin at all — it only appeared
in Moodle's standard log as external web service calls. So when inbound broke,
there was no plugin record to inspect. It also appeared to log successes but not
failures, which is backwards — the failure is the thing you need.

For our plugin:

- Log every call, both directions, success AND failure, in one table. Columns:
  timestamp (UTC), direction, action, affected Moodle user, iMIS contact ID,
  status, and the full error text on failure.
- Log failures especially. The one record we most needed during the incident was
  a failed inbound call, and it didn't exist.
- Timestamps in UTC, stored as Unix timestamps; convert for display with
  `userdate()`. The vendor table mixed UTC (`date_created`) with Brisbane time
  (inside the payload) — a 10-hour trap in the same row.
- Scrub secrets before writing a log row. The vendor payloads carried the live
  `AuthToken` in the logged URL, and their error messages echoed the full token
  in the query string (`wstoken=...`) — so a plain error dump leaks the
  credential. Never put the token in a URL/query string; never log it.
- Admin status page: last successful sync per direction, last error, token
  account + last use. Make "is it working?" a glance, not an investigation.

## 5. Don't depend on an external trigger with no "last heard from"

What happened: the inbound side is driven from outside Moodle (ATS calls in).
When it stopped, Moodle had no way to know it should have run — there was nothing
to compare against. We couldn't even tell "bridge is broken" from "quiet week"
without checking iMIS.

For our plugin:

- Prefer a Moodle scheduled task that the plugin controls for the inbound pull.
  You then get run history, fail delays and the task log for free, and "did it
  run?" is answerable inside Moodle. (Also §8 bulk/throttling applies.)
- If a direction must be externally triggered, record a "last heard from"
  timestamp so absence is detectable and alertable.

## 6. Web service security hygiene (standard, but the vendor missed it)

- Service set to Authorised users only (`restrictedusers => 1`); add only the
  service account. (Vendor's was open to all users → stray member tokens.)
- Don't expose privileged functions on the mobile app service. The vendor's five
  `local_imis_*` functions (including create/enrol) were also on
  `moodle_mobile_app`, widening the surface.
- In each external function, the correct order (house rule §4):
  `validate_parameters()` → `validate_context()` → `require_capability()`,
  importing from `core_external\`. No `required_param()` / `require_sesskey()` in
  web service files.
- Declare each capability in `db/services.php`, enforce it with
  `require_capability()` in the function body (the `'capabilities'` line in
  services.php is advisory/UI-hint only — not enforcement).
- Context matters: enrol and role-assign checks run in the course context, not
  system. Build the course context per target course and check there.

## 7. Data handling

- Don't make Moodle-optional fields mandatory. `city` is optional in Moodle
  core; 596 of SCCA's ~2,000 SAML2 users have a blank city. A sparse member
  record must still create an account. Declare optional params as `PARAM_TEXT`
  with a default of `''`.
- Generate a random password for SSO accounts. The vendor sets
  `password = username = idnumber` (the iMIS ID). Since auth is SAML2 the
  password is never used for login, but a password equal to the username is weak
  by design. Generate a long random one.
- Match existing users on `idnumber`/`username` = the iMIS ID (SCCA's match key).

## 8. Diagnostic discipline (a process lesson, not a code one)

The debugging in this incident took far longer than it should have because it
kept jumping to a cause from each error message before establishing the shape of
the problem. Theories proposed and killed by direct query, in order: empty city,
hidden course, `local/imis:sync` missing, wrong-account permissions, missing
enrol function — all wrong. What actually worked every time was checking the real
state (is the user created? is he enrolled? who was the modifier on the
historical enrolments?) rather than trusting the error text.

Design implication: our plugin's errors and logs should make the real state
obvious, so whoever debugs it doesn't have to reverse-engineer it from a generic
`invalidparameter` through an ad-hoc SQL tool. Specific error codes, full error
text in the log, and a status page that shows what actually happened.

## Appendix — confirmed facts about the SCCA setup (reference)

- Vendor plugin: `local_imis` (ATS). Functions: `local_imis_get_users`,
  `_create_users`, `_update_users`, `_enrol_users`, `_unenrol_users`.
- Bridge authenticates over REST to the SCCA Moodle REST endpoint
  (`/webservice/rest/server.php`).
- Token stored in iMIS → Settings → General Lookup Tables → `MOODLE_SETTINGS` →
  `WEBSERVICE_TOKEN`. Same table: `REST_ENDPOINT`, `ROLE_ID=5` (role assigned on
  enrol), `AUTH_MODE=saml2`, `ALLOW_UPDATE_USERS=FALSE`,
  `DEFAULT_TWO_DIGIT_COUNTRY=AU`, `SSO_URL_PARAM=wstoken`.
- Working bridge account = a system-level `manager` staff account. This is the
  account that made all historical create/enrol calls.
- The outbound direction (Moodle→iMIS completions/grades) logs to
  `local_imis_logs`; inbound does not log there.
- ATS support contacts: on file with SCCA operations.
