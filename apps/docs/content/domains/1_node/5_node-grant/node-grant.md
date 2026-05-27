# `orbit node:grant [consuming_node] [serving_node]`

[Back to Nodes commands.](../README.md)

Create the initial node access grant from one node to another and seed its
scoped permission set.

Use `node:grant` to open a fresh access edge between two nodes and pick the
permissions that edge starts with. The command is creation-only: once the
grant exists, later changes to its permission set are owned by
[`node:permissions`](../15_node-permissions/node-permissions.md). Grants are
role-agnostic; role constraints are enforced by access policy, not by the
grant command itself.

## Usage

Run this command to create a new grant edge with its initial permissions.

```bash
orbit node:grant [consuming_node] [serving_node] [--preset=<preset>] [--permissions=<list>] [--force] [--json]
```

## Examples

```bash
orbit node:grant agent-1 agent-1 --preset=agent-self
orbit node:grant operator-1 gateway-1 --preset=gateway-admin --force --json
orbit node:grant agent-1 app-1 --preset=operator --json
orbit node:grant agent-1 app-1 --permissions=tool:read,tool:logs --json
```

## Arguments and options

- `consuming_node`: node receiving permission to make Orbit requests against the
  serving node. Must match an existing active node record in gateway configuration.
- `serving_node`: node that may be accessed by those requests. Must match an
  existing active node record in gateway configuration. May equal
  `consuming_node` for a self-grant.
- `--preset`: named permission preset to apply as the grant's initial
  permission set. Mutually exclusive with `--permissions`.
- `--permissions`: comma-separated list of permissions to apply as the
  grant's initial permission set. Mutually exclusive with `--preset`.
- `--force`: explicit consent for elevated grants. Required to apply
  `gateway-admin` or any custom permission set containing `*` to a grant
  whose serving node is the gateway, when running non-interactively.
- `--json`: Output JSON.

Non-interactive callers must supply exactly one of `--preset` or
`--permissions`. Interactive callers without a mode flag are prompted for the
permission set through a multiselect.

## What Happens

Run `node:grant` to create a new access edge from one node to another with
a normalized initial permission set.

`node:grant` writes gateway-owned grant configuration in `node_access` from
`consuming_node` to `serving_node`. Gateway callers execute locally;
configured clients forward the request to the gateway over HTTPS through
WireGuard.

The command:

1. Validates that both nodes exist in gateway configuration.
2. Validates that the requested relationship does not violate node access
   policy.
3. Resolves the initial permission set from `--preset` or `--permissions`
   and normalizes it.
4. Requires explicit consent for elevated grants. `gateway-admin` or any
   permission set containing `*` on a grant to the gateway prompts an
   interactive confirmation, or requires `--force` in non-interactive mode.
5. Writes the grant record with the normalized permission set when the edge
   does not already exist.
6. Reports whether the grant was newly created or already present, and
   surfaces warnings for any redundant permissions that normalization
   removed.

`node:grant` does not:

- SSH into either node.
- Grant direct SSH from the consuming node to the serving node.
- Mutate serving-node host state.
- Mint node identity or WireGuard peer material.
- Remove or modify existing grants. Editing an existing grant's permission
  set belongs to
  [`node:permissions`](../15_node-permissions/node-permissions.md).
- Remove grants. Removal belongs to
  [`node:revoke`](../6_node-revoke/node-revoke.md).

## Output

You will see a confirmation of the grant that lists the normalized
permission set and distinguishes a newly created grant from an
already-existing one. Existing grants point you to `node:permissions` for
later permission edits.

JSON output returns the grant result, both node names, the normalized
permission set, whether the grant was newly created or already present, and
any redundant-permission warnings.

## Requirements

- Must run on the gateway host or from a configured client.
- The caller must hold a grant to the gateway whose permissions include
  `node:grant` or `*`. Callers without that grant fail before side effects.
- Both nodes must have active records in gateway node configuration. Records still
  in `provisioning` are rejected as not found; live reachability is not
  probed and belongs to `doctor --family=node`.
- Self-grants are allowed and required for explicit self-access; node
  access policy no longer rejects them.
- Elevated grants (`gateway-admin` or `*` to the gateway) require
  interactive confirmation or `--force`.

## Related Commands

Use these commands to manage access relationships and inspect the current state.

- [`node:revoke`](../6_node-revoke/node-revoke.md) — remove a node access grant
- [`node:permissions`](../15_node-permissions/node-permissions.md) — view or
  update the scoped permissions on an existing grant
- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`doctor --family=node`](../node-doctor.md) — verify node drift including
  grant validity

## Technical Contract

See [`node:grant` technical contract](technical/1_node-grant.md).
