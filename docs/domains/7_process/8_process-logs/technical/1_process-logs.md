# Technical Contract: `orbit process:logs [name]`

[Back to public `process:logs` documentation.](../process-logs.md)

**Owner:** `process`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:logs` on the target app's owning node.
- Log access requires gateway reachability to the owning node.

## Signature

```bash
orbit process:logs [name] [--app=<app>] [--workspace=<workspace>] [--follow] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the owning app. |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:logs`. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:logs`. |
| `follow` | `--follow` | Optional. | Never. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer count of historical log lines to read before streaming or returning. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-logs_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-logs_input-mode_non-interactive.md)

## Behavior Contract

### Process Log Streaming Rules

1. Resolve target app or workspace context from supplied input or local context, and resolve the process definition.
2. Send the request to the gateway, which validates the authenticated peer's authorization.
3. Derive the runtime-unit identity for the selected context.
4. Open a log read through the gateway on the owning node.
5. Read up to `lines` historical lines.
6. If `--follow` is present, keep streaming appended log lines until the operator interrupts the command.
7. Render the selected output.

`process:logs` does not mutate process configuration, runtime state, or durable lifecycle events.

## Renderer Contracts

- [Human renderer](6.1_process-logs_output-render_human.md)
- [JSON renderer](6.2_process-logs_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | The named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| Log read failed | The gateway cannot read logs from the owning node process manager. | Failure (`error.code=process.log_read_failed`). |

## Doctor Relationship

`process:logs` reads Supervisor logs. [`process-doctor.md`](../../process-doctor.md) owns verification and repair of the runtime-unit artifacts and event notifier material that help produce process observability.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /processes/{name}/log` |
| Effect | `read` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, or authorization failures before the app can be logged. |
| Properties | `app` (string or null) and `workspace` (string or null). No captured stdout, stderr, log payload, backend command text, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessLogsCommandTest.php` | Command contract for `process:logs` behavior; see detail below. |
| `tests/Feature/Commands/Processes/ProcessLogsInputContractTest.php` | Required inputs, app and workspace resolution, process resolution, line count validation, and `--json` input-mode selection. |

`ProcessLogsCommandTest.php` covers context resolution, grant authorization,
missing-grant denial, bounded log reads, follow-mode streaming, line
count validation, `--json` with `--follow` rejection, no configuration mutation,
no direct Supervisor log read, log read failure, and authorization failure.

Renderer and input-mode test mapping lives in the split companion files.
