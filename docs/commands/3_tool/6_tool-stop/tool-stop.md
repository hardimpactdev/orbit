# `orbit tool:stop <tool>`

[Back to Tool commands.](../README.md)

Stop a managed tool service.

`tool:stop` changes a managed tool's expected lifecycle state to `installed` and
stops the node-side service through the gateway. The tool remains installed and
managed.

## Usage

```bash
orbit tool:stop <tool> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:stop redis --node=app-1
orbit tool:stop redis --app=docs
orbit tool:stop redis --node=app-1 --json
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
because one app node is visible.

## What Happens

Run this command to set a managed tool's expected state to `installed` and stop it through the gateway.

`tool:stop`:

1. Resolves the target node and registered tool row.
2. Verifies the tool is managed and has a stop action.
3. Updates gateway configuration so the expected state is `installed`.
4. Stops the tool through its lifecycle backend on the target node.
5. Reports the resulting state.

## Output

Use `--json` to get a machine-readable result; omit it for a summary of the stop action.

Human success output is concise:

```text
Stopped redis on app-1.
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

Use these commands for related tool lifecycle actions.

- [`tool:start`](../5_tool-start/tool-start.md) - start a managed tool service
- [`tool:restart`](../7_tool-restart/tool-restart.md) - restart a managed tool service
- [`doctor --family=tool`](../tool-doctor.md) - verify the installed expectation

## Technical Contract

See [`tool-stop` technical contract](technical/1_tool-stop.md).
