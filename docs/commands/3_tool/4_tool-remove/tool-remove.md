# `orbit tool:remove <tool>`

[Back to Tool commands.](../README.md)

Remove a managed tool from a node when supported.

`tool:remove` is a destructive command. It removes Orbit-managed artifacts for a
tool and removes the gateway tool row only through the tool definition's
supported removal path.

## Usage

```bash
orbit tool:remove <tool> [--app=<app>] [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit tool:remove redis --node=app-1
orbit tool:remove redis --app=docs --force
orbit tool:remove redis --node=app-1 --force --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--force`: Confirm destructive removal in non-interactive mode or skip the
  interactive confirmation prompt.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

Run this command to remove Orbit-managed artifacts for a tool and delete its gateway row through the supported removal path.

`tool:remove`:

1. Resolves the target node and registered tool row.
2. Verifies the tool supports managed removal.
3. Requires destructive confirmation.
4. Removes managed node artifacts through the gateway.
5. Removes tool-owned credential material and service endpoint configuration when the
   selected tool owns those artifacts.
6. Removes the gateway tool row when cleanup succeeds.
7. Reports partial cleanup if gateway configuration and node reality diverge.

The command does not remove unrelated user-managed data unless the tool
definition explicitly owns that data.

## Output

Use `--json` to get a machine-readable result; omit it for a progress tree.

Human output is a progress tree for confirmation, node cleanup, and gateway
configuration removal.

JSON output returns removal outcome under `success.data.tool` and warnings under
`success.meta.warnings` when cleanup is partial.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered for the resolved node.
- The tool definition supports managed removal.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands to stop or audit managed tool state without removing.

- [`tool:stop`](../6_tool-stop/tool-stop.md) - stop a managed tool without removing it
- [`doctor --family=tool`](../tool-doctor.md) - report leftover managed artifacts

## Technical Contract

See [`tool-remove` technical contract](technical/1_tool-remove.md).
