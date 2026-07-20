# Technical Contract: `orbit schedule:show [name] [--instance=<project.instance>] [--node=<node>] [--json]`

[Back to public `schedule-show` documentation.](../schedule-show.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the selected schedule scope.

## Signature

```bash
orbit schedule:show [name] [--instance=<project.instance>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` or interactive schedule data table | `Required in non-interactive mode.` | `Never.` | `None.` | Schedule slug visible to the caller. |
| `app` | `--instance` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible eligible `app.instance`; a bare project is shorthand only when exactly one eligible instance is visible. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or node the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Schedule Detail Rules

- Reads one gateway schedule-configuration row visible to the caller.
- Resolves optional app disambiguation to one concrete instance at the
  gateway. Ambiguous bare project selectors fail instead of choosing one row.
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
| Instance required | No eligible instance exists for a bare project, or more than one eligible instance is visible. | `error.code=validation_failed`, `error.meta.reason=instance_required` |
| Schedule selector ambiguous | The name without a target filter matches more than one visible concrete target. | `error.code=validation_failed`, `error.meta.reason=schedule_selector_ambiguous` |

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
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php` | CLI schedule lookup and filter forwarding, validation before gateway contact, interactive name selection, `schedule.not_found` passthrough, and WireGuard failure surfacing. |
| `apps/gateway/tests/Feature/Http/Api/ScheduleAppInstanceOwnershipTest.php` | Explicit instance lookup and ambiguous bare app-selector rejection. |

Activity logging assertions remain a coverage gap until focused tests land.
