# `orbit tool:start <tool>`

[Back to Tool commands.](../README.md)

Start a managed tool service.

`tool:start` changes a managed tool's expected lifecycle state to `running` and
starts the node-side service through the gateway.

## Usage

```bash
orbit tool:start <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:start redis --node=app-1
orbit tool:start redis --app=docs
orbit tool:start redis --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

Run this command to set a managed tool's expected state to `running` and start it through the gateway.

`tool:start`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a start action.
3. Updates gateway configuration so the expected state is `running`.
4. Starts the tool through its lifecycle backend on the target node.
5. Reports the resulting state.

## Output

Use `--json` to get a machine-readable result; omit it for a summary of the start action.

Human output reports the start action and resulting intended state.

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

Use these commands for related tool lifecycle actions.

- [`tool:stop`](../6_tool-stop/tool-stop.md) - stop a managed tool service
- [`tool:restart`](../7_tool-restart/tool-restart.md) - restart a managed tool service
- [`doctor --family=tool`](../tool-doctor.md) - verify the running expectation

## Technical Contract

See [`tool-start` technical contract](technical/1_tool-start.md).
