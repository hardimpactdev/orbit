# Technical Contract: `orbit agent-ide:message [message]`

[Back to public `agent-ide:message` documentation.](../agent-ide-message.md)

**Owner:** `agent-ide`.

**Effects:** `write`.

**Prerequisites:**
- The CLI can reach the Orbit gateway over WireGuard.
- The gateway authorizes the calling WireGuard peer to read and communicate
  with the resolved app or workspace.
- The resolved app or workspace has an effective Agent IDE adapter configured.
- The adapter is registered with the gateway-owned adapter registry.
- The adapter can resolve an active session for the target context.

## Signature

```bash
orbit agent-ide:message [message] [--app=<app>] [--workspace=<workspace>] [--stdin] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `message` | `[message]` or stdin | Always. | `[message]` is present and `--stdin` is true. | None. | Non-empty UTF-8 text. Positional message trims surrounding whitespace; stdin preserves the body except for one trailing newline added by common shells. |
| `stdin` | `--stdin` | Never. | `[message]` is present. | `false`. | Reads message body from standard input. |
| `app` | `--app` | Required when neither `--workspace` nor current-directory context resolves a target. | `--workspace` is present. | Current-directory app context when available. | Existing app name or hostname visible to the caller. |
| `workspace` | `--workspace` | Required when neither `--app` nor current-directory context resolves a target. | `--app` is present. | Current workspace context when the command runs from a workspace path. | Existing workspace name or hostname, resolved inside app scope when an app context is known. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_agent-ide-message_input-mode_interactive.md)
- [Non-interactive input mode](5.2_agent-ide-message_input-mode_non-interactive.md)

## Input Resolution

1. Select the output renderer.
2. Validate mutually exclusive inputs:
   - `--app` and `--workspace` cannot be combined.
   - `[message]` and `--stdin` cannot be combined.
3. Resolve `message` from `[message]` or stdin.
4. Resolve target context:
   - `--workspace=<workspace>` selects a workspace context.
   - `--app=<app>` selects the app main context.
   - omitted target resolves from current workspace path, then current app path.
5. Call the gateway. The gateway authorizes the WireGuard peer for the resolved
   app or workspace.
6. The gateway resolves the effective Agent IDE adapter:
   - future workspace-level override, when present;
   - app override;
   - owning node default;
   - no adapter.
7. The gateway validates that the resolved adapter is registered with the
   gateway-owned adapter registry.
8. The gateway asks the adapter for the active session for the resolved
   context.
9. Start the selected renderer and deliver the message through the adapter.

## Behavior Contract

### Target Resolution Rules

- Explicit `--workspace` wins over current-directory context.
- Explicit `--app` targets the app's main context and does not imply a
  workspace.
- Current-directory resolution prefers workspace context over parent app
  context.
- A workspace target includes its parent app in command results.
- If the requested target is hidden from the caller, return
  `authorization_failed` instead of leaking target existence.

### Effective Adapter Rules

- Resolve the effective adapter through the shared inheritance chain documented
  in [Agent IDE Integration](../../../../ARCHITECTURE.md#agent-ide-integration).
- `none` at app scope means the app explicitly disables Agent IDE messaging;
  fail with `no_effective_adapter`.
- A missing node/app default also fails with `no_effective_adapter`.
- Adapter support is gateway-owned. The command must not accept an adapter name
  merely because local client code knows about it.
- Adapter server lifecycle and credentials are tool-family concerns.

### Delivery Rules

- Deliver exactly the supplied message text after input-mode resolution.
- Stdin input is a first-class automation path for long prompts and generated
  context. It does not change target resolution, authorization, adapter
  selection, or renderer behavior.
- Do not modify app source, workspace files, process definitions, node
  configuration, tool configuration, or local settings.
- Do not create a new Agent IDE session.
- Do not retry indefinitely. A single adapter delivery failure is a command
  failure with adapter context.
- A successful delivery means the adapter accepted the message for the active
  session; it does not guarantee that the IDE completed the requested work.
- Adapter session lookup is adapter-specific. App-context delivery may resolve
  the most recent active app-owned session when the adapter represents sessions
  as workspaces, but it must not cross app authorization boundaries.

### Scope Boundaries

`agent-ide:message` must not:
- Create or repair app, workspace, process, node, or tool state.
- Create Agent IDE sessions or workspaces.
- Prune workspaces.
- Trigger process lifecycle changes.
- Replace process crash-event history or crash notification policy.
- Store the message in durable Orbit history unless generic activity logging is
  enabled for command execution.

## Renderer Contracts

- [Human renderer](6.1_agent-ide-message_output-render_human.md)
- [JSON renderer](6.2_agent-ide-message_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Target not found | No visible app/workspace matches the resolved target. | Failure before adapter delivery |
| No effective adapter | The target resolves to no configured Agent IDE adapter. | Failure before adapter delivery |
| No active session | The adapter is configured but cannot find an active session for the target. | Failure before delivery |
| Adapter delivery failed | The adapter failed while accepting the message. | Failure after delivery attempt |

The shared exit status policy applies: `0` for accepted delivery, `1` for
Orbit-handled command failures, and `2` only for console-runtime invalid usage
before Orbit can apply this command contract.

## Activity Logging

The gateway API endpoint emits one activity entry for authorized message
delivery attempts. Activity properties must never include the full message body,
adapter credentials, raw adapter output, or secrets.

| Field | Value |
| --- | --- |
| Type | `api:POST /agent-ide/message` |
| Effect | `write` |
| Subject | `App` for app-target delivery; `Workspace` for workspace-target delivery; `none` when no authorized target is resolved. |
| Properties | `target_app`, `target_workspace`, `adapter`, `source`, `delivery_status`, and `failure_code` when delivery fails. |
| Description | `Agent IDE message sent to {target} through {adapter}` or `Agent IDE message failed for {target} through {adapter}`. |

## Doctor Relationship

- `agent-ide:message` is communication, not convergence.
- `doctor --family=node` verifies node-owned Agent IDE defaults when supported.
- `doctor --family=app` verifies app-owned Agent IDE configuration when
  supported.
- `doctor --family=workspace` owns workspace state that adapters may reference.
- `doctor --family=process` owns crash-event policy and history that may trigger
  Agent IDE notifications.
- `doctor --family=tool` owns managed adapter server lifecycle and credentials.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/AgentIde/AgentIdeMessageCommandTest.php` | Target resolution from explicit app, explicit workspace, and cwd context; caller-role behavior; authorization failures; effective adapter resolution; stdin message delivery; no-adapter failure; no-active-session failure; adapter delivery failure; accepted delivery success; read-only guarantee for Orbit state; and no session creation. |

Input-mode-specific test mapping lives in:

- [`5.1_agent-ide-message_input-mode_interactive.md`](5.1_agent-ide-message_input-mode_interactive.md#test-mapping)
- [`5.2_agent-ide-message_input-mode_non-interactive.md`](5.2_agent-ide-message_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_agent-ide-message_output-render_human.md`](6.1_agent-ide-message_output-render_human.md#test-mapping)
- [`6.2_agent-ide-message_output-render_json.md`](6.2_agent-ide-message_output-render_json.md#test-mapping)
