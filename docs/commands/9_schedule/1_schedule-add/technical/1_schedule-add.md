# Technical Contract: `orbit schedule:add [name] (--command=<command>|--script=<path>) --interval=<expression> [--app=<app>|--node=<node>] [--timezone=<timezone>] [--json]`

[Back to public `schedule-add` documentation.](../schedule-add.md)

**Owner:** `schedule`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage schedules for the resolved app or node scope.

## Signature

```bash
orbit schedule:add [name] (--command=<command>|--script=<path>) --interval=<expression> [--app=<app>|--node=<node>] [--timezone=<timezone>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Schedule slug unique within the selected scope. |
| `app` | `--app` | `Required when no node target resolves and no target can be prompted.` | `Forbidden with `node`.` | `None.` | Visible active app that the caller may manage. |
| `node` | `--node` | `Required when no app target resolves and no target can be prompted.` | `Forbidden with `app`.` | `local node:default when configured` | Visible active gateway or app node with schedule capability. |
| `command` | `--command` | `Required when `script` is absent.` | `Forbidden with `script`.` | `None.` | Non-empty command line accepted by the schedule execution policy for the target scope. |
| `script` | `--script` | `Required when `command` is absent.` | `Forbidden with `command`.` | `None.` | Managed script path readable by the gateway policy and executable by the target node. |
| `interval` | `--interval` | `Required in non-interactive mode.` | `Never.` | `None.` | Portable Orbit interval expression renderable by the active schedule backend. |
| `timezone` | `--timezone` | `Optional.` | `Never.` | `target default timezone` | Valid IANA timezone. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may create schedules only when their node identity has explicit
schedule-management authorization for the resolved app or node scope.
Management remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

- [Interactive input mode](5.1_schedule-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_schedule-add_input-mode_non-interactive.md)

## Behavior Contract

### Schedule Intent Rules

- Resolves exactly one target scope: app or node.
- Creates one gateway schedule-intent row in the `schedule` state family.
- Stores the schedule name, scope, target, interval, timezone, execution
  source, enabled state, and initial status.
- Fails before side effects when the name collides with an existing schedule in
  the selected scope.

### Execution Source Rules

- Accepts exactly one execution source: `--command` or `--script`.
- Stores inline commands as execution type `command`.
- Stores managed script paths as execution type `script`.
- Does not create app process definitions, persistent services, or other
  runtime units. The Orbit Scheduler executes the schedule each minute it
  is due; no per-schedule node-side artifact is enacted.

### Pickup Rules

- Writes gateway intent before notifying the target node.
- Confirms the target node's Orbit Scheduler is registered and the runtime
  backend is reachable.
- Reports `schedule.scheduler_unreachable` when gateway intent was written
  but the scheduler is not currently reachable. The schedule remains valid
  intent and runs on the next successful scheduler tick that reaches the
  gateway.

### Scope Boundaries

`schedule-add` must not create apps, nodes, workspaces, tools, proxy routes,
firewall rules, DNS records, or scheduler-side artifacts beyond gateway
intent. Drift detection belongs to
[`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-add_output-render_human.md)
- [JSON renderer](6.2_schedule-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, mutually exclusive, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage schedules for the selected scope. | `error.code=authorization_failed` |
| Name collision | A schedule with the same name already exists in the selected scope. | `error.code=schedule.name_collision` |
| Interval invalid | The interval cannot be parsed against the schedule expression contract. | `error.code=schedule.interval_invalid` |
| Execution source invalid | The selected command or script is rejected by schedule execution policy. | `error.code=schedule.execution_source_invalid` |
| Scheduler unreachable | Gateway intent was written, but the target node's Orbit Scheduler is not currently reachable for confirmation. | `error.code=schedule.scheduler_unreachable` |

## Doctor Relationship

`schedule-add` changes gateway schedule intent and performs command-owned
enactment only. [`schedule-doctor.md`](../../schedule-doctor.md) owns the
authoritative `schedule` probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /schedules` |
| Effect | `write` |
| Subject | `Schedule` when schedule intent is written; `none` for validation, target-resolution, caller-role, or authorization failures before a schedule can be logged. |
| Properties | `name` (string or null), `app` (string or null), and `node` (string or null). No raw command text, script contents, runtime output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleAddCommandTest.php` | Command contract for input validation, mutually exclusive target and execution-source rules, authorization, gateway intent write, backend enactment handoff, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, target resolution, interval normalization, execution source mapping, and schedule entity mapping. |
