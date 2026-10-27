# Providers

This plugin is a bridge between Moodle and an external membership/records system.
The system it talks to is abstracted behind a **provider**, so iMIS (via the ATS
SOAP bridge, or directly over REST) is the shipped integration rather than a
hard-wired assumption. Another membership/association system can be supported by
adding a provider class — no change to the observers, tasks or admin pages.

## Shipped providers

| Setting value | Class | Talks to | Capabilities |
|---------------|-------|----------|--------------|
| `soap` | `\local_membershipbridge\imis_client` | iMIS via the ATS `wsmoodle.asmx` middleware | push + order/group sync (ATS calls back into Moodle) |
| `rest` | `\local_membershipbridge\rest_client` | the iMIS REST API directly | push + registration sync (reads a registrations IQA and enrols locally, 1.21.0) |

Two settings pick the providers independently:

- **Connection provider** (`local_membershipbridge/transport`) sends records: completions,
  scores and notes.
- **Sync provider** (`local_membershipbridge/sync_provider`) runs the enrolment,
  cancellation and group syncs: the manual sync buttons, the login syncs and the
  nightly tasks. Blank (the default) follows the connection provider. Setting it to
  `soap` while the connection provider is `rest` keeps the ATS syncs while records
  go over REST.

A provider syncs only when `is_sync_configured()` says its sync settings are present:
the WSDL URL for `soap`; the REST connection **and** a Registrations IQA for `rest`.
So a site that only pushes over REST never starts enrolling on upgrade.

## The contract

A provider implements `\local_membershipbridge\provider\provider_interface`:

- `label()`, `is_configured()`, `supports($capability)` — **static**, so the plugin
  can list providers, gate capabilities and check configuration without
  constructing a client (a constructor needs live connection settings and may
  throw without them).
- `push_completion()`, `push_score()`, `push_module_note()` — the outbound writes,
  each taking the queued task's custom-data object.

A provider that can also pull orders/cancellations/groups into Moodle additionally
implements `\local_membershipbridge\provider\org_sync_provider` (both shipped providers
do), including the static `is_sync_configured()`. `\local_membershipbridge\provider\manager`
resolves the sync provider and returns `null` from `org_sync_instance()` when it
cannot sync or is not configured to, so the scheduled tasks, the login sync and the
manual sync page skip or hide those controls automatically.

## Features outside the provider

Two features do not go through the active provider, so they behave the same
whichever is selected:

- **Tutorial group sync** (`\local_membershipbridge\task\sync_tutorial_groups`) reads the
  iMIS `MoodleTutorialGroup` panel with `rest_client` directly. It needs the REST
  connection settings even when the provider is `soap`, and filling those in with
  `soap` selected enables only this feature — pushes still go over SOAP.
- **`local_membershipbridge_cancel_enrolment`** is an inbound web-service function that
  the ATS bridge calls into Moodle; it makes no outbound call at all.

## Adding a provider

1. Write a class in `classes/` (or a subnamespace) implementing
   `provider_interface` — and `org_sync_provider` too if it offers inbound sync.
2. Register it in `\local_membershipbridge\provider\manager::providers()`, keyed by the
   value you want stored in the Connection provider setting.
3. Add any provider-specific settings to `settings.php`. The provider dropdown is
   built from the registry, so the new choice appears automatically.
