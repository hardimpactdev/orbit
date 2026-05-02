# Technical Contract: `orbit process:logs [name]`

[Back to public `process:logs` documentation.](../process-logs.md)

**Owner:** `process`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller role is `control`, `gateway`, or `app`; `unknown` callers are
  denied before prompts or side effects.
- The current node identity is authorized to read process logs for the target
  app or workspace context.
- Log access requires gateway SSH reachability to the owning app node.

## Signature

```bash
orbit process:logs [name] [--app=<app>] [--workspace=<workspace>] [--follow] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the owning app. |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may read. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace of the selected app that the caller may read. |
| `follow` | `--follow` | Optional. | Never. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer count of historical log lines to read before streaming or returning. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

## Caller Role Behavior

`process:logs` follows the
[Process Caller Role Rule](../../README.md#process-caller-role-rule). It is a
runtime log command, so app-node callers are valid when authorized for the
resolved app or workspace context.

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Forward the log read to the gateway API when authorized. |
| `gateway` | `valid` | Read logs from the owning app node through `RemoteShell`. |
| `app` | `valid` | Resolve local app or workspace context when available, then call the gateway API. The gateway reads logs; the app-node CLI does not read journald directly. |
| `unknown` | `invalid` | Deny before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-logs_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-logs_input-mode_non-interactive.md)

## Behavior Contract

### Process Log Streaming Rules

1. Resolve caller role. Deny `unknown` callers before prompts or side effects.
2. Resolve caller authorization, target app or workspace context, and process
   definition.
3. Derive the runtime-unit identity for the selected context.
4. Open a log read through the gateway on the owning app node.
5. Read up to `lines` historical lines.
6. If `--follow` is present, keep streaming appended log lines until the
   operator interrupts the command.
7. Render the selected output.

`process:logs` does not mutate process intent, runtime state, or durable
lifecycle events.

## Renderer Contracts

- [Human renderer](6.1_process-logs_output-render_human.md)
- [JSON renderer](6.2_process-logs_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The caller role is `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Validation failed | Process, app, or workspace context is missing, invalid, or ambiguous in non-interactive input mode; `--lines` is invalid; or `--json` is combined with `--follow`. | Failure (`error.code=validation_failed`). |
| Authorization failed | The caller cannot read process logs for the target context. | Failure (`error.code=authorization_failed`). |
| Process not found | The named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| Log read failed | The gateway cannot read logs from the owning app node runtime backend. | Failure (`error.code=process.log_read_failed`). |

## Doctor Relationship

`process:logs` reads runtime backend logs. [`process-doctor.md`](../../process-doctor.md)
owns verification and repair of the runtime-unit artifacts and event notifier
material that help produce process observability.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessLogsCommandTest.php` | Command contract for context resolution, app-node caller allowance through the gateway, unknown-role denial before prompts or side effects, bounded log reads, follow-mode streaming, line count validation, `--json` with `--follow` rejection, no process intent mutation, no direct app-node journald read, log read failure, and authorization failure. |
| `tests/Feature/Commands/Processes/ProcessLogsInputContractTest.php` | Required inputs, app and workspace resolution, process resolution, line count validation, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
