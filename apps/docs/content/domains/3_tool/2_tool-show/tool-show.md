# `orbit tool:show <tool>`

[Back to Tool commands.](../README.md)

Show one registered tool's configuration and optional live state.

`tool:show` is the detail view for a tool on a target node. By default it reads
gateway configuration only. Use `--live` when the operator needs the gateway to
inspect current node reality.

## Usage

```bash
orbit tool:show <tool> [--app=<app>] [--node=<node>] [--live] [--json]
```

## Examples

```bash
orbit tool:show composer --node=app-1
orbit tool:show opencode-server --app=docs
orbit tool:show composer --node=app-1 --live
orbit tool:show composer --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--live`: Include live node status through the gateway. Without this flag,
  the command does not run remote shell/process inspection.
- `--json`: Output JSON.

Target context is required. Provide `--node`, `--app`, or configure local
`node:default`; non-interactive use fails with `validation_failed` when no
target source is available.

## What Happens

Run this command to read a tool's gateway configuration and optionally inspect its live state on the target node.

`tool:show`:

1. Resolves the target node.
2. Reads the registered tool row and configuration from the gateway.
3. Optionally asks the gateway to inspect live state on the target node.
4. Renders the tool details.

The command does not mutate gateway configuration or node artifacts.

## Output

Use `--json` to get a machine-readable result; omit it for a detail view.

Human output is a detail view with identity, target node, expected state,
managed flag, version/config metadata, service endpoint metadata when declared,
and live status only when requested. Secret values are not rendered by
`tool:show`; use `tool:credentials` for authorized credential reads.

Use `--json` for machine-readable tool and live details. Service endpoint
metadata that is not secret may be included in the machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tools for the selected
  node or app.
- The tool is registered for the resolved node.
- `--live` requires the gateway to reach the target node through Orbit's node
  execution primitive.

## Related Commands

Use these commands to list or verify tool state.

- [`tool:list`](../1_tool-list/tool-list.md) - list registered tool configuration
- [`doctor --family=tool`](../tool-doctor.md) - verify expected tool state

## Technical Contract

See [`tool-show` technical contract](technical/1_tool-show.md).
