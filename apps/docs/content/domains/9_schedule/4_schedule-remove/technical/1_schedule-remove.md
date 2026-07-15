# Technical Contract: `orbit schedule:remove [name] [--app=<app>] [--node=<node>] [--force] [--json]`

[Back to public `schedule-remove` documentation.](../schedule-remove.md)

**Owner:** `schedule`.

**Effects:** `write, destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage the selected schedule scope.

## Signature

```bash
orbit schedule:remove [name] [--app=<app>] [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` or interactive schedule data table | `Required in non-interactive mode.` | `Never.` | `None.` | Existing visible schedule slug. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may manage. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or node the caller may manage. |
| `force` | `--force` | `Required in non-interactive mode.` | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_schedule-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_schedule-remove_input-mode_non-interactive.md)

## Behavior Contract

### Schedule Removal Rules

- Resolves the schedule by name and optional app or node disambiguation from gateway schedule configuration.
- Fails before side effects when no visible schedule matches.
- Deletes the schedule row from the gateway database. Subsequent scheduler
  queries cannot select the deleted row.
- Returns after the gateway row deletion succeeds. It does not contact the
  schedule target, notify a scheduler, wait for a heartbeat, or publish an
  operation-progress stream.
- Retains durable run history unless a future retention command explicitly prunes it.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before gateway removal is recorded.
- Non-interactive mode requires `--force`.
- `--json` does not imply destructive consent.

### Scope Boundaries

`schedule-remove` must not remove app code, app-instance process definitions, nodes,
proxy routes, firewall rules, DNS records, target-node artifacts, or scripts.
An optional reachability warning may describe node state after the write, but
must not imply remote cleanup.

## Renderer Contracts

- [Human renderer](6.1_schedule-remove_output-render_human.md)
- [JSON renderer](6.2_schedule-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=destructive_consent_required` |

## Doctor Relationship

`schedule-remove` deletes gateway schedule configuration only.
[`schedule-doctor.md`](../../schedule-doctor.md) owns the authoritative
`schedule` probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /schedules/{name}` |
| Effect | `destructive` |
| Subject | `Schedule` when the schedule is resolved before removal; `none` for not-found, validation, or authorization failures before a schedule can be logged. |
| Properties | `name` (string), `app` (string or null), and `node` (string or null). No runtime output or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php` | CLI destructive consent, gateway-only DELETE payload, history-retained metadata, and interactive confirmation. |
| `apps/gateway/tests/Feature/Models/ScheduleTest.php` | Gateway row deletion without heartbeat, target pickup, or notification state. |

There is no gateway-side coverage for this command contract: no gateway API or SDK contract test is linked for this command yet. The linked CLI test proves the mapped CLI behavior above; API behavior, activity logging, and authorization assertions remain coverage gaps until focused tests land.
