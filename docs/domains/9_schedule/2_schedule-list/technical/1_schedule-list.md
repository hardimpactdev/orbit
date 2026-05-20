# Technical Contract: `orbit schedule:list [--app=<app>] [--node=<node>] [--json]`

[Back to public `schedule-list` documentation.](../schedule-list.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect schedules for the selected scope.

## Signature

```bash
orbit schedule:list [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may inspect. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or node the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Schedule Configuration Visibility Rules

- Reads gateway schedule configuration visible to the caller.
- Applies the optional app or node filter at the gateway.
- Returns app-scoped, node-scoped, and Orbit-scoped schedules when no filter is supplied.
- Limits the result to schedules the caller is authorized to see.
- Includes latest durable run-history summary when available.
- Does not inspect live Orbit Scheduler state.

### Scope Boundaries

`schedule-list` must not create, update, remove, run, fix, adopt, or apply schedules. It must not read scheduler-side state directly. Drift belongs to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-list_output-render_human.md)
- [JSON renderer](6.2_schedule-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Doctor Relationship

`schedule-list` reads gateway schedule configuration only. [`schedule-doctor.md`](../../schedule-doctor.md) owns the authoritative `schedule` probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /schedules` |
| Effect | `read` |
| Subject | `none` |
| Properties | `app` (string or null) and `node` (string or null). |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleListCommandTest.php` | Command contract for filter validation, gateway authorization, read-only boundary, run-history summary inclusion, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, filter rules, and schedule entity mapping. |
