# `orbit node:agent-ide [name] [agent_ide]`

[Back to Nodes commands.](../README.md)

Set the default agent IDE adapter for a node.

Stores the default agent IDE for the node, used by apps and workspaces on that node
when they do not define an app-level override. Used to make agent IDE messaging
and crash-notification workflows work by default on a development app node.

## Usage

```bash
orbit node:agent-ide [name] [agent_ide] [--json]
```

## Examples

```bash
orbit node:agent-ide app-1 opencode
orbit node:agent-ide app-1 none
orbit node:agent-ide app-1 polyscope --json
```

## Arguments and options

- `name`: node name. Must exist in gateway node configuration.
- `agent_ide`: agent IDE input value. Core adapter names are `opencode` and
  `polyscope`. Installed Orbit extensions may register additional adapters with
  the gateway. `none` is a reserved node-scope token that clears the node
  default; it is not an adapter. The gateway is the sole authority on which
  adapter names are accepted. `inherit` is not valid here because nodes are the
  root of the agent IDE inheritance chain; clearing the node default is the
  node-scope way to let the chain resolve to no configured agent IDE.
- `--json`: Output JSON.

## What Happens

`node:agent-ide` writes the adapter you choose into gateway node
configuration. Gateway callers execute locally; configured control callers
forward the request to the gateway over HTTPS through WireGuard.

The command:

1. Validates that the target node exists in gateway configuration.
2. Validates that the requested adapter is supported.
3. Stores the adapter as the node-level default when it differs from the current
   value.
4. Reports whether the adapter was set or was already the current value.

`node:agent-ide` is a pure configuration write. Apps and current workspaces
resolve their effective agent IDE per-event using the current inheritance chain
(`app → node → none`); the architecture reserves a future workspace-level
override slot above app scope. A change to the node default is naturally picked
up at the next consumer-side resolution event without a push from this command.
Workspace cleanup remains app-scoped: changing a node default does not prune
workspaces for inheriting apps. Run
[`app:prune`](../../5_app/7_app-prune/app-prune.md) for each affected app when
stale workspace cleanup is wanted after a node-default change.

`node:agent-ide` does not:

- Create an agent IDE session.
- Grant node access or alter node transport.
- Override the agent IDE settings configured at the app level.
- SSH into the target node.
- Trigger downstream session restart or app-level invalidation.
- Notify running agent-IDE sessions or invalidate app-level or workspace-level
  overrides.
- Remove or prune workspaces for apps that inherit the node default.

### Recovery from doctor warnings

When `doctor --family=node` reports `node.agent_ide_default_invalid`, the
stored node-level adapter is missing or unsupported. Doctor reports this only;
it does not choose or clear an adapter automatically. Recover by running
`orbit node:agent-ide <node> <supported-adapter>` or
`orbit node:agent-ide <node> none`.

## Output

Human output summarizes the configured default and distinguishes a newly set
value from an already-matching value.

JSON output returns the node name, the configured adapter, its source, and the
command action under a machine-readable output. See the
[JSON renderer contract](technical/6.2_node-agent-ide_output-render_json.md) for the exact
payload shape.

## Requirements

- Must run on the gateway host or from a configured control node.
- Control callers must be authorized to update node registry configuration.
- App-node callers are rejected before prompts or side effects.
- The target node must exist in gateway configuration.
- The adapter must be present in the gateway-owned adapter registry. Adapters
  shipped by installed Orbit extensions become valid only after the extension
  has registered them with the gateway.

## Related Commands

Use these commands alongside `node:agent-ide` to manage node configuration and verify results.

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`node:update`](../7_node-update/node-update.md) — update node metadata
- [`node:default`](../9_node-default/node-default.md) — set the local default development app node
- [`app:prune`](../../5_app/7_app-prune/app-prune.md) — prune stale workspaces for an app
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:agent-ide` technical contract](technical/1_node-agent-ide.md).
