# `orbit tool:install <tool>`

[Back to Tool commands.](../README.md)

Install or configure a managed tool on a node.

`tool:install` bootstraps a supported tool capability on a target node and
records gateway configuration for that node. It is for first install or re-applying a
missing managed tool, not for changing an already managed version.

## Usage

```bash
orbit tool:install <tool> [--app=<app>] [--node=<node>] [--status=<installed|running>] [--json]
```

## Examples

```bash
orbit tool:install redis --node=app-1
orbit tool:install redis --app=docs --status=running
orbit tool:install redis --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--status`: Expected lifecycle state after install. Defaults to `installed`.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

Run this command to bootstrap a supported tool on the target node and record gateway configuration.

`tool:install`:

1. Resolves the target node and tool definition.
2. Verifies the tool is supported for the target node role and platform.
3. Creates or updates the gateway tool row for the node.
4. Generates managed credentials when the selected tool declares a credential
   contract.
5. Creates or updates service endpoint configuration owned by the tool when the selected tool
   declares one.
6. Applies the managed install/configuration through the gateway.
7. Starts the tool when the expected state is `running`.
8. Reports the resulting expected state and command-owned apply outcome.

If the tool is already managed and the operator wants to change its version,
use [`tool:update`](../9_tool-update/tool-update.md).

## Output

Use `--json` to get a machine-readable result; omit it for progress.

Human output shows progress for configuration write, install/configuration, and
optional start steps.

Use `--json` for the machine-readable tool and command outcome metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool definition supports managed installation on the resolved node.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands for related tool lifecycle and configuration actions.

- [`tool:update`](../9_tool-update/tool-update.md) - change intended version for a managed tool
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup without changing version
- [`doctor --family=tool`](../tool-doctor.md) - verify installed tool state

## Technical Contract

See [`tool-install` technical contract](technical/1_tool-install.md).
