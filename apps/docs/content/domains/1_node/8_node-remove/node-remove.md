# `orbit node:remove [name]`

[Back to Nodes commands.](../README.md)

Unregister and detach a node from the Orbit fleet.

Use `node:remove` when decommissioning servers or moving a host to a different
Orbit app. The command removes the node from gateway-owned node configuration,
deletes node access grants, tears down the node's WireGuard peer identity, and
rematerializes private DNS projections without the removed node. It
does not clean up apps, instances, workspaces, processes, schedules, tools, firewall rules,
proxy routes, or deploy artifacts on the target server.

Before removing a node with an app role that still owns instances, remove or migrate those instances
through instance-family commands such as
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
execute locally; configured non-gateway callers forward the request to the
gateway over HTTPS through WireGuard. SSH to the gateway is not used for this
command.

1. Validates that the target node exists in gateway configuration.
2. Refuses to remove any gateway node.
3. If the gateway manages a WireGuard peer for the node, removes it from
   wg-easy durable state and then from the live gateway WireGuard interface.
   A failure stops removal and keeps the node registry state for retry.
4. In one gateway transaction, removes all node access grants where the node is
   the consumer or serving node, deletes its firewall-rule and WireGuard peer
   rows, and deletes the node record.
5. In that same transaction, reconciles the node-owned record projection and any affected proxy-owned
   exact backend records so DNS contains no records for the removed node. The
   shared materializer replaces changed artifacts under one lock and restarts
   DNS once without changing family ownership or touching tool-owned base
   configuration.
   Contract:
   [`docs/domains/3_tool/dns-bootstrap-contract.md`](../../3_tool/dns-bootstrap-contract.md).
6. Reports a WireGuard detach failure as a retryable command error. A DNS
   projection failure returns `node.dns_reconciliation_failed` and rolls the
   registry transaction back, so the same command can retry safely.

When a configured client targets its own node record, Orbit refuses removal
while that node still has a gateway-managed WireGuard peer. Run the command from
the gateway or another authorized node so the caller receives a reliable result
and the gateway retains the peer identity if cleanup must be retried.
Self-removal remains allowed when no managed peer row exists. Local settings and
local WireGuard configuration are left untouched.

`node:remove` does not:

- SSH into the target node.
- Remove or retire gateway nodes. Gateway retirement requires a future explicit
  gateway migration/removal flow.
- Treat an already-absent node as successful removal.
- Stop, remove, or modify apps, instances, workspaces, tools, processes, schedules,
  operator-managed firewall rules, proxy routes, or deploy artifacts on the
  server. Removing the deleted node's firewall-rule registry rows from gateway
  state is part of deleting the node identity.
- Block removal when downstream family state exists on the node.
- Clean up local caller settings, gateway trust, or local WireGuard
  configuration when the removed node is the local machine.

Downstream family state on a removed node becomes orphaned node reality. Clean
it up through family-specific commands before removal. A WireGuard teardown
failure leaves the node registered so the same `node:remove` request can retry;
a DNS reconciliation failure also leaves the node registry state intact.
Doctor is not a recovery path after the node identity has been deleted.

Already-absent node removal is not idempotent because the node record is the
primary fleet identity. A missing node name usually means the operator targeted
the wrong Orbit network, mistyped the node name, or the node was removed by a
separate actor. Orbit reports that as `node.not_found` instead of silently
declaring success. This differs from `node:revoke`, where both endpoint nodes
must still exist and the grant row is only a relationship edge between them.

## Output

You will see a confirmation prompt in interactive mode unless `--force`
is supplied, then a success message with the removed node name. A WireGuard
detach failure returns an error and keeps the node registry state for retry.

JSON output returns the command result, removed node name, whether the removed
node was the current caller, grant count, and peer removal status. A teardown
failure returns `node.wireguard_peer_removal_failed` with `retryable=true`.

## Requirements

- Must run on the gateway host or from a configured client.
- The caller's grant on the target node must include the `node:remove`
  permission (gateway-admin grants escalate this requirement). Denials surface
  as `authorization_failed`.
- The target node must exist in gateway configuration.
- The target node must not be any gateway node.
- A caller cannot remove its own node while a gateway-managed WireGuard peer
  exists. Run the command from the gateway or another authorized node.
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
