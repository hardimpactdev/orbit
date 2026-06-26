# Technical Contract: `orbit process:logs [name]`

[Back to public `process:logs` documentation.](../process-logs.md)

**Owner:** `process`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:logs` on the process owning node.
- Log access requires gateway reachability to the owning node.

## Signature

```bash
orbit process:logs [name] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--follow] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the resolved owning scope. |
| `node` | `--node` | Required when reading logs for a node-owned process. | `app` or `workspace` is present. | None. | Must resolve to a node that grants `process:logs`. |
| `app` | `--app` or app context | Required unless `node` is supplied or `workspace` resolves the app. | `node` is present. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:logs`. |
| `workspace` | `--workspace` or workspace context | Optional. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:logs`; pass `--app` when the workspace name is ambiguous. |
| `follow` | `--follow` | Optional. | Never. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer. How many prior log lines to read before streaming or returning. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-logs_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-logs_input-mode_non-interactive.md)

## Behavior Contract

### Process Log Streaming Rules

1. Resolve target node, app, or workspace context from supplied input or local context, and resolve the process definition.
2. Send the request to the gateway, which validates the authenticated peer's authorization.
3. Derive the runtime-unit identity for the selected context.
4. Open a log read through the gateway on the owning node.
5. Read up to `lines` prior log lines.
6. For bounded service process log reads, include process-owned connection metadata: definition name, version, service name, endpoint, and credential field names. Credential values are excluded.
7. If `--follow` is present, keep streaming appended log lines until the operator interrupts the command.
8. Render the selected output.

`process:logs` does not mutate process configuration, runtime state, or durable lifecycle events.

## Renderer Contracts

- [Human renderer](6.1_process-logs_output-render_human.md)
- [JSON renderer](6.2_process-logs_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | The named process does not exist for the resolved context. | Failure (`error.code=process.not_found`). |
| Invalid context | `--node` is combined with `--app` or `--workspace`, or no node/app/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Log read failed | The gateway cannot read logs from the owning node process manager. | Failure (`error.code=process.log_read_failed`). |

## Doctor Relationship

`process:logs` reads logs from the selected runtime backend for process runtime units.
[`process-doctor.md`](../../process-doctor.md) owns verification and
repair of the runtime-unit artifacts and event notifier material that help
produce process observability.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /processes/{name}/log` |
| Effect | `read` |
| Subject | Resolved `Node` for node-owned processes or `App` for app/workspace contexts; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `app` (string or null), and `workspace` (string or null). No captured stdout, stderr, log payload, backend command text, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php` | Gateway bounded process log reads for app, workspace, and node contexts, managed-service metadata, unsupported-runtime validation, authorization failures, and log read failures. |
| `apps/gateway/tests/Feature/Http/Api/ProcessLogStreamControllerTest.php` | Gateway follow-stream log output for authorized process log reads. |
| `apps/cli/tests/Feature/Commands/Process/ProcessLogsCommandTest.php` | CLI `process:logs` bounded and follow-mode requests, node context forwarding, human output, JSON bounded output, and gateway/WireGuard failure passthrough. |
| `apps/cli/tests/Feature/Commands/Process/ProcessLogsCommandTest.php` | CLI `process:logs` missing-name validation, invalid line-count validation, and `--json` plus `--follow` rejection before opening gateway requests. |

`ProcessLogControllerTest.php`, `ProcessLogStreamControllerTest.php`, and `ProcessLogsCommandTest.php` cover context resolution, grant authorization,
missing-grant denial, bounded log reads, follow-mode streaming, line
count validation, `--json` with `--follow` rejection, no configuration mutation,
no direct backend log read, log read failure, and authorization failure.

Renderer and input-mode test mapping lives in the split companion files.
