# iMIS ↔ Moodle vendor bridge — architecture (as discovered, 14–15 Oct 2026)

Reference for the SCCA vendor bridge (ATS `local_imis`), reconstructed by
diagnosis. Kept as the reference model for our own `local_imisbridge` build.

> Provenance note: sanitised internal notes. Individual names and the production
> hostname are generalised because this repository is intended for public
> release. Never commit tokens, credentials or personal data here.

## Overview

The inbound bridge is **push-based, iMIS → Moodle, over REST**. ATS's iMIS cloud
initiates outbound HTTPS calls to Moodle's REST web service endpoint. Moodle only
ever responds — it never calls out on the inbound (create/enrol) path.

```
  iMIS (ATS cloud)                         Moodle (SCCA site)
  ───────────────                          ──────────────────
  Registration /          HTTPS POST        /webservice/rest/server.php
  trigger occurs   ───────────────────►     ?wstoken=<TOKEN>
                        (REST, token         &wsfunction=local_imis_create_users
                         in query string)    &users[0][username]=...&...
                                             │
                        ◄───────────────────┤  XML response (success or EXCEPTION)
                         ResultCode /         │
                         ResultMessage        ▼
                                             executes local_imis_* function
                                             (create / enrol / update user)
```

## Transport & endpoint

- **Protocol:** Moodle REST web service. (SOAP is *disabled* on Moodle and is
  irrelevant to this path — the `.asmx` SOAP endpoint is only how iMIS talks to
  the ATS middleware, not how ATS talks to Moodle.)
- **Endpoint:** `https://<moodle-site>/webservice/rest/server.php` (stored in
  iMIS as `REST_ENDPOINT`).
- **Call style:** parameters passed in the **query string**, including the token
  (`wstoken=...`) and the function (`wsfunction=...`). The token in the URL is a
  security weakness — it lands in web server logs, proxies, and error messages.
  The vendor's own error output echoes the full token this way.

## Authentication

- **Single web service token**, stored on the iMIS side in
  Settings → General Lookup Tables → `MOODLE_SETTINGS` → `WEBSERVICE_TOKEN`.
- The token belongs to a **Moodle account**, tied to the **imis Service**
  external service (service shortname `imis_service`).
- **Working account = a system-level `manager` staff account.** Manager-level
  course access is what lets the enrol step succeed — see "Why manager matters".
- No token expiry was set; tokens here don't lapse on their own. When the bridge
  broke, the token had been *deleted/replaced*, returning `invalidtoken` /
  "token not found".

## Functions (the API surface)

The **imis Service** exposes five functions (mapped in iMIS `MOODLE_SETTINGS`):

| iMIS setting                      | Moodle function            | Direction |
|-----------------------------------|----------------------------|-----------|
| `FN_CORE_USER_GET_USERS`          | `local_imis_get_users`     | lookup    |
| `FN_CORE_USER_CREATE_USERS`       | `local_imis_create_users`  | inbound   |
| `FN_CORE_USER_UPDATE_USERS`       | `local_imis_update_users`  | inbound   |
| `FN_ENROL_MANUAL_ENROL_USERS`     | `local_imis_enrol_users`   | inbound   |
| `FN_ENROL_MANUAL_UNENROL_USERS`   | `local_imis_unenrol_users` | inbound   |

(All five are *also* exposed on the mobile app service — a surface-area problem,
not part of the intended design.)

## The order flow (per registration)

A registration triggers an "order" that runs, in sequence:

1. `local_imis_get_users` — look up whether the member already has an account.
2. `local_imis_create_users` — create the account if absent.
3. `local_imis_enrol_users` — enrol into the mapped course, assign role
   `ROLE_ID` (=5, Student).

Observed in the Moodle log as a tight cluster (same second), e.g. get → create →
`user_created` → enrol → `user_enrolment_created` → `role_assigned`.

**Coupling flaw:** create and enrol are one atomic order. A failed create aborts
the whole order (`OrdersSent=false`) so enrol never runs. With
`ALLOW_UPDATE_USERS=FALSE`, any already-existing member fails create → never
enrols. (Our build must decouple these.)

## Course mapping

- Target Moodle course is resolved from an iMIS general lookup table
  (`moodle_products`), keyed by `PRODUCTCODE/REGOPTION` (registration option is
  uniformly `/REG`), with the Moodle **course id** held in the `description`
  field. Rows created manually in iMIS.
- `COURSE_URL` = `.../course/view.php?id=` (the course link pattern).

