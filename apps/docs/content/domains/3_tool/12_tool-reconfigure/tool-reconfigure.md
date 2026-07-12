# `orbit tool:reconfigure [tool]`

[Back to Tool commands.](../README.md)

Rerun configuration or setup for an installed managed tool.

`tool:reconfigure` re-applies a managed tool's setup/configuration flow without
changing the intended version. It is the command path for changed settings,
credential repair, missing config files, or setup reruns.

## Usage

```bash
orbit tool:reconfigure [tool] [--app=<app>] [--node=<node>] [--password=<password>] [--json|--stream-json]
```

## Examples

```bash
orbit tool:reconfigure opencode-cli --node=agent-1
orbit tool:reconfigure opencode-cli --app=docs --password=<new-password>
orbit tool:reconfigure opencode-cli --node=agent-1 --json
orbit tool:reconfigure opencode-cli --node=agent-1 --stream-json
```

## Arguments and options

- `tool`: Optional tool name. When omitted in interactive mode, Orbit prompts
  from reconfigurable tools visible on the resolved node.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--password`: Optional new authentication password when the tool definition
  supports password reconfiguration.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON. Mutually exclusive
  with `--json`.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:reconfigure`:

1. Resolves the target node and registered tool.
2. Verifies the tool definition declares a reconfigure action.
3. Runs the tool definition's setup/configuration action through the gateway.
4. Updates generated secrets or backend config only when the tool definition
   says reconfiguration owns those values.
5. Updates service endpoint configuration owned by the tool only when the tool definition
   owns that endpoint.
6. Preserves the expected tool version.
7. Reports the reconfiguration result.

Gateway-owned configuration changes stay gateway-local. Target-node
setup/configuration uses Agent push; `tool:reconfigure` exposes no node
transport selector and never falls back to SSH.

The command does not create a tool row for an unmanaged observed tool. Use
explicit `doctor --family=tool --adopt` semantics for supported adoption.

## Output

Use `--json` to get one machine-readable result, or `--stream-json` to stream
newline-delimited progress JSON.

Human output shows progress for setup/configuration steps.

Use `--json` for the machine-readable tool and action result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool is registered for the resolved node and supports reconfiguration.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands for related tool configuration actions.

- [`tool:update`](../9_tool-update/tool-update.md) - change intended version
- [`doctor --family=tool`](../tool-doctor.md) - verify resulting tool state

## Technical Contract

See [`tool-reconfigure` technical contract](technical/1_tool-reconfigure.md).
