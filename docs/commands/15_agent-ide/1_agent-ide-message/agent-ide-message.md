# `orbit agent-ide:message [message]`

[Back to Agent IDE commands.](../README.md)

**Purpose:** Send a message to the active Agent IDE session for an app or
workspace.

**Description:** Resolves an app or workspace target, resolves its effective
Agent IDE adapter, and delivers a user-provided message to the active adapter
session for that context.

The command is useful for humans, LLM agents, and automation that need to ask
the active IDE session to inspect or act on the current app or workspace.

## Usage

```bash
orbit agent-ide:message [message] [--app=<app>] [--workspace=<workspace>] [--json]
```

## Examples

```bash
orbit agent-ide:message "Run the focused test for the current change"
orbit agent-ide:message "Inspect the queue worker logs" --app=docs
orbit agent-ide:message "Review the open diff" --workspace=feature-docs
orbit agent-ide:message "Summarize the failing request" --app=docs --json
```

## Behavior

- Resolves a target context before sending the message:
  - `--workspace=<workspace>` targets a workspace;
  - `--app=<app>` targets the app's main context;
  - with no explicit target, Orbit resolves the current workspace or app from
    the current directory when possible.
- Resolves the effective Agent IDE adapter from workspace setting when a future
  workspace override exists, then app override, then owning node default.
- Finds the active adapter session for the resolved context.
- Sends the message through the resolved adapter.
- Reports delivery success or adapter failure.

`agent-ide:message` does not create an Agent IDE session and does not mutate
Orbit app, workspace, process, node, or tool state.

## Output

Human output reports the target context, adapter, and delivery result.

JSON output returns the same result in the shared `success` or `error` envelope.
See the [JSON renderer contract](technical/6.2_agent-ide-message_output-render_json.md)
for the exact shape.

## Requirements

- The caller role can be resolved.
- The caller can reach the Orbit gateway when not running on the gateway.
- The current node identity is authorized for the resolved app or workspace.
- The app or owning node has an effective Agent IDE adapter configured.
- The adapter can resolve an active session for the target context.

## Related

- [`orbit node:agent-ide [name] [agent_ide]`](../../1_node/10_node-agent-ide/node-agent-ide.md)
- [`orbit app:agent-ide [app] [agent_ide]`](../../5_app/9_app-agent-ide/app-agent-ide.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)

See [`agent-ide:message` technical contract](technical/1_agent-ide-message.md).
