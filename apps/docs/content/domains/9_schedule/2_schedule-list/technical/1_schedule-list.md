# Technical Contract: `orbit schedule:list [--instance=<app.instance>] [--node=<node>] [--json]`

[Back to public `schedule-list` documentation.](../schedule-list.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect schedules for the selected
  concrete instance or node scope.

## Signature

```bash
orbit schedule:list [--instance=<app.instance>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `--instance` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible eligible `app.instance`; a bare app is shorthand only when exactly one eligible instance is visible. |
| `node` | `--node` | `Optional.` | `Forbidden with `instance`.` | `None.` | Visible active gateway or node the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Schedule Configuration Visibility Rules

- Reads gateway schedule configuration visible to the caller.
- Resolves an optional app filter to one concrete instance before querying;
  it never aggregates multiple instances for a bare app selector.
- Applies the concrete instance or node filter at the gateway.
- Returns instance-scoped, node-scoped, and Orbit-scoped schedules when no filter is supplied.
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

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | No eligible instance exists for a bare app, or more than one eligible instance is visible. | `error.code=validation_failed`, `error.meta.reason=instance_required` |

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
| Properties | `instance` (string or null) and `node` (string or null). |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleListCommandTest.php` | CLI filter forwarding, JSON envelope shape, human table with last-run summary, empty states, and gateway/WireGuard failure passthrough. |
| `apps/gateway/tests/Feature/Http/Api/ScheduleInstanceOwnershipTest.php` | Explicit instance list filtering and ambiguous bare-selector rejection. |

Activity logging assertions remain a coverage gap until focused tests land.
