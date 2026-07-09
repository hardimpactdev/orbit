# `orbit tool:update [tool]`

[Back to Tool commands.](../README.md)

Upgrade or downgrade an already managed tool capability on a node.

`tool:update` changes a managed tool's intended version or updates it to the
latest supported version. It is for host capability version movement, not for
first install, process runtime migration, or configuration-only repair.

## Usage

```bash
orbit tool:update [tool] [--app=<app>] [--node=<node>] [--node-transport=<transport>] [--expected-version=<version>] [--json|--stream-json]
```

## Examples

```bash
orbit tool:update composer --node=app-1
orbit tool:update composer --node=app-1 --expected-version=2.9.2
orbit tool:update --node=app-1
orbit tool:update opencode-cli --app=docs --json
orbit tool:update opencode-cli --app=docs --stream-json
orbit tool:update caddy --node=beast --node-transport=transitional-ssh-fallback --stream-json
```

## Arguments and options

- `tool`: Optional tool name. When omitted, Orbit attempts to update all managed
  tools on the target node that support updates.
- `--expected-version`: Specific target version. This avoids Symfony's global
  `--version` console option. When omitted, the tool definition's latest
  supported version is used.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--node-transport`: Node execution transport preference. Defaults to `auto`.
  Catalog update scripts dispatch through the typed `internal:tool:run-script`
  command over agent-push. The preference is honored for normal output,
  `--json`, and `--stream-json`.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON. Mutually exclusive
  with `--json`.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:update`:

1. Resolves the target node and selected managed tools.
2. Verifies each selected tool supports updates.
3. Updates the gateway expected version.
4. Applies the version update through the gateway.
5. Reports updated, skipped, and failed tools.

Use [`tool:install`](../3_tool-install/tool-install.md) to create or configure a tool for the
first time. Use [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) to rerun setup
without changing intended version. `tool:update` does not silently migrate an
installed capability into a runnable process and does not restart related
processes.

## Output

Use `--json` to get machine-readable results, or `--stream-json` to stream
newline-delimited progress JSON.

Human output shows progress grouped by selected tool.

Use `--json` for machine-readable updated tools, skipped tools, and warnings
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

Use these commands for related tool installation and configuration actions.

- [`tool:install`](../3_tool-install/tool-install.md) - install or configure a managed tool
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun configuration/setup
- [`doctor --family=tool`](../tool-doctor.md) - detect version drift

## Technical Contract

See [`tool-update` technical contract](technical/1_tool-update.md).
