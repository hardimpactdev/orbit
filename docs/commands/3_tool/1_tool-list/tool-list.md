# `orbit tool:list`

[Back to Tool commands.](../README.md)

List registered tool state from gateway configuration.

`tool:list` shows the tools Orbit expects, manages, or observes on nodes in the
fleet. It is an inventory and audit command. It does not probe nodes unless a
future command contract adds an explicit live inspection option.

## Usage

```bash
orbit tool:list [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:list
orbit tool:list --node=app-1
orbit tool:list --app=docs
orbit tool:list --json
```

## Arguments And Options

- `--node`: Limit results to one visible node.
- `--app`: Resolve the owning app node from an app and limit results to that
  node.
- `--json`: Output JSON.

## What Happens

`tool:list`:

1. Resolves the caller's gateway connection and visibility.
2. Resolves optional node or app filters.
3. Reads visible gateway tool rows.
4. Groups the result by node.

The command is read-only. It does not install, remove, start, stop, or inspect
tools on nodes.

## Output

Human output is grouped by node and shows each tool's name, expected lifecycle
state, managed flag, and known version when available.

JSON output returns tool entities under `success.data.tools` and applied filters
under `success.meta`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tools for the selected
  node or app.
- Requested node or app filters are visible to the current node identity.

## Related Commands

- [`tool:show`](../2_tool-show/tool-show.md) - inspect one registered tool
- [`doctor --family=tool`](../tool-doctor.md) - compare tool configuration with node reality

## Technical Contract

See [`tool-list` technical contract](technical/1_tool-list.md).
