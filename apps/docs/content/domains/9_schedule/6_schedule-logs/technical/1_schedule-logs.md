# Technical Contract: `orbit schedule:logs [name] [--instance=<app.instance>] [--node=<node>] [--run=<id>] [--lines=<count>] [--json]`

[Back to public `schedule-logs` documentation.](../schedule-logs.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect schedule run history for
  the selected concrete instance or node scope.

## Signature

```bash
orbit schedule:logs [name] [--instance=<app.instance>] [--node=<node>] [--run=<id>] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` or interactive schedule data table | `Required in non-interactive mode.` | `Never.` | `None.` | Existing visible schedule slug. |
| `instance` | `--instance` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible eligible `app.instance`; a bare project is shorthand only when exactly one eligible instance is visible. |
| `node` | `--node` | `Optional.` | `Forbidden with `instance`.` | `None.` | Visible active gateway or node the caller may inspect. |
| `run` | `--run` | `Optional.` | `Never.` | `latest run` | Positive integer run id for the selected schedule. |
| `lines` | `--lines` | `Optional.` | `Never.` | `renderer default` | Positive integer line limit. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Run History Read Rules

- Reads one schedule from gateway configuration by name and optional concrete
  instance or node disambiguation.
- Reads one durable run-history record for that schedule.
- Defaults to the latest run when `--run` is absent.
- Applies the line limit independently to captured stdout and stderr.
- Does not inspect live Orbit Scheduler state or scheduler-captured stdout/stderr that has not yet been reported as run history.

### Scope Boundaries

`schedule-logs` must not create, update, remove, run, fix, adopt, or apply
schedules. It must not stream live scheduler container logs directly. Live
scheduler state and run-history hook drift belong to
[`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-logs_output-render_human.md)
- [JSON renderer](6.2_schedule-logs_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |
| Instance required | No eligible instance exists for a bare project, or more than one eligible instance is visible. | `error.code=validation_failed`, `error.meta.reason=instance_required` |
| Run not found | No run-history record matches the selected schedule and run id. | `error.code=schedule.run_not_found` |
| Log read failed | The gateway could not read stored run output. | `error.code=schedule.log_read_failed` |

## Doctor Relationship

`schedule-logs` explains past schedule behavior from gateway history. [`schedule-doctor.md`](../../schedule-doctor.md) verifies current Orbit Scheduler state against gateway configuration.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
schedule run-log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /schedules/{name}/logs` |
| Effect | `read` |
| Subject | `Schedule` when the schedule is resolved and visible; `none` for not-found, run-not-found, validation, or authorization failures before a schedule can be logged. |
| Properties | `name` (string), `instance` (string or null), `node` (string or null), `run` (integer or null), and `lines` (integer or null). No captured stdout, stderr, command text, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Schedule/ScheduleLogsCommandTest.php` | CLI lookup and filter forwarding, validation before gateway contact, interactive schedule selection, `schedule.run_not_found` passthrough, and WireGuard failure surfacing. |
| `apps/gateway/tests/Feature/Http/Api/ScheduleInstanceOwnershipTest.php` | Shared concrete-instance schedule lookup and ambiguous bare-selector rejection. |

There is no gateway-side coverage for this command contract: no gateway API or SDK contract test is linked for this command yet. The linked CLI test proves the mapped CLI behavior above; API behavior, activity logging, and authorization assertions remain coverage gaps until focused tests land.
