# `orbit node:remove [name]`

[Back to Nodes commands.](../README.md)

Unregister and detach a node from the Orbit fleet.

Use `node:remove` when decommissioning servers or moving a host to a different
Orbit project. The command removes the node from gateway-owned node intent,
deletes node access grants, and tears down the node's WireGuard peer identity.
It does not clean up apps, workspaces, processes, schedules, tools, firewall
rules, proxy routes, or deploy artifacts on the target server.
Before removing an app node that still owns apps, remove or migrate those apps
through app-family commands such as
[`app:remove`](../../4_app/6_app-remove/app-remove.md). `node:remove` does not
block on downstream family state and does not cascade into app cleanup.

## Usage

```bash
orbit node:remove [name] [--force] [--json]
```

Destructive consent is always required before removal side effects start.
Interactive input mode asks for confirmation unless `--force` is supplied.
Non-interactive input mode requires `--force` because prompts are unavailable.
`--json` never implies `--force`.

## Examples

```bash
orbit node:remove app-1
orbit node:remove app-1 --force
orbit node:remove app-1 --force --json
```

## Arguments And Options

- `name`: node name to remove. Must exist in gateway node intent and must not
  be a gateway node.
- `--force`: explicit destructive consent. Skips the interactive confirmation
  prompt. Required in non-interactive input mode. `--json` never implies
  `--force`.
- `--json`: Output JSON.

## What Happens

`node:remove` performs gateway-owned removal of a node identity. Gateway callers
execute locally; configured control callers forward the request to the gateway
over HTTPS through WireGuard. SSH to the gateway is not used for this command.

1. Validates that the target node exists in gateway intent.
2. Refuses to remove any gateway node.
3. Removes all node access grants where the node is the consumer or the
   serving node.
4. Removes the node's gateway-managed WireGuard peer identity.
5. Removes the node record from the gateway registry.
6. Reports partial WireGuard detach as a structured warning and remaining
   node-family drift.

When a configured control node removes its own node record, the removal is
allowed if the caller is authorized for the gateway node. The machine loses
Orbit gateway access after the gateway removes its node record and WireGuard
peer. Local settings and local WireGuard configuration are left untouched.

`node:remove` does not:

- SSH into the target node.
- Remove or retire gateway nodes. Gateway retirement requires a future explicit
  gateway migration/removal flow.
- Treat an already-absent node as successful removal.
- Stop, remove, or modify apps, workspaces, tools, processes, schedules,
  firewall rules, proxy routes, or deploy artifacts on the server.
- Block removal when downstream family state exists on the node.
- Clean up local caller settings, gateway trust, or local WireGuard
  configuration when the removed node is the local machine.

Downstream family state on a removed node becomes orphaned node reality.
Clean it up through family-specific commands or `doctor --family=<family> --fix`.
Stale WireGuard peers after intent removal are reported by
`doctor --family=node`.

Already-absent node removal is not idempotent because the node record is the
primary fleet identity. A missing node name usually means the operator targeted
the wrong Orbit network, mistyped the node name, or the node was removed by a
separate actor. Orbit reports that as `node.not_found` instead of silently
declaring success. This differs from `node:revoke`, where both endpoint nodes
must still exist and the grant row is only a relationship edge between them.

## Output

Human output shows a confirmation prompt in interactive mode unless `--force`
is supplied, then a success message with the removed node name. When WireGuard
detach partially fails, a warning is shown and the stale peer remains
node-family drift.

JSON output returns the command result, removed node name, whether the removed
node was the current caller, grant and peer removal status. If WireGuard detach
partially fails, JSON output keeps the result successful and reports the repair
path under `success.meta.warnings`.

## Requirements

- Must run on the gateway host or from a configured control node.
- Control callers must be authorized to operate on the gateway node.
- App-node callers are rejected before prompts or side effects.
- The target node must exist in gateway intent.
- The target node must not be any gateway node.
- Removing a non-existent node is a validation failure, not an idempotent
  success. Grant revocation is the node-family idempotent absent-edge command;
  node identity removal is not.
- Destructive consent is required through the interactive confirmation prompt
  or `--force`. Non-interactive input mode requires `--force`.

## Related Commands

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`app:remove`](../../4_app/6_app-remove/app-remove.md) — remove apps before
  decommissioning their owning app node
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:remove` technical contract](technical/1_node-remove.md).
