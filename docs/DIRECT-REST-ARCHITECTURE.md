# Direct iMIS REST integration — architecture (strategic target)

Design reference for talking to iMIS **directly through its own REST API**, with
no ATS `wsmoodle.asmx` middleware in the path. This is the strategic end state
for `local_imisbridge`: it removes the annual ATS subscription, takes a third
party out of the critical path, and ends the WSDL/endpoint/sandbox ambiguity that
the SOAP transport forces on us.

It is written to be built **inside the existing plugin** as a second, selectable
transport — not a rewrite — so the proven SOAP path keeps working as a fallback
while REST is validated.

> Provenance: synthesises three sources — (1) hand-verified REST notes taken
> against the **dev iMIS** instance (`<dev-imis>`) with a Bruno collection;
> (2) the reconstructed vendor bridge (`VENDOR-ARCHITECTURE.md`); (3) a file-level
> read of the vendor `local_imis` plugin. Points proven on `<dev-imis>` are marked
> **[verified]**; points inferred or carried from the SOAP contract are marked
> **[assumed]** and must be confirmed before go-live. Real hostnames, IQA paths
> and credentials live in the internal runbook, never in this repo.

---

## 1. Why direct REST

The current path is Moodle → **ATS middleware** (`wsmoodle.asmx`) → iMIS. iMIS
exposes its own REST API, and everything the ATS bridge does on the iMIS side —
reading registrations, writing activity/completion records — can be done directly
against that API. Doing so:

- removes the ATS annual subscription and a vendor that is moving away from this
  product;
- collapses three moving hosts (Moodle / ATS-prod / ATS-sandbox) down to two
  (Moodle / iMIS), eliminating the "which `.asmx` writes to which iMIS" problem
  and the WSDL-embedded-`soap:address` trap entirely;
- was proven end to end on `<dev-imis>`: authenticate → read a contact → run an
  IQA query → read activity records → create an activity → delete it, with no ATS
  in the loop. **[verified]**

---

## 2. Topology (direct — no middleware)

```
  Moodle (local_imisbridge)                 iMIS (ATS cloud AMS)
  ─────────────────────────                 ────────────────────
  OUTBOUND  writeback                       REST API
  completion / grade event  ── HTTPS ─────► POST/PUT /api/Activity
                               Bearer tok    (MOODLE activity record)

  INBOUND   pull (scheduled task)
  cron reads registrations  ── HTTPS ─────► GET  /api/IQA?QueryName=...
                               Bearer tok    GET  /api/Party/{id}
       │                                     GET  /api/Activity?PartyId=...
       ▼ enrol/unenrol LOCALLY
  Moodle internal enrol API
  (no inbound call into Moodle)
```

The single biggest shape change from the vendor model is **direction of the
inbound path**. The vendor had iMIS/ATS *push into* Moodle's REST web service
(`local_imis_create_users` / `enrol_users`, token in the URL). Direct REST
inverts that: Moodle **pulls** registrations from iMIS on a schedule and enrols
locally. Nothing calls into Moodle, so the inbound web service, its token, and the
`require_login`-on-hidden-courses enrol gotcha (`VENDOR-ARCHITECTURE.md`,
"Why manager matters") all disappear.

---

## 3. Identity model — the iMIS contact ID is the Moodle **ID number**

This is the load-bearing fact for every read and write, so it is stated first.

- iMIS "PartyId" means the contact's iMIS ID. It is the same value as `ShipToId` on
  a registration and `ID` on an activity. **[verified on `<dev-imis>`]**
- The **authoritative Moodle field is `idnumber`.** The live Moodle SAML2 config
  matches on it: `auth_saml2 | idpattr = UNIQUEID` is matched to
  `auth_saml2 | mdlattr = ID number`. So on every login the IdP's `UNIQUEID` (the
  iMIS ID) is matched against the Moodle user's **ID number** — which means
  `idnumber` must hold the iMIS ID for login to work at all, and is therefore the
  field guaranteed to carry it.
- **Case-insensitive.** `auth_saml2 | tolower = Case and accent insensitive`, so the
  match ignores case (and accents). Do not rely on exact case when correlating; and
  note iMIS may apply its own matching on the value we send as `PartyId`.
