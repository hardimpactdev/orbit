# `orbit node:grant [consuming_node] [serving_node]`

[Back to Nodes commands.](../README.md)

Allow one node to consume Orbit capabilities from another node through the
gateway.

Use `node:grant` to establish which nodes may operate on resources owned by other
nodes in the fleet. Grants are role-agnostic; role constraints are enforced by
access policy, not by the grant command itself.

## Usage

```bash
orbit node:grant [consuming_node] [serving_node] [--json]
```

## Examples

```bash
orbit node:grant control-1 app-1
orbit node:grant app-1 gateway-1 --json
```

## Arguments And Options

- `consuming_node`: node receiving permission to make Orbit requests against the
  serving node. Must match an existing active node record in gateway intent.
- `serving_node`: node that may be accessed by those requests. Must match an
  existing active node record in gateway intent.
- `--json`: Output JSON.

## What Happens

`node:grant` writes gateway-owned grant intent in `node_access` from
`consuming_node` to `serving_node`. Gateway callers execute locally; configured
control callers forward the request to the gateway over HTTPS through WireGuard.

The command:

1. Validates that both nodes exist in gateway intent.
2. Validates that the requested relationship does not violate node access policy.
3. Writes the grant record when it does not already exist.
4. Reports whether the grant was newly created or already present.

`node:grant` does not:

- SSH into either node.
- Grant direct SSH from the consuming node to the serving node.
- Mutate serving-node host state.
- Mint node identity or WireGuard peer material.
- Remove or modify existing grants.

## Output

Human output confirms the grant and distinguishes a newly created grant from an
already-existing one.

JSON output returns the grant result, both node names, and whether the grant was
newly created or already present.

## Requirements

- Must run on the gateway host or from a configured control node.
- Control callers must be authorized to operate on the gateway node.
- App-node callers are rejected before side effects.
- Both nodes must have active records in gateway node intent. Records still
  in `provisioning` are rejected as not found; live reachability is not
  probed and belongs to `doctor --family=node`.
- The grant must not violate node access policy. Self-grant is rejected.

## Related Commands

- [`node:revoke`](../6_node-revoke/node-revoke.md) — remove a node access grant
- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`doctor --family=node`](../node-doctor.md) — verify node drift including
  grant validity

## Technical Contract

See [`node:grant` technical contract](technical/1_node-grant.md).
