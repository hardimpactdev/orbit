# Technical Contract: `orbit schedule:logs <name> [--app=<app>] [--node=<node>] [--run=<id>] [--lines=<count>] [--json]`

[Back to public `schedule-logs` documentation.](../schedule-logs.md)

**Owner:** `schedule`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect schedule run history for the selected scope.

## Signature

```bash
orbit schedule:logs <name> [--app=<app>] [--node=<node>] [--run=<id>] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required.` | `Never.` | `None.` | Existing visible schedule slug. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may inspect. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or app node the caller may inspect. |
| `run` | `--run` | `Optional.` | `Never.` | `latest run` | Positive integer run id for the selected schedule. |
| `lines` | `--lines` | `Optional.` | `Never.` | `renderer default` | Positive integer line limit. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Authorization By Caller Role

All authenticated caller roles use the same gateway-owned access policy. App-node callers may read visible schedule run history when authorized; `schedule:logs` never grants write permission.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt; missing required input and invalid filters fail according to the shared invocation model.

## Behavior Contract

### Run History Read Rules

- Reads one schedule from gateway configuration by name and optional app or node disambiguation.
- Reads one durable run-history record for that schedule.
- Defaults to the latest run when `--run` is absent.
- Applies the line limit independently to captured stdout and stderr.
- Does not inspect live Orbit Scheduler state or scheduler-captured stdout/stderr that has not yet been reported as run history.

### Scope Boundaries

`schedule-logs` must not create, update, remove, run, fix, adopt, or apply schedules. It must not stream live process manager (Supervisor) logs directly. Live scheduler state and run-history hook drift belong to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-logs_output-render_human.md)
- [JSON renderer](6.2_schedule-logs_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | The name, filter, run id, or line limit is missing, malformed, unsupported, or mutually exclusive. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect schedule run history for the selected scope. | `error.code=authorization_failed` |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |
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
| Properties | `name` (string), `app` (string or null), `node` (string or null), `run` (integer or null), and `lines` (integer or null). No captured stdout, stderr, command text, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleLogsCommandTest.php` | Command contract for lookup, filter validation, run selection, line limiting, gateway authorization, read-only boundary, failure codes, and doctor boundary. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, run-history lookup rules, captured output mapping, and log line limiting. |