- On this installation provisioning populates **both `idnumber` and `username`** with
  the iMIS ID, so either field yields a correct value — but `idnumber` is the one
  SAML depends on, so it is the robust key. (This corrects an earlier draft that
  read the SAML settings-page *defaults* — `idpattr = uid`, `mdlattr = Username` —
  rather than the configured values; the vendor `local_imis` plugin was right to key
  on `$user->idnumber`.)

**Plugin setting.** Set `imis_id_source` = **ID number** for this deployment (the
plugin default is `username`, which also works here only because username is
populated too — but `idnumber` is authoritative). The REST transport reads the same
setting.

### Provisioning consequence
`auth_saml2 | autocreate` and `allowcreate` are both **No**, so SAML does not
create missing users on login — the vendor's inbound push did that. The inbound
pull task (§6) therefore has to **create the Moodle account itself** when a
registrant has no user yet, then enrol. Account creation is part of the pull, not
a precondition of it.

---

## 4. Authentication

- **Base URL:** the iMIS site **root**, e.g. `https://<dev-imis>` /
  `https://<prod-imis>` — no `/api` suffix; the client appends `/token` and
  `/api/...` itself. **[verified]**
- **Token:** `POST /token`, `application/x-www-form-urlencoded`, body
  `grant_type=password&username=<svc>&password=<secret>`. Returns `access_token`
  and `expires_in` (seconds). **[verified]**
- **Use:** `Authorization: Bearer <access_token>` on every call.
- **Refresh:** tokens are short-lived (~1 hour). A `401` with
  `"Authorization has been denied for this request."` means it expired — fetch a
  new token and retry the call **once**. Refresh on 401, or proactively just
  before `expires_in`. **[verified]**
- iMIS login type here is plain username/password (no external IdP on the API), so
  the password grant works directly. **[verified]**

### Service account (hard requirement)
Run the bridge under a **dedicated iMIS user** with `RemoteService` plus only the
roles it needs (read registrations, read/write activities) — **never** a person's
login or a shared SysAdmin account. On `<dev-imis>` every API account observed
carried `RemoteService` + `SysAdmin`, which is full unrestricted write and makes
Change-History attribution impossible. Confirm the **licence position** first —
each iMIS API user is a paid licence. Store the credential in Moodle as a masked
config value; never commit it.

---

## 5. Outbound — completion / grade writeback

The main write: record a Moodle completion as an iMIS **activity**. This replaces
the SOAP `MoodleUpdate` operation.

### 5.1 Two activity types — do not conflate them
The vendor wrote **two** different kinds of record, via two different SOAP ops:

| Record | Vendor SOAP op | Meaning | Key columns |
|---|---|---|---|
| **`MOODLE`** | `MoodleUpdate` | Canonical completion/enrolment state | `UF_1`=course id, `UF_3`=status, `UF_4`=grade |
| **`MOODLENOTE`** | `createActivity` | Supplementary audit notes (module completions, grades, enrol/login trail) | `PRODUCT_CODE`=Moodle course number (`UF_1` empty), `DESCRIPTION`=activity name, `NOTE`=`Module completed : <activity>` / `Score : <activity>`, `NOTE_2`=category (`modulecompletion`/`score`), `SOURCE_CODE`=module type, `UF_3`=status, `UF_4`=score, `UF_5`=cmid, `THRU_DATE`=date — confirmed against a vendor-written row (1.26.1) |

The hand-verified REST notes document the **`MOODLE`** record (§5.3). The
`MOODLENOTE` trail is optional and secondary — implement the `MOODLE` record first;
it is the one iMIS reporting keys on.

**Which observer event writes which record — and the invariant.** The vendor did
*not* route every event to the canonical row, and neither should the REST
transport. Only **course completion** owns the `MOODLE` row; per-attempt and
per-module events write `MOODLENOTE` so they can never overwrite the course-level
status or final grade:

| Observer event | Record | Keyed by |
|---|---|---|
| `course_completed` | **`MOODLE`** (upsert, §5.6) | `PartyId` + course (`UF_1`) |
| `user_graded` / graded-quiz submission | `MOODLENOTE` (insert) | own `SEQN` |
| `course_module_completion_updated` | `MOODLENOTE` (insert) | own `SEQN` |

**Invariant: nothing but a course-completion event may update the `MOODLE` row.** A
quiz re-attempt or a late grade after completion must not flip the canonical
`UF_3=COMPLETE` back to `PASS`/`INPROGRESS` or replace the final course grade with a
module score. This is exactly why the vendor sent quiz/module results as
`MOODLENOTE` (`createActivity`) and only `course_completed` as `MoodleUpdate`.
`MOODLENOTE` rows are append-only (one per event); they are never located-and-updated,
so the upsert race (§5.6) does not apply to them.

