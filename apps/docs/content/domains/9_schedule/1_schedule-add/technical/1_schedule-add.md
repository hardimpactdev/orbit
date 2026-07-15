# Technical Contract: `orbit schedule:add [name] [--app=<app>] [--node=<node>] [--command=<command>] [--script=<path>] [--interval=<expression>] [--timezone=<timezone>] [--json]`

[Back to public `schedule-add` documentation.](../schedule-add.md)

**Owner:** `schedule`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage schedules for the resolved app or node scope.

## Signature

```bash
orbit schedule:add [name] [--app=<app>] [--node=<node>] [--command=<command>] [--script=<path>] [--interval=<expression>] [--timezone=<timezone>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Schedule slug unique within the selected scope. |
| `app` | `--app` | `Required when no node target resolves and no target can be prompted.` | `Forbidden with `node`.` | `None.` | Visible active app that the caller may manage. |
| `node` | `--node` | `Required when no app target resolves and no target can be prompted.` | `Forbidden with `app`.` | `local node:default when configured` | Visible active gateway or node with schedule capability. |
| `command` | `--command` | `Required when `script` is absent.` | `Forbidden with `script`.` | `None.` | Non-empty command line accepted by the schedule execution policy for the target scope. |
| `script` | `--script` | `Required when `command` is absent.` | `Forbidden with `command`.` | `None.` | Managed script path readable by the gateway policy and executable by the target node. |
| `interval` | `--interval` | `Required in non-interactive mode.` | `Never.` | `None.` | Portable Orbit interval expression renderable by the active schedule backend. |
| `timezone` | `--timezone` | `Optional.` | `Never.` | `target default timezone` | Valid IANA timezone. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_schedule-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_schedule-add_input-mode_non-interactive.md)

## Behavior Contract

### Schedule Configuration Rules

These rules describe how `schedule:add` resolves scope and writes the gateway schedule row.

- Resolves exactly one target scope: app or node.
- Creates one gateway schedule-configuration row in the `schedule` state family.
- Stores the schedule name, scope, target, interval, timezone, execution source, enabled state, and initial status.
- Rejects writes that collide with an existing schedule name in the selected scope, before any side effects.

### Execution Source Rules

- Accepts exactly one execution source: `--command` or `--script`.
- Stores inline commands as execution type `command`.
- Stores managed script paths as execution type `script`.
- Does not create app-instance process definitions, persistent services, or other runtime units.
- The Orbit Scheduler executes the schedule each minute it is due.
- No per-schedule node-side artifact is applied.

### Pickup Rules

- Writes gateway configuration. The gateway-only Orbit Scheduler reads the gateway database every tick; there is no node-side scheduler to notify.
- Target node local-executor reachability is verified at dispatch time, not at `schedule:add` time.
- A schedule remains valid configuration even when its target is temporarily unreachable.
- Dispatch failures are recorded in `schedule_runs` as failed runs.

### Scope Boundaries

`schedule-add` must not create apps, nodes, workspaces, tools, proxy routes, firewall rules, DNS records, or any artifacts on the scheduler side that go beyond what gateway configuration tracks. Drift detection belongs to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-add_output-render_human.md)
- [JSON renderer](6.2_schedule-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Name collision | A schedule with the same name already exists in the selected scope. | `error.code=schedule.name_collision` |
| Interval invalid | The interval cannot be parsed against the schedule expression contract. | `error.code=schedule.interval_invalid` |
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
| Properties | `name` (string or null), `app` (string or null), and `node` (string or null). No raw command text, script contents, runtime output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php` | CLI `schedule:add` POST payload, target and execution-source validation, default node when no target is supplied, and gateway error passthrough. |

There is no gateway-side coverage for this command contract: no gateway API or SDK contract test is linked for this command yet. The linked CLI test proves the mapped CLI behavior above; API behavior, activity logging, and authorization assertions remain coverage gaps until focused tests land.
