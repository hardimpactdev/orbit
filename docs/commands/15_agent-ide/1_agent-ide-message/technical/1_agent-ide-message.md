# Technical Contract: `orbit agent-ide:message [message]`

[Back to public `agent-ide:message` documentation.](../agent-ide-message.md)

**Owner:** `agent-ide`.

**Effects:** `write`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../../1_node/README.md#local-caller-role) contract.
- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read and communicate with the
  resolved app or workspace.
- The resolved app or workspace has an effective Agent IDE adapter configured.
- The adapter is registered with the gateway-owned adapter registry.
- The adapter can resolve an active session for the target context.

## Signature

```bash
orbit agent-ide:message [message] [--stdin] [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `message` | `[message]` or stdin | Always. | `[message]` is present and `--stdin` is true. | None. | Non-empty UTF-8 text. Positional message trims surrounding whitespace; stdin preserves the body except for one trailing newline added by common shells. |
| `stdin` | `--stdin` | Never. | `[message]` is present. | `false`. | Reads message body from standard input. |
| `app` | `--app` | Required when neither `--workspace` nor local app/workspace context resolves a target. | `--workspace` is present. | Local app/workspace context when available. | Existing app name or hostname visible to the caller. |
| `workspace` | `--workspace` | Required when neither `--app` nor local app/workspace context resolves a target. | `--app` is present. | Current workspace context when the command runs from a workspace path. | Existing workspace name or hostname, resolved inside app scope when an app context is known. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`agent-ide:message` resolves the caller role before prompts, target resolution,
or adapter delivery.

| Caller role | Behavior |
| --- | --- |
| `control` | Resolves input locally, then calls the gateway over HTTPS through WireGuard for authorization and adapter delivery. |
| `gateway` | Executes gateway-owned target resolution, authorization, effective adapter resolution, and adapter delivery locally. |
| `app` | May infer local app/workspace context, then calls the gateway over HTTPS through WireGuard. App-node context is target-resolution help only; the gateway still authorizes the message. |
| `unknown` | Invalid local context. Fail before prompts, gateway requests, or adapter delivery. |

All valid caller roles use the same gateway-owned authorization and adapter
registry. The CLI caller must not use a local adapter manifest or app-node local
state as authority.

## Input Mode Contracts

- [Interactive input mode](5.1_agent-ide-message_input-mode_interactive.md)
- [Non-interactive input mode](5.2_agent-ide-message_input-mode_non-interactive.md)

## Input Resolution

1. Resolve caller role before prompts or gateway requests.
2. Select the output renderer.
3. Validate mutually exclusive inputs:
   - `--app` and `--workspace` cannot be combined.
   - `[message]` and `--stdin` cannot be combined.
4. Resolve `message` from `[message]` or stdin.
5. Resolve target context:
   - `--workspace=<workspace>` selects a workspace context.
   - `--app=<app>` selects the app main context.
   - omitted target resolves from current workspace path, then current app path.
6. Authorize the current node identity for the resolved app or workspace.
7. Resolve the effective Agent IDE adapter:
   - future workspace-level override, when present;
   - app override;
   - owning node default;
   - no adapter.
8. Validate that the resolved adapter is registered with the gateway-owned
   adapter registry.
9. Ask the adapter for the active session for the resolved context.
10. Start the selected renderer and deliver the message through the adapter.

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
  in [Agent IDE Integrations](../../../../BLUEPRINT.md#agent-ide-integrations).
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
- Do not modify app source, workspace files, process definitions, node intent,
  tool intent, or local settings.
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

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required message/target input is missing, message is empty, `--app` and `--workspace` are combined, or `[message]` and `--stdin` are combined. | Failure before adapter delivery |
| Local context invalid | The local node role setting is unreadable or unsupported. | Failure before prompts or gateway requests |
| Gateway unavailable | A non-gateway caller cannot reach the configured gateway API. | Failure before adapter delivery |
| Authorization failed | The current node identity is not authorized for the resolved app/workspace. | Failure before adapter delivery |
| Target not found | No visible app/workspace matches the resolved target. | Failure before adapter delivery |
| No effective adapter | The target resolves to no configured Agent IDE adapter. | Failure before adapter delivery |
| No active session | The adapter is configured but cannot find an active session for the target. | Failure before delivery |
| Adapter delivery failed | The adapter failed while accepting the message. | Failure after delivery attempt |

The shared exit status policy applies: `0` for accepted delivery, `1` for
Orbit-handled command failures, and `2` only for console-runtime invalid usage
before Orbit can apply this command contract.

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
