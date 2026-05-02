# `orbit tool:stop <tool>`

[Back to Tool commands.](../README.md)

Stop a managed tool service.

`tool:stop` changes a managed tool's expected lifecycle state to `installed` and
stops the node-side service through the gateway. The tool remains installed and
managed.

## Usage

```bash
orbit tool:stop <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:stop redis --node=app-1
orbit tool:stop redis --app=docs
orbit tool:stop redis --node=app-1 --json
```

## Arguments And Options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:stop`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a stop action.
3. Updates gateway intent so the expected state is `installed`.
4. Stops the tool through its lifecycle backend on the target node.
5. Reports the resulting state.

## Output

Human output reports the stop action and resulting intended state.

JSON output returns the tool entity under `success.data.tool`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered and managed for the resolved node.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

- [`tool:start`](../5_tool-start/tool-start.md) - start a managed tool service
- [`tool:restart`](../7_tool-restart/tool-restart.md) - restart a managed tool service
- [`doctor --family=tool`](../tool-doctor.md) - verify the installed expectation

## Technical Contract

See [`tool-stop` technical contract](technical/1_tool-stop.md).
