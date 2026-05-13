# `orbit tool:reload [tool]`

[Back to Tool commands.](../README.md)

Reload a managed tool's configuration without a full restart.

`tool:reload` asks a reload-capable tool to apply current configuration without
cycling the service. It is lower impact than `tool:restart` when the tool
definition supports it.

## Usage

```bash
orbit tool:reload [tool] [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:reload caddy --node=app-1
orbit tool:reload caddy --app=docs
orbit tool:reload caddy --node=app-1 --json
```

## Arguments And Options

- `tool`: Optional tool name. When omitted in interactive mode, Orbit prompts
  from reload-capable tools visible on the resolved node.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:reload`:

1. Resolves the target node and registered tool.
2. Verifies the tool definition declares reload support.
3. Runs the tool definition's reload action through the gateway.
4. Preserves gateway tool configuration and expected version.
5. Reports the reload result.

The command does not fall back to restart unless the tool definition explicitly
declares restart-as-reload behavior.

## Output

Human output is a progress tree for the reload action.

JSON output returns the tool entity and action result under
`success.data.tool`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered for the resolved node and supports reload.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

- [`tool:restart`](../7_tool-restart/tool-restart.md) - cycle a managed tool service
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup/configuration
- [`doctor --family=tool`](../tool-doctor.md) - verify tool drift

## Technical Contract

See [`tool-reload` technical contract](technical/1_tool-reload.md).
