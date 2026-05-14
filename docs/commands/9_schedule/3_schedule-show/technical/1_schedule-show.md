# Technical Contract: `orbit schedule:show [name] [--app=<app>] [--node=<node>] [--json]`

[Back to public `schedule-show` documentation.](../schedule-show.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the selected schedule scope.

## Signature

```bash
orbit schedule:show [name] [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` or interactive schedule data table | `Required in non-interactive mode.` | `Never.` | `None.` | Schedule slug visible to the caller. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may inspect. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or app node the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Schedule Detail Rules

- Reads one gateway schedule-configuration row visible to the caller.
- Applies optional app or node disambiguation at the gateway.
- Includes latest durable run-history summary when available.
- Distinguishes gateway-configuration status from live scheduler verification.
- Does not inspect live Orbit Scheduler state.

### Scope Boundaries

`schedule-show` must not create, update, remove, run, fix, adopt, or apply schedules. It must not read scheduler-side state directly. Drift belongs to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-show_output-render_human.md)
- [JSON renderer](6.2_schedule-show_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |

## Doctor Relationship

`schedule-show` reads gateway schedule configuration only. [`schedule-doctor.md`](../../schedule-doctor.md) owns the authoritative `schedule` probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule detail reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /schedules/{name}` |
| Effect | `read` |
| Subject | `Schedule` when the schedule is resolved and visible; `none` for not-found, validation, or authorization failures before a schedule can be logged. |
| Properties | `name` (string), `app` (string or null), and `node` (string or null). |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php` | Command contract for schedule lookup, filter validation, gateway authorization, read-only boundary, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, lookup rules, and schedule entity mapping. |