### 5.2 Course → product-code mapping is done **client-side**
The vendor Moodle plugin never touched `MOODLE_PRODUCTS` — it sent the **numeric
Moodle course id** as `productID` and let iMIS/ATS map it. With no middleware, the
bridge must do that lookup itself:

- Read the `MOODLE_PRODUCTS` general lookup table (via IQA or the lookup API).
- **Code** = product/registration-option key (e.g. `ACCD_26/REG`); split on `/`.
- **Description** = the Moodle **course id**.
- **SubstituteValue** is unreliable (blank/irregular on some rows) — do not key on
  it.
- De-duplicate on course id: `.../REG` and `.../REGNZ` can point at the same
  course.
- **Reading it by IQA — proven by the tutorial group sync (1.19.2).** The
  `MoodleCourseLookup` IQA has a **required `Code` prompt** matching the **full**
  code: `Parameter=ATS_BRIDGE/REG` returns `Description = 7`, the bare
  `ATS_BRIDGE` returns nothing. So look codes up one at a time as `{code}/REG`, and
  require the returned `Code` to match exactly (guarding against a loosely filtered
  IQA returning a neighbouring `.../REGNZ` row). **[verified]**

On the activity record, `UF_1` still carries the raw numeric course id (matching
`observer::course_number()`, which returns `$course->id`); `PRODUCT_CODE` carries
the resolved iMIS product code.

### 5.3 The create body
`POST /api/Activity`, `Content-Type: application/json`, Bearer token. Reliable
approach: **read an existing `MOODLE` activity** (`GET /api/Activity?PartyId=...`)
and use it as a template so all the zero-default columns iMIS expects are present,
then change only what is needed. **[verified]**

