# `orbit tool:show <tool>`

[Back to Tool commands.](../README.md)

Show one registered tool's configuration and optional live state.

`tool:show` is the detail view for a tool on a target node. By default it reads
gateway configuration only. Use `--live` when the operator needs the gateway to inspect
current node reality.

## Usage

```bash
orbit tool:show <tool> [--app=<app>] [--node=<node>] [--live] [--json]
```

## Examples

```bash
orbit tool:show redis --node=app-1
orbit tool:show redis --app=docs
orbit tool:show redis --node=app-1 --live
orbit tool:show redis --node=app-1 --json
```

## Arguments And Options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--live`: Include live node status through the gateway.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:show`:

1. Resolves the target node.
2. Reads the registered tool row and configuration from the gateway.
3. Optionally asks the gateway to inspect live state on the target node.
4. Renders the tool details.

The command does not mutate gateway configuration or node artifacts.

## Output

Human output is a detail view with identity, target node, expected state,
managed flag, version/config metadata, service endpoint metadata when declared,
and live status when requested. Secret values are not rendered by
`tool:show`; use `tool:credentials` for authorized credential reads.

JSON output returns the tool entity under `success.data.tool` and live detail
under `success.data.live` when `--live` is present. Non-secret service
endpoint metadata may be included under `success.data.tool.endpoints`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tools for the selected
  node or app.
- The tool is registered for the resolved node.
- `--live` requires the gateway to reach the target node through Orbit's node
  execution primitive.

## Related Commands

- [`tool:list`](../1_tool-list/tool-list.md) - list registered tool configuration
- [`tool:logs`](../8_tool-logs/tool-logs.md) - read logs for log-capable managed tools
- [`doctor --family=tool`](../tool-doctor.md) - verify expected tool state

## Technical Contract

See [`tool-show` technical contract](technical/1_tool-show.md).
