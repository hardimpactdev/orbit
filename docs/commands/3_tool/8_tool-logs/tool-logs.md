# `orbit tool:logs <tool>`

[Back to Tool commands.](../README.md)

Print or follow log lines for a managed tool.

`tool:logs` reads log output for a registered tool that declares a log source.
It streams through the gateway from the target node and does not mutate tool
configuration.

## Usage

```bash
orbit tool:logs <tool> [--app=<app>] [--node=<node>] [--lines=<count>] [--follow] [--json]
```

## Examples

```bash
orbit tool:logs redis --node=app-1
orbit tool:logs redis --app=docs --lines=200
orbit tool:logs redis --node=app-1 --follow
orbit tool:logs redis --node=app-1 --json
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target app node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app slug, app domain, or
  `<slug>.<node-tld>` selector such as `docs.dev1`.
- `--lines`: Number of historical lines to show. Defaults to `100`.
- `--follow`: Continue streaming new log lines.
- `--json`: Output JSON for finite, non-follow reads.

Target resolution prefers `--app`, then `--node`, then local `node:default`,
then gateway-known self for non-gateway callers. `--app` and `--node` may be
combined only when both resolve to the same app node. `tool:logs` does not use
the only visible tool node as an implicit target.

`--follow --json` is forbidden until a future streaming JSON frame contract is
added.

## What Happens

Run this command to read or stream log output for a registered tool that declares a log source.

`tool:logs`:

1. Resolves the target node and registered tool row.
2. Verifies the tool declares a log source.
3. Asks the gateway to read or follow logs from the target node.
4. Renders the log lines.

## Output

Use `--json` for finite reads as machine-readable output; use `--follow` to stream new lines.

Human output prints log lines in source order. With `--follow`, output
continues until the user stops the stream or the gateway stream fails.

Use `--json` for finite machine-readable log lines with tool and node metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tools for the selected
  node or app.
- The tool is registered for the resolved node and declares a log source.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands to inspect tool state or verify managed tool health.

- [`tool:show`](../2_tool-show/tool-show.md) - inspect tool configuration and live state
- [`doctor --family=tool`](../tool-doctor.md) - verify expected tool state

## Technical Contract

See [`tool-logs` technical contract](technical/1_tool-logs.md).
