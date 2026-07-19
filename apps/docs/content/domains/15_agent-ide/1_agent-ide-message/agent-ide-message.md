# `orbit agent-ide:message [message]`

[Back to Agent IDE commands.](../README.md)

Send a message to the active Agent IDE session for an app or workspace.

`agent-ide:message` resolves an app or workspace target, resolves its effective
Agent IDE adapter, and delivers a user-provided message to the active adapter
session for that context. The command is useful for humans, LLM agents, and
automation that need to ask the active IDE session to inspect or act on the
current app or workspace.

## Usage

```bash
orbit agent-ide:message [message] [--app=<app>] [--workspace=<workspace>] [--stdin] [--json]
```

## Examples

```bash
orbit agent-ide:message "Run the focused test for the current change"
orbit agent-ide:message "Inspect the queue worker logs" --app=docs
orbit agent-ide:message "Review the open diff" --workspace=feature-docs
git diff | orbit agent-ide:message --stdin --workspace=feature-docs
orbit agent-ide:message "Summarize the failing request" --app=docs --json
```

## Behavior Summary

These subsections describe how the command resolves its target, finds the active session, and delivers the message.

### Message input

Accepts the message from `[message]` or from standard input when `--stdin` is present.

### Target resolution

Resolves the target context from explicit options or the current directory. `--workspace=<workspace>` targets a workspace and `--app=<app>` targets the app's main context. With no explicit target, Orbit resolves the current workspace or app from the current directory when possible.

Workspace and path-derived targets are `app-dev` only. An `app-prod` caller or
target is rejected before adapter lookup or delivery. Messages to the app's
main context remain available when normally authorized.

### Adapter resolution

Resolves the effective Agent IDE adapter from workspace setting when a future workspace override exists, then app override, then owning node default.

### Session lookup

Finds the active adapter session for the resolved context. The adapter owns session lookup, but lookup must stay inside the resolved app/workspace scope.

### Delivery

Sends the message through the resolved adapter and reports delivery success or adapter failure.

### No mutations

Does not create an Agent IDE session and does not mutate Orbit app, workspace, process, node, or tool state.

## Requirements

- The CLI can reach the Orbit gateway over WireGuard.
- The gateway authorizes the calling WireGuard peer for the resolved app or workspace.
- The app or owning node has an effective Agent IDE adapter configured.
- The adapter can resolve an active session for the target context.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human**: Reports the target context, adapter, and delivery result.
- **JSON**: Returns the same result as machine-readable output. See the [JSON renderer contract](technical/6.2_agent-ide-message_output-render_json.md) for the exact shape.

## Related

- [`orbit node:agent-ide [name] [agent_ide]`](../../1_node/10_node-agent-ide/node-agent-ide.md)
- [`orbit app:agent-ide [app] [agent_ide]`](../../5_app/9_app-agent-ide/app-agent-ide.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)

***

**Technical Contract:** [technical/1_agent-ide-message.md](technical/1_agent-ide-message.md)
