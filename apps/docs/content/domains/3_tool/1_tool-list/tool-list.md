# `orbit tool:list`

[Back to Tool commands.](../README.md)

List registered tool state from gateway configuration.

`tool:list` shows the tools Orbit expects or manages on the selected node. By
default, the command uses the local default node. When no default node is set,
it falls back to the current caller node: the machine where Orbit is installed
and running. It is an inventory and audit command. It does not probe nodes
unless a future command contract adds an explicit live inspection option.

## Usage

```bash
orbit tool:list [--instance=<app.instance>] [--node=<node>] [--all] [--json]
```

## Examples

```bash
orbit tool:list
orbit tool:list --node=app-1
orbit tool:list --all
orbit tool:list --instance=docs
orbit tool:list --json
```

## Arguments and options

- `--node`: Limit results to one visible node.
- `--instance`: Resolve the owning node from a concrete instance and limit
  results to that node. Bare logical shorthand is valid only when exactly one
  instance is visible.
- `--all`: Show visible tools across all nodes instead of defaulting to the
  local default node or current caller node.
- `--json`: Output JSON.

## What Happens

Run this command to read the tools Orbit expects or manages on the selected node.

`tool:list`:

1. Resolves the caller's gateway connection and visibility.
2. Resolves optional node or instance filters; when neither is provided, uses the
   local default node or current caller node.
3. Reads visible gateway tool rows.
4. Shows a node-scoped list, or a grouped node list when `--all` is provided.

The command is read-only. It does not install, remove, start, stop, or inspect
tools on nodes.

## Output

Use `--json` to get machine-readable tool entities; omit it for a grouped node
property list.

Human output is grouped by node and shows each tool's name, expected lifecycle
state, managed flag, and known version when available.

Use `--json` for machine-readable tool entities and applied filters.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tools for the selected
  node or instance.
- Requested node or instance filters are visible to the current node identity.

## Related Commands

Use these commands to inspect or verify tool state in more detail.

- [`tool:show`](../2_tool-show/tool-show.md) - inspect one registered tool
- [`doctor --family=tool`](../tool-doctor.md) - compare tool configuration with node reality

## Technical Contract

See [`tool-list` technical contract](technical/1_tool-list.md).