## Why manager matters (the enrol gotcha)

The vendor's `local_imis_enrol_users` calls `require_login($course)` internally.
For a **hidden** course, `require_login()` runs access checks that a full
**manager** passes but a minimal web-service role does **not** — even with
`moodle/course:viewhiddencourses` granted. SCCA runs hidden courses with members
enrolled, so the enrolling account **must** effectively have manager-level course
access. This is why:

- the historical manager account enrolled into hidden courses fine for months;
- a new tightly-scoped `webserviceuser` account could *create* but not *enrol*;
- the fix was to also grant the service account the `manager` role.

(For our own plugin: don't call `require_login()` in the enrol function — check
`enrol/manual:enrol` in the course context directly, so a least-privilege role
works. See `DESIGN-NOTES.md` §2. At cutover the bridge is repointed to core
`enrol_manual_enrol_users` instead, which works for a least-privilege account given
`moodle/course:view` + `viewhiddencourses` — `CHANGEOVER-CHECKLIST.md` §C.)

## Config reference (iMIS `MOODLE_SETTINGS` lookup table)

| Code                        | Value / meaning                                   |
|-----------------------------|---------------------------------------------------|
| `REST_ENDPOINT`             | Moodle REST server.php URL                         |
| `WEBSERVICE_TOKEN`          | the auth token (on the manager account / imis Service) |
| `ROLE_ID`                   | 5 — role assigned on enrol (Student)               |
| `AUTH_MODE`                 | saml2 — accounts created as SAML2 auth             |
| `ALLOW_UPDATE_USERS`        | FALSE — won't update existing users (collision src)|
| `DEFAULT_TWO_DIGIT_COUNTRY` | AU                                                 |
| `API_DELAY_MILLISECONDS`    | 250 — throttle between calls                       |
| `CONTACT_DATA_IQA`          | `$/ATS/Bridges/Moodle/SAML` — source IQA           |
| `IQA_FOLDER_NAME`           | `$/ATS/Bridges/Moodle/`                            |
| `COURSE_URL`                | `.../course/view.php?id=`                          |
| `SSO_URL_PARAM`             | wstoken (token also used in an SSO pass-through)    |
| `LOGIN_URL`                 | `.../ATS/SAMLPASSTHRU.aspx`                         |
| `XMLRPC_ENDPOINT`           | NA (unused)                                        |

## Reverse direction — Moodle → iMIS (completions, grades)

A **separate** channel over the ATS SOAP service, logged in the vendor's
`local_imis_logs` table as `moodle_update` / `create_activity` actions. It kept
working throughout the inbound outage, which is how we proved the two systems
could still communicate and localised the fault to the inbound token.

The vendor source (reconstructed from `local_imis`) shows two operations:

**`MoodleUpdate`** — the course-level completion / progress record. The vendor's
`moodleupdate_data` always sends a fixed shape; only the contact, course, status,
score and dates vary:

| SOAP field                           | Value                                            |
|--------------------------------------|--------------------------------------------------|
| `userId`                             | iMIS ContactID (Moodle `user.idnumber`)          |
| `productId`                          | Moodle course id (maps to the iMIS product)      |
| `type`                               | **`Completion`** — a literal record discriminator, **not** a credit type |
| `value`                              | **empty**                                        |
| `status`                             | `COMPLETE`, `INPROGRESS`, `NOTSTARTED`, `PASS` or `FAIL` |
| `score`                              | course grade                                     |
| `startDate` / `completionDate` / `grantDate` | `Y-m-d`                                  |

**The service only writes the record when `type` is exactly `Completion`.** Any
other `type` (e.g. a credit type such as `CEU`) returns `ResultCode 0` but lands
nothing — a silent no-op. `status` is the Moodle completion state mapped to the
uppercase ATS vocabulary (`COMPLETE` for a finished course, `INPROGRESS` for a
grade-in-progress). The CEU credit amount is **not** carried here — iMIS derives
it from the product on its own side. (Our plugin originally sent `type=CEU`,
`value=0`, `status=Pass`, which is why pushes returned success but never
appeared; fixed to match this contract.)

**`createActivity`** — a richer per-module activity record (ATS `ActivityType`,
e.g. `MOODLENOTE`) carrying the module name, feedback as the note, and the
per-module status/score in `UserField3`/`UserField4` with the course module id in
`UserField5`. Used for case-study / module-level completions, separate from the
course-level `MoodleUpdate`.
