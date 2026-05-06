# Technical Contract: `orbit schedule:remove <name> [--app=<app>] [--node=<node>] [--force] [--json]`

[Back to public `schedule-remove` documentation.](../schedule-remove.md)

**Owner:** `schedule`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage the selected schedule scope.

## Signature

```bash
orbit schedule:remove <name> [--app=<app>] [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Existing visible schedule slug. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may manage. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or app node the caller may manage. |
| `force` | `--force` | `Required in non-interactive mode.` | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may remove schedules only when their node identity has explicit
schedule-management authorization for the selected scope. Management remains
gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

- [Interactive input mode](5.1_schedule-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_schedule-remove_input-mode_non-interactive.md)

## Behavior Contract

### Schedule Removal Rules

- Resolves the schedule by name and optional app or node disambiguation from
  gateway schedule intent.
- Fails before side effects when no visible schedule matches.
- Records removal intent on the gateway.
- Confirms the target node's Orbit Scheduler is reachable so the removal is
  picked up on the next scheduler tick.
- Finalizes the schedule as removed once the gateway intent has been
  written.
- Retains durable run history unless a future retention command explicitly
  prunes it.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before gateway
  removal intent is recorded.
- Non-interactive mode requires `--force`.
- `--json` does not imply destructive consent.

### Scope Boundaries

`schedule-remove` must not remove app code, app process definitions, nodes,
proxy routes, firewall rules, DNS records, or scripts outside managed
schedule policy. Scheduler-side state drift belongs to
[`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-remove_output-render_human.md)
- [JSON renderer](6.2_schedule-remove_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or mutually exclusive. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage the selected schedule. | `error.code=authorization_failed` |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=destructive_consent_required` |
| Scheduler unreachable | Gateway removal intent was recorded, but the target node's Orbit Scheduler could not be confirmed reachable for prompt pickup. | `error.code=schedule.scheduler_unreachable` |

## Doctor Relationship

`schedule-remove` removes gateway schedule intent and performs command-owned
cleanup only. [`schedule-doctor.md`](../../schedule-doctor.md) owns the
authoritative `schedule` probe, issue codes, fix map, and adopt map.

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
| `tests/Feature/Commands/Schedule/ScheduleRemoveCommandTest.php` | Command contract for lookup, filter validation, gateway authorization, destructive consent, cleanup failure codes, history retention, and doctor handoff behavior. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, lookup rules, destructive consent mapping, and removed schedule entity mapping. |
