# Changelog

All notable changes to the iMIS Bridge plugin (`local_imisbridge`) are documented
in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.0] - 2026-10-19

### Added
- **Service endpoint override.** A new optional *Service endpoint URL (override)*
  setting routes SOAP requests to a specified `.asmx` endpoint instead of the
  address embedded in the WSDL. PHP's `SoapClient` sends requests to the
  `<soap:address location>` inside the WSDL, not to the host the WSDL was fetched
  from, so a staging WSDL that embeds a production address would otherwise send
  every call — including writes — to production. Leave blank to use the WSDL's
  address (unchanged behaviour).
- The read-only connection test (CLI and admin page) now reports the endpoint
  requests will actually be sent to — the override, or the address read from the
  WSDL — so a staging-to-production mismatch is caught before any write.

## [1.6.1] - 2026-10-15

### Fixed
- Align the SOAP client with the published `wsmoodle.asmx` WSDL contract:
  - `MoodleUpdate`: send the contract's camelCase `userId` / `productId`
    parameters (were `UserID` / `productID`, which the document/literal service
    would not bind), and read the `ResultsBase` response (was a non-existent
    `MoodleUpdateResult`).
  - `UpdateMoodleGroups`: use `CourseId` / `LastUpdated` (were `CourseID` /
    `lastUpdated`), send the `AuthToken`, and read the `ResultsBase` response.
  - `SendNewOrdersToMoodle` / `SendCancelledOrdersToMoodle`: send the required
    `AuthToken`.
  - `GetBridgeSettings`: send the `AuthToken` when one is configured (still
    allowing a token-less connectivity check).
  - `MoodleGetUserProfileByToken`: use the contract's lowercase `token`
    parameter and no `AuthToken`.
  - `getIQARows`: pass the single `jsonParams` object (`QueryPath`,
    `QueryFilters`, `PageSize`, `PageOffset`) the WSDL defines.

### Added
- `imis_client::setup_bridge()` wrapping the `SetupBridge` operation, returning
  the service's log lines.

## [1.6.0] - 2026-10-13

### Added
- **Bulk completions/grades pull web service.** A new read-only web service
  function `local_imisbridge_get_completions_since` lets iMIS poll Moodle for
  every course completion and final course grade changed since a given time,
  in stable id order with a paging cursor (`afterid` / `nextafterid` / `hasmore`).
  iMIS makes the call, so no extra iMIS API user licence is needed. Exposed
  through a pre-built, restricted "iMIS bridge" service and gated by a new
  `local/imisbridge:readcompletions` capability (granted to no role by default —
  assign it to the dedicated web service user only). Deleted and suspended
  users are excluded.

## [1.5.0] - 2026-10-13

### Added
- **iMIS audit log.** Every SOAP call to iMIS is now recorded (operation, user,
  product, status, any fault, timestamp) in a new `local_imisbridge_log` table,
  viewable and filterable on a new admin page (_iMIS Bridge_ settings → Open iMIS
  audit log). Credentials (AuthToken, session tokens, `MO-` tokens) are redacted.
- **Opt-in payload capture.** A _Capture full request/response_ setting (off by
  default) additionally stores the full SOAP request/response XML for debugging
  the wire format; leave it off in normal operation to avoid holding personal
  data at rest.
- **Configurable retention.** A daily task prunes log rows older than a
  configurable number of days (default 90; 0 keeps them indefinitely).
- The privacy provider now exports and erases a user's audit-log rows.

## [1.4.2] - 2026-10-13

### Added
- The manual sync admin page now shows a confirmation step before running any
  enrolment, cancellation, group or full sync, warning explicitly when no
  contact ID is set (which syncs **all** users against the production iMIS).

## [1.4.1] - 2026-10-12

### Changed
- CI: rewrote the GitHub Actions matrix to the Moodle 5.1–5.3 LTS baseline —
  branch-aware (MOODLE_501/502/503_STABLE plus a non-blocking Moodle `main`
  cell), on PostgreSQL 17 and MariaDB 11. The retired Moodle 5.0 cells are
  removed. Grunt now runs only on the 5.1 cells.
- Raised the minimum Moodle version to 5.1 (`$plugin->requires` = `2025100600`)
  and declared `$plugin->supported = [501, 503]`. The `requires` for 5.1 is a
  minimum; Moodle 5.0 is no longer a declared target.
- Updated the README and testing-guide requirements to Moodle 5.1–5.3.

## [1.4.0] - 2026-09-21

Open-source release preparation. All changes are backward-compatible: existing
installations keep their stored configuration and their current on-the-wire
behaviour.

### Added
- **Configurable iMIS contact ID source** — a new *iMIS contact ID source*
  setting (`imis_id_source`) lets sites choose which Moodle user field holds the
  iMIS contact ID: username, ID number, or email. Defaults to username, so
  existing installs are unaffected. Observers resolve the ID through the new
  `util::get_imis_id()` helper.
- **Configuration guard** — `util::is_configured()` short-circuits every
  automatic write (the login/completion/quiz observers, the three nightly tasks,
  and the queued adhoc tasks) when no WSDL URL is set, so a fresh install is a
  clean no-op instead of raising repeated cron errors.
- Unit tests for the ID-source resolver, the configuration guard, and the
  observer paths that use them.

### Changed
- The shipped **iMIS WSDL URL** default is now blank instead of an
  organisation-specific ATS host, so a fresh install never points at another
  site's endpoint. Existing installs keep their stored URL.
- Generalised the privacy metadata string, `README.md`, and `docs/TESTING.md` to
  describe the configurable identifier rather than assuming the username.

[1.7.0]: https://github.com/verzog/imisbridge/releases/tag/v1.7.0
[1.6.1]: https://github.com/verzog/imisbridge/releases/tag/v1.6.1
[1.6.0]: https://github.com/verzog/imisbridge/releases/tag/v1.6.0
[1.5.0]: https://github.com/verzog/imisbridge/releases/tag/v1.5.0
[1.4.2]: https://github.com/verzog/imisbridge/releases/tag/v1.4.2
[1.4.1]: https://github.com/verzog/imisbridge/releases/tag/v1.4.1
[1.4.0]: https://github.com/verzog/imisbridge/releases/tag/v1.4.0
