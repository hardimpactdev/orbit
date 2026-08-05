# Technical Contract: `orbit schedule:add [name] [--instance=<app.instance>] [--node=<node>] [--command=<command>] [--script=<path>] [--interval=<expression>] [--timezone=<timezone>] [--timeout=<seconds>] [--json]`

[Back to public `schedule-add` documentation.](../schedule-add.md)

**Owner:** `schedule`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage schedules on the resolved
  instance's serving node or selected node.

## Signature

```bash
orbit schedule:add [name] [--instance=<app.instance>] [--node=<node>] [--command=<command>] [--script=<path>] [--interval=<expression>] [--timezone=<timezone>] [--timeout=<seconds>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Schedule slug unique within the selected concrete target. |
| `instance` | `--instance` | `Required when no node target resolves and no target can be prompted.` | `Forbidden with `node`.` | `None.` | Visible eligible `app.instance`; a bare app is shorthand only when exactly one eligible instance is visible. |
| `node` | `--node` | `Required when no instance target resolves and no target can be prompted.` | `Forbidden with `instance`.` | `local node:default when configured` | Visible active gateway or node with schedule capability. |
| `command` | `--command` | `Required when `script` is absent.` | `Forbidden with `script`.` | `None.` | Non-empty command line accepted by the schedule execution policy for the target scope. |
| `script` | `--script` | `Required when `command` is absent.` | `Forbidden with `command`.` | `None.` | Managed script path readable by the gateway policy and executable by the target node. |
| `interval` | `--interval` | `Required in non-interactive mode.` | `Never.` | `None.` | Portable Orbit interval expression renderable by the active schedule backend. |
| `timezone` | `--timezone` | `Optional.` | `Never.` | `target default timezone` | Valid IANA timezone. |
| `timeout` | `--timeout` | `Optional.` | `Never.` | `900` | Integer from `1` through `86400`; limits the command and its remote transport with a small transport buffer. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_schedule-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_schedule-add_input-mode_non-interactive.md)

## Behavior Contract

### Schedule Configuration Rules

These rules describe how `schedule:add` resolves scope and writes the gateway schedule row.

- Resolves exactly one target scope: instance or node.
- Resolves instance scope to exactly one concrete instance before writing. A
  dotted selector addresses that instance; a bare app selector succeeds only
  when exactly one eligible instance is visible for `schedule:add`.
- Creates one gateway schedule-configuration row in the `schedule` state family.
- Stores the schedule name, scope, concrete `instance_id` when applicable,
  target, interval, timezone, execution source, execution timeout, enabled state, and initial
  status.
- Rejects ambiguous instance selectors and writes that collide with an existing
  schedule name in the selected concrete target before any side effects.

### Execution Source Rules

- Accepts exactly one execution source: `--command` or `--script`.
- Stores inline commands as execution type `command`.
- Stores managed script paths as execution type `script`.
- Does not create instance process definitions, persistent services, or other runtime units.
- The Orbit Scheduler executes the schedule each minute it is due.
- Dispatch enforces the stored per-schedule timeout. Remote transport receives a 15-second completion buffer beyond the command timeout.
- No per-schedule node-side artifact is applied.

### Pickup Rules

- Writes gateway configuration. The gateway-only Orbit Scheduler reads the gateway database every tick; there is no node-side scheduler to notify.
- Target node local-executor reachability is verified at dispatch time, not at `schedule:add` time.
- A schedule remains valid configuration even when its target is temporarily unreachable.
- Dispatch failures are recorded in `schedule_runs` as failed runs.

### Scope Boundaries

`schedule-add` must not create apps, instances, nodes, workspaces, tools, proxy routes, firewall rules, DNS records, or any artifacts on the scheduler side that go beyond what gateway configuration tracks. Drift detection belongs to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-add_output-render_human.md)
- [JSON renderer](6.2_schedule-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Name collision | A schedule with the same name already exists in the selected concrete target. | `error.code=schedule.name_collision` |
| Instance required | No eligible instance exists for a bare app, or more than one eligible instance is visible. | `error.code=validation_failed`, `error.meta.reason=instance_required` |
| Interval invalid | The interval cannot be parsed against the schedule expression contract. | `error.code=schedule.interval_invalid` |
| Timeout invalid | The timeout is outside `1..86400` seconds. | `error.code=validation_failed`, `error.meta.field=timeout` |
| Execution source invalid | The selected command or script is rejected by schedule execution policy. | `error.code=schedule.execution_source_invalid` |

## Doctor Relationship

`schedule-add` changes gateway schedule configuration and performs command-owned application only. [`schedule-doctor.md`](../../schedule-doctor.md) owns the authoritative `schedule` probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /schedules` |
| Effect | `write` |
| Subject | `Schedule` when schedule configuration is written; `none` for validation, target-resolution, or authorization failures before a schedule can be logged. |
| Properties | `name` (string or null), `instance` (string or null), and `node` (string or null). No raw command text, script contents, runtime output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php` | CLI `schedule:add` POST payload, target and execution-source validation, default node when no target is supplied, and gateway error passthrough. |
| `apps/gateway/tests/Feature/Http/Api/ScheduleInstanceOwnershipTest.php` | Explicit and bare instance resolution, per-instance name uniqueness, serving-node payloads, and ambiguity before writes. |
| `apps/gateway/tests/Feature/Migrations/CanonicalizeScheduleAppInstanceOwnershipTest.php` | Existing app-schedule ownership backfill and ambiguous migration stop. |

Activity logging assertions remain a coverage gap until focused tests land.
