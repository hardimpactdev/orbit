# `orbit node:show [name]`

[Back to Nodes commands.](../README.md)

Show one node's gateway registry details.

Use `node:show` when you need the gateway-owned node record, access grants, and
recorded metadata. Live readiness, platform drift, and reachability checks
belong to `doctor --family=node`.

## Usage

```bash
orbit node:show [name] [--json]
```

Run without arguments in an interactive terminal to choose the target from a
node data table. Non-interactive calls use the documented local default
resolution chain.

## Examples

```bash
orbit node:show
orbit node:show app-1
orbit node:show app-1 --json
```

## Arguments and options

- `name`: node name to inspect. Optional. Interactive calls prompt with a data
  table when omitted. Non-interactive calls default to the local default
  development node when configured; otherwise they default to the calling
  node.
  See
  [interactive missing-name resolution](technical/5.1_node-show_input-mode_interactive.md#missing-name-resolution)
  for interactive behavior and
  [Non-interactive default resolution](technical/5.2_node-show_input-mode_non-interactive.md#default-resolution)
  for non-interactive behavior.
- `--json`: Output JSON.

## What Happens

Run `node:show` to inspect a single node's registry record without triggering any live checks.

`node:show` performs a read-only registry inspection of a single node:

1. Resolves the target node name from input, an interactive data-table
   selection, or non-interactive local defaults.
2. Reads the node record from gateway-owned node configuration.
3. Validates that the current caller is authorized to inspect the target node
   through gateway-owned access policy.
4. Returns the registry-backed node details and durable grant metadata.

`node:show` does not:

- Mutate gateway configuration or node state.
- Restore drift or adopt node reality.
- SSH into the target node directly from the caller.
- Run live readiness, platform, WireGuard, gateway API, or SSH probes.
- Block on slow or unreachable node runtime checks.

## Output

Human output is a registry detail view. It shows role assignments as the
primary role view (`Roles` when multiple assignments exist, `Role` when one
assignment exists) and renders grants from the target node's perspective:

- `Serving`: nodes that are allowed to consume this node.
- `Consuming`: nodes this node is allowed to consume.

JSON output returns the node record under a machine-readable output. See the
[JSON renderer contract](technical/6.2_node-show_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The target node is visible to the current node identity through gateway-owned
  access policy.

## Related Commands

Use these commands to act on the node after reviewing its details.

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`gateway:add`](../../2_gateway/1_gateway-add/gateway-add.md) — add a gateway
  to local config
- [`node:update`](../7_node-update/node-update.md) — update node metadata
- [`node:remove`](../8_node-remove/node-remove.md) — remove a node from the fleet
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:show` technical contract](technical/1_node-show.md).
