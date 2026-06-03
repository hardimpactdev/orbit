# `orbit tool:start <tool>`

[Back to Tool commands.](../README.md)

Start the managed process related to a tool capability.

`tool:start` is a compatibility lifecycle command. Tools are node-level
capabilities; the lifecycle-managed unit is the related process. During the
migration, this command resolves the selected tool row to a related managed
process or legacy backend and starts that long-running unit through the gateway.

## Usage

```bash
orbit tool:start <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:start redis --node=app-1
orbit tool:start redis --app=docs
orbit tool:start redis --app=docs.app-1
orbit tool:start redis --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node.
- `--app`: Resolve the target node from an app selector. Accepts an app slug,
  app domain, or `<slug>.<node-tld>` selector.
- `--json`: Output JSON.

Target resolution uses this hierarchy:

1. `--app`, resolving the app's owning node.
2. `--node`.
3. Local `node:default`.
4. Self, using the gateway-known caller identity.

When both `--app` and `--node` are present, the app's owning node must match
the supplied node. Orbit does not select a node just because only one node
is visible.

## What Happens

Run this command to start the managed process related to a selected tool
capability through the gateway.

`tool:start`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a related lifecycle path.
3. Resolves the related managed process or transitional backend.
4. Starts the related long-running unit on the target node.
5. Reports the resulting state.

## Output

Use `--json` to get a machine-readable result; omit it for a summary of the start action.

Human output stays concise:

```text
Started <tool> on <node>.
```

Use `--json` for the machine-readable tool result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered and managed for the resolved node.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands for related compatibility lifecycle actions.

- [`tool:stop`](../6_tool-stop/tool-stop.md) - stop the related managed process
- [`tool:restart`](../7_tool-restart/tool-restart.md) - restart the related managed process
- [`doctor --family=tool`](../tool-doctor.md) - verify the tool capability expectation

## Technical Contract

See [`tool-start` technical contract](technical/1_tool-start.md).
