# Agent IDE Commands

Agent IDE commands communicate with an app or workspace's effective agent IDE
adapter. They provide Orbit-native command surfaces for developer and agent
collaboration without making the adapter the owner of Orbit app, workspace,
process, node, or tool state.

The Agent IDE command family owns the `agent-ide:*` command prefix.

## State Ownership

The `agent-ide` command domain does not own a state family. It consumes adapter
configuration and active-session capabilities owned by other families.

[`doctor --family=node`](../1_node/node-doctor.md) owns the Agent IDE defaults
configured at the node level. [`doctor --family=app`](../5_app/app-doctor.md) owns
the Agent IDE settings configured at the app level, and app runtime health.
[`doctor --family=workspace`](../6_workspace/workspace-doctor.md) owns
workspace state and workspace artifacts. [`doctor --family=process`](../7_process/process-doctor.md)
owns crash-event policy and history that may trigger Agent IDE notifications.
[`doctor --family=tool`](../3_tool/tool-doctor.md) owns managed adapter server
tool lifecycle, such as `opencode-server` and `polyscope-server`.

There is no `doctor --family=agent-ide` contract.

## Domain Rules

These rules define the Agent IDE command domain and its authorization model.

- Agent IDE defaults are gateway configuration owned by nodes and apps.
- `node:agent-ide` defines the node default.
- `app:agent-ide` defines the app override.
- The effective adapter for an app or workspace resolves workspace setting
  first when a future workspace override exists, then app setting, then owning
  node default, then no configured adapter.
- Agent IDE adapters are gateway-registered extension points. Core adapter
  names are `opencode` and `polyscope`; installed Orbit extensions may register
  more adapters.
- Agent IDE commands may communicate with an adapter's active session, but they
  must not create sessions unless a future command explicitly owns that
  behavior.
- Messaging commands are best-effort communication. They do not create or
  repair app/workspace state and do not replace process crash-event history.
- All callers use Agent IDE commands as gateway clients. The gateway
  authenticates the WireGuard peer and authorizes the resolved app or workspace.
  Locally gathered context (current app, workspace, paths) can help resolve
  defaults, but it is not authorization.

## Commands

The Agent IDE family provides the following commands.

1. [`orbit agent-ide:message [message]`](1_agent-ide-message/agent-ide-message.md)

## Related

- [`orbit node:agent-ide [name] [agent_ide]`](../1_node/10_node-agent-ide/node-agent-ide.md)
- [`orbit app:agent-ide [app] [agent_ide]`](../5_app/9_app-agent-ide/app-agent-ide.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- [`doctor --family=app`](../5_app/app-doctor.md)
- [`doctor --family=workspace`](../6_workspace/workspace-doctor.md)
- [`doctor --family=process`](../7_process/process-doctor.md)
- [`doctor --family=tool`](../3_tool/tool-doctor.md)
