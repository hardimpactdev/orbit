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

Run without arguments to let the command resolve the target node from local
configuration. The name defaults to the local default development app node when
one is configured; otherwise it defaults to the calling node.

## Examples

```bash
orbit node:show
orbit node:show app-1
orbit node:show app-1 --json
```

## Arguments And Options

- `name`: node name to inspect. Optional. Defaults to the local default
  development app node when configured; otherwise defaults to the calling node.
  See
  [Default resolution](technical/5.1_node-show_input-mode_interactive.md#default-resolution)
  for interactive behavior and
  [Non-interactive default resolution](technical/5.2_node-show_input-mode_non-interactive.md#default-resolution)
  for non-interactive behavior.
- `--json`: Output JSON.

## What Happens

`node:show` performs a read-only registry inspection of a single node:

1. Resolves the target node name from input or local default development app
   node configuration.
2. Reads the node record from gateway-owned node intent.
3. Validates that the current caller is authorized to inspect the target node
   through gateway-owned access policy.
4. Returns the registry-backed node details and durable grant metadata.

`node:show` does not:

- Mutate gateway intent or node state.
- Fix drift or adopt node reality.
- SSH into the target node directly from the caller.
- Run live readiness, platform, WireGuard, gateway API, or SSH probes.
- Block on slow or unreachable node runtime checks.

## Output

Human output is a registry detail view.

JSON output returns the node record under a structured envelope. See the
[JSON renderer contract](technical/6.2_node-show_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The target node is visible to the current node identity through gateway-owned
  access policy.

## Related Commands

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`gateway:add`](../2_gateway-add/gateway-add.md) — add a gateway to local config
- [`node:update`](../7_node-update/node-update.md) — update node metadata
- [`node:remove`](../8_node-remove/node-remove.md) — remove a node from the fleet
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:show` technical contract](technical/1_node-show.md).
