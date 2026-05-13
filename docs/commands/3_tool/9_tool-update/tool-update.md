# `orbit tool:update [tool]`

[Back to Tool commands.](../README.md)

Upgrade or downgrade an already managed tool on a node.

`tool:update` changes a managed tool's intended version or updates it to the
latest supported version. It is for version movement, not for first install and
not for configuration-only repair.

## Usage

```bash
orbit tool:update [tool] [--app=<app>] [--node=<node>] [--expected-version=<version>] [--json]
```

## Examples

```bash
orbit tool:update redis --node=app-1
orbit tool:update redis --node=app-1 --expected-version=7.2
orbit tool:update --node=app-1
orbit tool:update redis --app=docs --json
```

## Arguments And Options

- `tool`: Optional tool name. When omitted, Orbit attempts to update all managed
  tools on the target node that support updates.
- `--expected-version`: Specific target version. This avoids Symfony's global
  `--version` console option. When omitted, the tool definition's latest
  supported version is used.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:update`:

1. Resolves the target node and selected managed tools.
2. Verifies each selected tool supports updates and the requested version.
3. Updates the gateway expected version.
4. Applies the version update through the gateway.
5. Restarts a tool when the update process requires it and the expected state is
   `running`.
6. Reports updated, skipped, and failed tools.

Use [`tool:install`](../3_tool-install/tool-install.md) to create or configure a tool for the
first time. Use [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) to rerun setup
without changing intended version.

## Output

Human output is a progress tree grouped by selected tool.

JSON output returns updated tool entities under `success.data.updated`, skipped
tools under `success.data.skipped`, and warnings under `success.meta.warnings`
when some selected tools cannot be updated.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- Selected tools are already registered and managed for the resolved node.
- The tool definitions support updates.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

- [`tool:install`](../3_tool-install/tool-install.md) - install or configure a managed tool
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun configuration/setup
- [`doctor --family=tool`](../tool-doctor.md) - detect version drift

## Technical Contract

See [`tool-update` technical contract](technical/1_tool-update.md).
