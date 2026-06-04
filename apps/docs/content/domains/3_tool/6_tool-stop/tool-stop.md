# `orbit tool:stop <tool>`

[Back to Tool commands.](../README.md)

Stop the managed process related to a tool capability.

`tool:stop` is a compatibility lifecycle command. Tools are node-level
capabilities; the lifecycle-managed unit is the related process. During the
migration, this command resolves the selected tool row to a related managed
process or legacy backend and stops that long-running unit through the gateway.
The tool remains installed and managed.

## Usage

```bash
orbit tool:stop <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:stop opencode-server --node=agent-1
orbit tool:stop opencode-server --app=docs
orbit tool:stop opencode-server --node=agent-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node.
- `--app`: Resolve the target node from an app selector. The selector may be
  the app slug, app domain, or `<slug>.<node-tld>`.
- `--json`: Output JSON.

Target resolution uses this order:

1. `--app`, optionally checked against `--node` when both are present.
2. `--node`.
3. Local `node:default`.
4. Gateway-known caller identity when the caller has no explicit/default target.

When `--app` and `--node` are both present, they must resolve to the same node
or the command fails before side effects. Orbit does not infer the target merely
because one tool node is visible.

## What Happens

Run this command to stop the managed process related to a selected tool
capability through the gateway.

`tool:stop`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a related lifecycle path.
3. Resolves the related managed process or transitional backend.
4. Stops the related long-running unit on the target node.
5. Reports the resulting state.

## Output

Use `--json` to get a machine-readable result; omit it for a summary of the stop action.

Human success output is concise:

```text
Stopped opencode-server on agent-1.
```

Use `tool:show`, JSON, `tool:logs`, or `doctor --family=tool` for detailed
inspection.

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

Use these commands for related compatibility lifecycle actions.

- [`tool:start`](../5_tool-start/tool-start.md) - start the related managed process
- [`tool:restart`](../7_tool-restart/tool-restart.md) - restart the related managed process
- [`doctor --family=tool`](../tool-doctor.md) - verify the tool capability expectation

## Technical Contract

See [`tool-stop` technical contract](technical/1_tool-stop.md).
