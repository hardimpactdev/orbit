# `orbit tool:restart <tool>`

[Back to Tool commands.](../README.md)

Restart a managed tool service.

`tool:restart` cycles a managed tool's service without changing version intent
or rerunning the tool's setup flow.

## Usage

```bash
orbit tool:restart <tool> [--node=<node>] [--app=<app>] [--json]
```

## Examples

```bash
orbit tool:restart redis --node=app-1
orbit tool:restart redis --app=docs
orbit tool:restart redis --node=app-1 --json
```

## Arguments And Options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:restart`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a restart path.
3. Restarts the tool through its lifecycle backend on the target node.
4. Preserves existing gateway configuration and version intent.
5. Reports the resulting state.

The command does not repair divergent configuration. Use
[`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) or `doctor --family=tool --fix`
for configuration convergence.

## Output

Human output reports the restart action and resulting intended state.

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

- [`tool:reload`](../11_tool-reload/tool-reload.md) - reload configuration when supported
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup without changing version
- [`doctor --family=tool`](../tool-doctor.md) - verify tool drift

## Technical Contract

See [`tool-restart` technical contract](technical/1_tool-restart.md).
