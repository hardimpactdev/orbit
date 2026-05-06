# 17_activity — Activity Workstream

Detail file for the activity command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/17_activity/`.

The cross-cutting activity-logging foundation (Loggable contract, gateway
correlation, per-family tech-contract backfill, lint enforcement, doctrine
realignment) lives in [`activity-logging.md`](activity-logging.md).

## Commands

- [x] `activity:list` — gateway-local + Saloon forwarding + destructive
  filtering + `has_more` + `activity.listed` logging. Pest under
  `tests/Feature/Commands/Activity/` and
  `tests/Feature/Http/Api/ActivityListControllerTest.php`; Docker feature
  E2E `tests/E2E/ActivityListTest.php`.
- [x] `activity:show` — gateway-local + Saloon forwarding + related-entry
  resolution + `activity.shown` logging. Pest under
  `tests/Feature/Commands/Activity/` and
  `tests/Feature/Http/Api/ActivityShowControllerTest.php`. `lane=none`
  (read covered by `activity:list` E2E).