- **`PrimaryParentIdentity` is required on create.** A `PartyId` property alone is
  not enough — without this block iMIS returns `"The PartyId (null) is not valid."`

  ```json
  "PrimaryParentEntityTypeName": "Party",
  "PrimaryParentIdentity": {
    "$type": "Asi.Soa.Core.DataContracts.IdentityData, Asi.Contracts",
    "EntityTypeName": "Party",
    "IdentityElements": {
      "$type": "System.Collections.ObjectModel.Collection`1[[System.String, mscorlib]], mscorlib",
      "$values": ["<iMISID>"]
    }
  }
  ```

- In `Properties.$values` set every data-bearing field the SOAP `MoodleUpdate`
  sends today (§5.4), not a subset — a first-time `POST` that omits the grade or a
  date would silently drop data the SOAP path records. At minimum: `PartyId`,
  `ACTIVITY_TYPE` = `MOODLE`, `PRODUCT_CODE`, `UF_1` (course id), `UF_3` (status),
  `UF_4` (grade), the start/completion/grant dates (§5.4), `TRANSACTION_DATE`,
  `DESCRIPTION` — plus the credit type/value where their columns are mapped (§5.4;
  SOAP leaves credit to ATS, so REST may need to supply it).
- **Remove `SEQN`** so iMIS assigns a new id (otherwise it clashes with the
  template row).
- **Remove `CREATED_ON` / `UPDATED_ON`** — read-only audit fields; sending values
  risks a 500.
- **Set or clear `UF_2` deliberately** — it carries over from a template (observed
  value `1325`, purpose unknown).

On success iMIS returns the created entity with the new id in both
`Identity.IdentityElements` and the `SEQN` property. **[verified]**

### 5.4 Field mapping — map **every** `MoodleUpdate` input, on create and update
The SOAP `MoodleUpdate` sends a fixed set of fields (vendor `moodleupdate_data`:
`userId`, `productId`, `type`, `value`, `status`, `score`, `startDate`,
`completionDate`, `grantDate`). `type` is the literal record discriminator
`Completion` and `value` is empty — they carry **no** credit; ATS/iMIS derives the
CEU from the product (`VENDOR-ARCHITECTURE.md`). The REST `MOODLE` record must
carry the equivalent of the data-bearing fields, and must include the same set on
both the `POST` and the `PUT` — otherwise a transport switch would quietly stop
recording grades or dates.

| `MoodleUpdate` input | Activity property | Notes |
|---|---|---|
| `userId` | `PartyId` (+ `PrimaryParentIdentity`) | Moodle ID number (§3) |
| `productId` | `UF_1` (course id) + `PRODUCT_CODE` | client-side `MOODLE_PRODUCTS` lookup (§5.2) |
| `status` | `UF_3` | `COMPLETE` / `INPROGRESS` for the course record (`completion_completion::is_complete()`) |
| `score` | `UF_4` | course final grade as a percentage (decimal) |
| `type` / `value` | — | fixed `Completion` / empty on SOAP; no REST equivalent needed (`ACTIVITY_TYPE = MOODLE` plays that role) |
| *(REST only)* credit type / value | raw columns **to confirm** | not sent over SOAP. With no ATS to derive credit, REST *may* need to write it: the plugin's "default credit type" and credit-value course field, sent only when the `rest_col_credit*` settings name the columns |
| `startDate` / `completionDate` / `grantDate` | `TRANSACTION_DATE` + raw column(s) **to confirm** | `grantDate` was **required** by the vendor payload; local-time, no TZ (§5.5) |

The raw Activity columns backing credit type/value and the start/completion/grant
dates were **not** observed in the REST testing (only `UF_1`/`UF_3`/`UF_4`/
`TRANSACTION_DATE` were). Read a real existing `MOODLE` row from your iMIS to
discover which raw columns hold them before building — do not guess column names.
**[assumed — confirm against `<dev-imis>`]**

### 5.5 Dates — local time, no timezone
Write **local-time** timestamps (Australia/Brisbane for this org) with **no
timezone** and no UTC conversion. Stored iMIS date values were observed this way.
**[verified]**

> Note: the vendor plugin formatted dates as `DateTime::ATOM` (ISO-8601 *with*
> offset). That was the contract to ATS's SOAP layer, which normalised it — it is
> **not** the direct-REST wire format. Follow the verified local-no-TZ form here.

### 5.6 Insert vs update — the bridge owns the upsert **[assumed]**
The vendor never issued an update — it re-sent `MoodleUpdate` and let **ATS**
decide insert-vs-update. So the REST `PUT` contract is unproven. The bridge must
own the upsert:

1. `GET /api/Activity?PartyId=<id>` and find an existing `MOODLE` row for this
   course (`UF_1`). **Filter server-side** by `ACTIVITY_TYPE=MOODLE` and `UF_1`, or
   **page the result fully** (`HasNext`/`NextOffset`, §8) — the default page is ~100
   rows, so a contact with many activities can have its matching row on a later page,
   and reading only the first page would miss it and create a duplicate.
2. None → `POST` a new activity (§5.3).
3. Exists → `PUT /api/Activity/{SEQN}` with the full entity (including its
   `Identity`), flipping `UF_3`/`UF_4`. **Verify `PUT` against `<dev-imis>` before
   relying on it.**

**Concurrency — serialise the read-before-create.** The read-then-write sequence
races: completion and quiz events already enqueue independent `push_activity_task`
adhoc tasks, so two overlapping workers can both finish the `GET` (step 1) before
either `POST`s (step 2), both conclude "no row", and both create a duplicate
canonical row. Guard it with a **per-(`PartyId`, course) lock** (Moodle's
`\core\lock` API) held across steps 1–3, or a server-side atomic uniqueness/upsert
if iMIS offers one. (Quiz/module events are exempt — they append `MOODLENOTE` rows
and never locate-and-update, §5.1.)

### 5.7 Errors (useful signal)
- A **structurally valid** body with a bad value → proper `ValidationResultsData`
  JSON naming the field (e.g. `"The PartyId (null) is not valid."`) → use it to
  diagnose. **[verified]**
- A generic `500` with plain text
  `"An error occurred. Please contact the administrator."` was observed **once, for
  a malformed body** in testing. But `50x` is iMIS's general *internal-error* class
  (validation failures come back as `422`), so a `500` is **not** proof the body is
  malformed — it can equally be a transient server fault. **Keep normal retry /
  backoff on `500`** (as the SOAP path has), and treat "malformed body" as one
  possible cause to check, not a conclusion. **[partially verified — 500 seen for a
  malformed body; 500 is not exclusively that]**

Route every call through the existing audit logger (operation, status, fault),
same as the SOAP path.

---

## 6. Inbound — registration pull (replaces the vendor push)

Instead of iMIS pushing users/enrolments into Moodle's web service, a **Moodle
scheduled task pulls** registrations from iMIS and provisions locally.

### 6.1 Read registrations
`GET /api/IQA?QueryName={fullPath}&Limit={n}`. **[verified]**

- `QueryName` is the full document path (genericised: `$/<org>/<folder>/<query>`).
  The query **must** have "Available via REST API" ticked or the API reports it as
  not found even though it exists.
- Rows are in `Items.$values`; each is a `GenericEntityData` with
  `Properties.$values` as `{Name, Value}` pairs using the **IQA column aliases**.
- Paging: `PagedResult` gives `TotalCount` / `HasNext` / `NextOffset`; when
  `HasNext`, repeat with `Offset={NextOffset}`. An IQA `SELECT TOP n` caps the
  designer preview only, not the API.
- **Prompts are positional `Parameter` values.** Supply each prompt as
  `&Parameter=<value>`, one per prompt in the query's prompt order. A named form
  such as `&Code=<value>` is rejected ("parameters must be Parameter Equal
  [Code]"), and omitting a **required** prompt returns HTTP `400` "Missing required
  parameter: Code". **[verified on `<dev-imis>`, 22 Oct 2026]**

The registration IQA should join registrations to `MOODLE_PRODUCTS` so each row
carries the contact's iMIS ID (= Moodle `idnumber`) and the resolved Moodle course
id.

**Cancellations must be detectable from the row set.** A row carrying only contact
+ course identifies an *active* enrolment; it gives the per-row loop no way to know a
registration was cancelled, so a cancelled member would stay enrolled indefinitely.
Pick one of:

- **Status column (preferred):** the IQA returns **both** active and recently
  cancelled registrations, each with an explicit **status** and a **stable
  registration key** (so the loop enrols on active and unenrols/suspends on
  cancelled); or
- **Snapshot reconciliation:** persist the last pulled set keyed by that stable
  registration key, and on each run treat keys that **disappeared** from the active
  result as cancellations.

Either way the pull needs a stable per-registration key, not just (contact, course)
— program-item registrations can produce several rows per contact/course (§11).

### 6.2 Provision and enrol — locally, via Moodle's internal API
For each registration row:

1. Find the Moodle user by **`idnumber`** (§3), requiring **exactly one** match.
   Moodle does **not** enforce uniqueness on `idnumber` (unlike `username`), so the
   lookup must handle the three cases distinctly: **one** match → use it; **zero** →
   **create** it (SAML won't; §3): set both `idnumber` and `username` to the iMIS ID
   (matching how the site is provisioned today), auth `saml2`, a **long random
   password** (never a password derived from the iMIS ID), sparse profile allowed;
   **more than one** → do **not** guess — skip and log/flag the ambiguity, exactly as
   `logger::resolve_userid()` already refuses a non-unique match, so a duplicate
   `idnumber` never enrols the wrong account.
2. Enrol into the mapped course via Moodle's **internal enrol API**. **Do not call
   `require_login($course)`** — check `enrol/manual:enrol` in the course context
   directly so hidden courses enrol by design and no manager role is needed
   (`VENDOR-ARCHITECTURE.md` "Why manager matters"; `DESIGN-NOTES.md` §2).
3. Decouple create from enrol — a create failure for one user never blocks another
   (`DESIGN-NOTES.md` §3).
4. Cancellations: unenrol (or suspend) locally; mirror to the activity record if
   required (§7).

Because this runs **in-process** on the Moodle side, there is no inbound token, no
token-in-URL weakness, and no external account with write access to Moodle.

---

## 7. Cancellation / unenrol
The vendor's cancelled-order path voided/marked the iMIS activity. Direct
equivalent: `DELETE /api/Activity/{SEQN}` (returns `200`, empty body —
**[verified]**, used in testing only), or `PUT` the activity to a cancelled status.
Prefer a status change over a hard delete for production so history is retained.

---

## 8. Value-encoding quirks (reads) **[verified]**
- Scalars are type-wrapped: `{"$type":"System.Boolean","$value":true}`,
  `System.Decimal`, `System.Int32`. Unwrap `$value`.
- Empty fields appear as `"Value":""` **or** with no `Value` key at all — handle
  both as null.
- Time-only fields come back with a dummy `1900-01-01` date — ignore the date part.
- Activity rows exist only once something writes them. A fresh registration has no
  activity, so a status view must fall back to a date-based status when `UF_3` is
  empty.

---

## 9. What changes vs the SOAP transport

| Concern | SOAP (today) | Direct REST (target) |
|---|---|---|
| Transport | PHP `SoapClient` → `wsmoodle.asmx` | HTTP + Bearer token → `/api/*` |
| Middleware | ATS, billed yearly | none |
| Endpoint ambiguity | WSDL-embedded `soap:address`, override setting | single iMIS base URL |
| Auth | ATS AuthToken (`MO-xxxxxx`) | OAuth password grant, service account |
| Outbound write | `MoodleUpdate` | `POST`/`PUT /api/Activity` (upsert) |
| Course→product map | iMIS-side | **client-side** `MOODLE_PRODUCTS` lookup |
| Inbound | iMIS **pushes** into Moodle WS | Moodle **pulls** via scheduled task |
| Identity | ID number (configurable) | ID number (same setting) |

What **stays the same**: the identity model (ID number), the observer events that
trigger writeback, the audit log, the kill-switches, and SSO (handled by
`auth_saml2` — out of scope either way).

---

## 10. Build phasing (inside the existing plugin)

1. **Transport switch.** Add a `transport` setting (`soap` | `rest`). SOAP stays
   default until REST is proven. REST adds base URL, service-account credentials,
   token cache. — **DONE (1.9.0)**
2. **`rest_client`** alongside `imis_client`, sharing the audit logger and the
   `imis_id_source` setting. — **DONE (1.9.0)**
3. **Outbound writeback (§5)** on the existing observer events — completion
   upserts the `MOODLE` row (per-(contact, course) lock), quiz scores append
   `MOODLENOTE`. — **DONE (1.9.0)**, with these follow-ups still open:
   - the raw columns for credit type/value and the grant/completion dates are sent
     only when mapped via the optional column settings (default: not sent), pending
     confirmation against iMIS (§5.4);
   - `PRODUCT_CODE` population via the client-side `MOODLE_PRODUCTS` lookup (§5.2)
     is not yet wired — `UF_1` (course id) is always sent; on an update the existing
     row's `PRODUCT_CODE` is preserved by the template clone;
   - the `POST` entity shape and the `PUT` update contract (§5.6) need confirming
     against the target iMIS; the read-only `cli/test_rest.php` is the safe first
     check.
4. **Inbound pull task (§6)** — the strategic win; retire ATS once proven. —
   **DONE (1.21.0)**, building on the pattern the tutorial group sync (1.19.x)
   proved. With **Sync provider = Direct iMIS REST** and a **Registrations IQA** set,
   the enrolment and cancellation syncs read that IQA (no prompts; columns `ID`,
   `EventCode`, `Status`, plus optional `RegistrationKey`, `IsActiveRegistration`,
   `EndDate`, `FirstName`, `LastName`, `Email`) and decide **per member and course**:
   any active registration enrols (creating the account first when allowed, or
   reactivating a suspended enrolment); all-cancelled suspends (grades kept); no rows
   changes nothing. The event code resolves to a course through the same
   `MoodleCourseLookup` IQA (§5.2). Safety: dry run by default, empty-read abort,
   look-back window on `EndDate`, rows flagged inactive ignored, and a per-course
   suspension guard. Groups stay with the tutorial group sync. The **Sync provider**
   setting can instead keep the ATS SOAP syncs while pushes use REST.

Phases 1–3 ship behind the switch with zero risk to the live SOAP path (default
`transport = soap`). Phase 4 is what lets the ATS subscription be cancelled.

---

## 11. Open questions / to verify before go-live
- `PUT /api/Activity/{SEQN}` update contract and required fields (§5.6).
- Purpose of `UF_2` (`1325`).
- Whether program-item registrations (e.g. an item within a parent event) produce
  separate activity rows, and how `PRODUCT_CODE` is set per item on enrolment.
- Full `MOODLE_PRODUCTS` contents and any rows with blank/irregular keys.
- Licensing position on a dedicated API service account.
- **Dev endpoints still reference production ATS** — `<dev-imis>` was cloned from
  production, so its own integration endpoints point at the prod ATS host. Before
  any direct-write test, check **RiSE → Process automation** for anything that
  fires on new activities, and keep write tests to calls that don't trigger iMIS
  automation until those endpoints are repointed.
