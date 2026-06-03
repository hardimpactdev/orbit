# `orbit tool:restart <tool>`

[Back to Tool commands.](../README.md)

Restart the managed process related to a tool capability.

`tool:restart` is a compatibility lifecycle command. Tools are node-level
capabilities; the lifecycle-managed unit is the related process. During the
migration, this command resolves the selected tool row to a related managed
process or legacy backend and restarts that long-running unit without changing
the expected version or rerunning the tool's setup flow.

## Usage

```bash
orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:restart redis --node=app-1
orbit tool:restart redis --app=docs
orbit tool:restart redis --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured, then gateway-known self for non-gateway callers.
- `--app`: Resolve the target node from an app slug, app domain, or `<slug>.<node-tld>` selector.
- `--json`: Output JSON.

Target resolution is ordered: explicit `--app` or `--node`, then local
`node:default`, then gateway-known self for non-gateway callers. When both
`--app` and `--node` are supplied, the app selector must resolve to the same
node. Orbit never selects a target just because only one tool node is visible.

## What Happens

`tool:restart`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a related restart path.
3. Resolves the related managed process or transitional backend.
4. Restarts the related long-running unit on the target node.
5. Preserves existing gateway configuration and expected version.
6. Reports the restart result.

The command does not repair divergent configuration. Use
[`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) or `doctor --family=tool --restore`
for configuration convergence.

## Output

Use `--json` to get a machine-readable result; omit it for a summary of the restart action.

Human output stays concise:

```text
Restarted redis on app-1.
```

Use `tool:show`, `--json`, `doctor`, or `tool:logs` for detailed state,
configuration, and runtime diagnostics.

Use `--json` for the machine-readable tool result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered and managed for the resolved node.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands for related compatibility lifecycle and configuration
actions.

- [`tool:reload`](../11_tool-reload/tool-reload.md) - reload configuration when supported
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup without changing version
- [`doctor --family=tool`](../tool-doctor.md) - verify tool drift

## Technical Contract

See [`tool-restart` technical contract](technical/1_tool-restart.md).
