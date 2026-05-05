# Technical Contract: `orbit schedule:show <name> [--app=<app>] [--node=<node>] [--json]`

[Back to public `schedule-show` documentation.](../schedule-show.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to inspect the selected schedule scope.

## Signature

```bash
orbit schedule:show <name> [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required.` | `Never.` | `None.` | Schedule slug visible to the caller. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may inspect. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or app node the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may read visible schedules when authorized; `schedule:show`
never grants write permission or local state authority.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt;
missing required input and invalid filters fail according to the shared
invocation model.

## Behavior Contract

### Schedule Detail Rules

- Reads one gateway schedule-intent row visible to the caller.
- Applies optional app or node disambiguation at the gateway.
- Includes latest durable run-history summary when available.
- Distinguishes gateway-intent status from live scheduler verification.
- Does not inspect live Orbit Scheduler state.

### Scope Boundaries

`schedule-show` must not create, update, remove, run, fix, adopt, or enact
schedules. It must not read scheduler-side state directly. Drift belongs to
[`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-show_output-render_human.md)
- [JSON renderer](6.2_schedule-show_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | The name or filter is malformed, unsupported, or mutually exclusive. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect the selected schedule. | `error.code=authorization_failed` |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |

## Doctor Relationship

`schedule-show` reads gateway schedule intent only.
[`schedule-doctor.md`](../../schedule-doctor.md) owns the authoritative
`schedule` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php` | Command contract for schedule lookup, filter validation, gateway authorization, read-only boundary, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, lookup rules, and schedule entity mapping. |
