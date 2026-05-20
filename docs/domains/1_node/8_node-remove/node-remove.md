# `orbit node:remove [name]`

[Back to Nodes commands.](../README.md)

Unregister and detach a node from the Orbit fleet.

Use `node:remove` when decommissioning servers or moving a host to a different
Orbit project. The command removes the node from gateway-owned node configuration,
deletes node access grants, tears down the node's WireGuard peer identity, and
removes development DNS mappings that the gateway owns for development nodes. It
does not clean up apps, workspaces, processes, schedules, tools, firewall rules,
proxy routes, or deploy artifacts on the target server.
Before removing a node with an app role that still owns apps, remove or migrate those apps
through app-family commands such as
[`app:remove`](../../5_app/6_app-remove/app-remove.md). `node:remove` does not
block on downstream family state and does not cascade into app cleanup.

## Usage

Run this command when you need to unregister a node from the fleet.

```bash
orbit node:remove [name] [--force] [--json]
```

Destructive consent is always required before removal side effects start.
Interactive input mode asks for confirmation unless `--force` is supplied.
Destructive consent follows the shared rule in
[`docs/domains/README.md`](../../README.md#destructive-confirmation),
including the current `--json` consent behavior.

## Examples

```bash
orbit node:remove app-1
orbit node:remove app-1 --force
orbit node:remove app-1 --force --json
```

## Arguments and options

- `name`: node name to remove. Must exist in gateway node configuration and must
  not be a gateway node.
- `--force`: explicit destructive consent. Skips the interactive confirmation
  prompt. See the shared destructive confirmation rule for `--json` behavior.
- `--json`: Output JSON.

## What Happens

Use `node:remove` when you want to permanently unregister a node and detach its identity from the gateway.

`node:remove` performs gateway-owned removal of a node identity. Gateway callers
execute locally; configured operator callers forward the request to the gateway
over HTTPS through WireGuard. SSH to the gateway is not used for this command.

1. Validates that the target node exists in gateway configuration.
2. Refuses to remove any gateway node.
3. Removes all node access grants where the node is the consumer or the
   serving node.
4. Removes the node's WireGuard peer identity that the gateway manages.
5. Removes development DNS mappings that the gateway owns for development nodes.
6. Removes the node record from the gateway registry.
7. Reconciles the active `vpn` role DNS runtime so the removed node's TLD no
   longer resolves over WG. In v1 this materializes the gateway-owned desired
   DNS mappings and policy on the gateway-coupled `vpn` role runtime. Contract:
   [`docs/domains/3_tool/dns-bootstrap-contract.md`](../../3_tool/dns-bootstrap-contract.md).
8. Reports partial WireGuard detach or development DNS cleanup failures as
   structured warnings and remaining node-family drift.

When a configured client removes its own node record, the removal is
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

Downstream family state on a removed node becomes orphaned node reality. Clean
it up through family-specific commands or `doctor --family=<family> --restore`.
Stale WireGuard peers and development DNS mappings owned by the gateway after
configuration removal are reported by `doctor --family=node`.

Already-absent node removal is not idempotent because the node record is the
primary fleet identity. A missing node name usually means the operator targeted
the wrong Orbit network, mistyped the node name, or the node was removed by a
separate actor. Orbit reports that as `node.not_found` instead of silently
declaring success. This differs from `node:revoke`, where both endpoint nodes
must still exist and the grant row is only a relationship edge between them.

## Output

You will see a confirmation prompt in interactive mode unless `--force`
is supplied, then a success message with the removed node name. When WireGuard
detach or development DNS cleanup partially fails, a warning is shown and the
stale artifact owned by the gateway remains node-family drift.

JSON output returns the command result, removed node name, whether the removed
node was the current caller, grant and peer removal status. If WireGuard detach
or development DNS cleanup partially fails, JSON output keeps the result
successful and reports repair guidance in machine-readable metadata.

## Requirements

- Must run on the gateway host or from a configured client.
- Operator callers must be authorized to operate on the gateway node.
- App-role callers are rejected before prompts or side effects.
- The target node must exist in gateway configuration.
- The target node must not be any gateway node.
- Removing a non-existent node is a validation failure, not an idempotent
  success. Grant revocation is the command in the node family that handles an absent edge idempotently;
  node identity removal is not.
- Destructive consent is required through the interactive confirmation prompt
  or `--force`. Non-interactive input mode requires `--force`.

## Related Commands

Use these commands to clean up downstream state before or after removing a node.

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`app:remove`](../../5_app/6_app-remove/app-remove.md) — remove apps before
  decommissioning their owning node
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:remove` technical contract](technical/1_node-remove.md).
