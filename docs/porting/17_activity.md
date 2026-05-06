# 17_activity — Activity Workstream

Detail file for the activity command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/17_activity/`.

The cross-cutting activity-logging foundation (Loggable contract, gateway
correlation, per-command tech contract backfill, lint enforcement, doctrine
realignment) is tracked in [`activity-logging.md`](activity-logging.md).

## Commands

- [x] `activity:list` — gateway-local + forwarding + destructive filtering +
  renderers + Docker feature E2E.
  - Gateway API list endpoint, typed Saloon request/DTO, normalized JSON
    metadata, `has_more`, and `activity.listed` logging through
    `tests/Feature/Http/Api/ActivityListControllerTest.php`.
  - CLI command tests under `tests/Feature/Commands/Activity/Activity*Test.php`
    cover local gateway reads, typed gateway forwarding, destructive filtering,
    validation, empty human output, and typed gateway request DTO parsing.
- [x] `activity:show` — gateway-local + forwarding + renderers + related
  entries.
  - Gateway API show endpoint covers selected activity details, related
    entries, not-found and validation failures, and `activity.shown` logging
    through `tests/Feature/Http/Api/ActivityShowControllerTest.php`.
  - CLI command tests cover local gateway detail reads, typed gateway
    forwarding, validation, not-found, authorization failures, human detail
    output, and typed gateway request DTO parsing.

## E2E gate

- [x] Docker feature read of `activity:list` from control through the gateway
  API after seeding a few gateway activity entries; read-only from the caller
  perspective.
