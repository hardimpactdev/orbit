# Activity Logging Workstream

Cross-cutting infrastructure for activity logging across every command and
API endpoint. Product authority:
[`../commands/17_activity/activity-concepts.md`](../commands/17_activity/activity-concepts.md).

Every ported command's `technical/1_<command>.md` must declare a
`## Activity Logging` section. The
`command_docs.activity_logging_contract` lint rule enforces the section
against an explicit allowlist that grows per family as commands backfill.

Loggable contract vocabulary (doctrine names): `effect()`
(`read`|`write`|`destructive`), `type()` (action string like
`node.granted`), `subject()`, `properties()`, `description()`. The
doctrine `effect` corresponds to old `Type`, doctrine `type` to old
`Action`.

Read commands (`*:list`, `*:show`) emit with `effect=read`. A read may
declare "does not emit" only when noise dominates audit value; record the
exception in the command's `## Activity Logging` section with a reason.

## Foundation

- [x] `spatie/laravel-activitylog` v4.12.3 installed and configured.
- [x] `ActivityLogCorrelation` service + `X-Orbit-Request-Id` middleware
  propagation across gateway API and CLI.
- [x] Activity-log Loggable interface/traits used by commands and
  controllers, with old method names (`activityLogType`, etc.) kept as
  thin proxies on `App\Concerns\LogsCommandActivity` until callers migrate.
- [x] Activity family split into `17_activity` with doctrine
  (`activity-concepts.md`), reconciled `CONCEPTS.md`/`operation-concepts.md`,
  and `NonStateDomainHandoffRule` registration.
- [x] Activity-effect enum supports `read`/`write`/`destructive` end-to-end
  (PHP enum, middleware, gateway API payload, `activity:list --effect`
  filter, CLI forwarding).
- [x] `activity:list` and `activity:show` — see [`17_activity.md`](17_activity.md).

## Per-family contract backfill

| Family | Status |
|---|---|
| `1_node` | [x] all nine commands declared and enforced |
| `2_gateway` | [x] |
| `3_tool` | [x] read commands (`tool:list`, `tool:show`) |
| `4_firewall` | [ ] commands ported; tech-contract sections + allowlist entries pending |
| `5_app` | [x] |
| `6_workspace` | [x] commands and step-policy commands |
| `7_process` | [x] |
| `8_proxy` | [ ] commands ported; tech-contract sections + allowlist entries pending |
| `9_schedule` | [x] commands and API Loggable controllers |
| `10_deploy` | [ ] commands not started; add per-command activity contracts while porting |
| `11_operation` | [~] `update`, `update:all`, `profile`, verify-mode `doctor` declared. `doctor --fix`/`--adopt` pending per family action map. |
| `12_cf` / `13_vpn` / `14_php` | [ ] commands not started; add per-command activity contracts while porting |
| `15_agent-ide` | [x] `agent-ide:message` enforced |
| `16_dns` | [x] |
| `17_activity` | [x] (allowlist origin) |

## Test gates

- [x] Loggable contract per controller plus correlation generation through
  `LogActivity` middleware (`tests/Feature/Http/Middleware/LogActivityTest.php`).
- [x] Gateway API activity list/show tests cover destructive filtering,
  normalized JSON metadata, `has_more`, validation, and the `activity.listed`
  / `activity.shown` events
  (`tests/Feature/Http/Api/ActivityListControllerTest.php`,
  `ActivityShowControllerTest.php`).
- [x] CLI command tests under `tests/Feature/Commands/Activity/Activity*Test.php`.
- [x] Docker feature E2E for `activity:list` from a control caller
  (`tests/E2E/ActivityListTest.php`).
