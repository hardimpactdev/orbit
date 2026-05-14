# Technical Contract: `orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]`

[Back to public `schedule-run` documentation.](../schedule-run.md)

**Owner:** `schedule`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to run the selected schedule.

## Signature

```bash
orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` or interactive schedule data table | `Required in non-interactive mode.` | `Never.` | `None.` | Existing visible schedule slug. |
| `app` | `--app` | `Optional.` | `Forbidden with `node`.` | `None.` | Visible active app the caller may run schedules for. |
| `node` | `--node` | `Optional.` | `Forbidden with `app`.` | `None.` | Visible active gateway or app node the caller may run schedules for. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

`schedule:run` performs one Orbit Scheduler tick on the resolved target node: fetch the node's schedule list from the gateway, evaluate which schedules are due in the current minute, and fire them. The same logic runs inside the resident `orbit-scheduler` daemon at least once per minute. Operators use `schedule:run` to fire a tick on demand for testing, troubleshooting, or recovery; the daemon's loop is the steady-state path.

When called with a schedule name, `schedule:run [name]` force-runs that one schedule regardless of its interval and records the resulting run.

### One-Off Execution Rules

- Resolves one schedule from gateway configuration by name and optional app or node disambiguation.
- Force-runs the schedule's stored command or script once on the target node, regardless of whether the schedule is currently due.
- Runs app-scoped schedules in the app context on the owning app node.
- Runs node-scoped schedules in the selected node context.
- Does not change the recurring interval or enabled state.

### Run History Rules

- Creates a durable run-history record for the one-off run.
- Records started and finished timestamps, status, process result, and captured
  output.
- Stores scheduled process output in run history whether the scheduled process succeeds or fails.

### Scope Boundaries

`schedule-run` must not create, update, remove, fix, adopt, or re-render schedule configuration. It must not infer schedule definitions from scheduler-side state. Scheduler drift belongs to [`schedule-doctor.md`](../../schedule-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_schedule-run_output-render_human.md)
- [JSON renderer](6.2_schedule-run_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Schedule not found | No visible schedule matches the name and filters. | `error.code=schedule.not_found` |
| Run failed | The scheduled command or script exits non-zero. | `error.code=schedule.run_failed` |
| Run history write failed | The gateway could not persist the run-history record. | `error.code=schedule.history_write_failed` |

The command follows the shared exit status policy. Scheduled process failure is
an Orbit-handled command failure; the scheduled process result is captured in
the renderer data.

## Doctor Relationship

One-off runs are gateway history. [`schedule-doctor.md`](../../schedule-doctor.md) verifies Orbit Scheduler liveness and per-schedule run history, not whether an individual manual run succeeded.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
manual schedule run attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /schedules/{name}/run` |
| Effect | `write` |
| Subject | `Schedule` when the schedule is resolved and visible; `none` for not-found, validation, or authorization failures before a schedule can be logged. |
| Properties | `name` (string), `app` (string or null), and `node` (string or null). No captured stdout, stderr, command text, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php` | Command contract for lookup, filter validation, gateway authorization, execution request shape, run-history persistence, non-zero scheduled exit handling, failure codes, and doctor boundary. |
| `tests/Unit/Services/Schedules/ScheduleCommandContractTest.php` | Shared schedule DTO shape, run request mapping, captured output mapping, and run-history entity mapping. |
