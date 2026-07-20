# `orbit tool:show <tool>`

[Back to Tool commands.](../README.md)

Show one registered tool's configuration and optional live state.

`tool:show` is the detail view for a tool on a target node. By default it reads
gateway configuration only. Use `--live` when the operator needs the gateway to
inspect current node reality.

## Usage

```bash
orbit tool:show <tool> [--instance=<project.instance>] [--node=<node>] [--live] [--json]
```

## Examples

```bash
orbit tool:show composer --node=app-1
orbit tool:show opencode-cli --instance=docs
orbit tool:show composer --node=app-1 --live
orbit tool:show composer --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--instance`: Resolve the target node from an app.
- `--live`: Include live node status through gateway-initiated Agent push.
  Without this flag, the
  command reads the gateway registry only and performs no node transport.
- `--json`: Output JSON.

Target context is required. Provide `--node`, `--instance`, or configure local
`node:default`; non-interactive use fails with `validation_failed` when no
target source is available.

## What Happens

Run this command to read a tool's gateway configuration and optionally inspect its live state on the target node.

`tool:show`:

1. Resolves the target node.
2. Reads the registered tool row and configuration from the gateway without
   contacting the target node.
3. With `--live`, asks the gateway to inspect live state on the target node
   through a typed Agent-push probe.
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
- The target node must be Agent eligible and reachable when `--live` is used.

## Related Commands

Use these commands to list or verify tool state.

- [`tool:list`](../1_tool-list/tool-list.md) - list registered tool configuration
- [`doctor --family=tool`](../tool-doctor.md) - verify expected tool state

## Technical Contract

See [`tool-show` technical contract](technical/1_tool-show.md).
