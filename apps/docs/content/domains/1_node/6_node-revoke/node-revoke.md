# `orbit node:revoke [consuming_node] [serving_node]`

[Back to Nodes commands.](../README.md)

Remove a node access grant between a consuming node and a serving node.

Use `node:revoke` when decommissioning services, rotating security credentials,
or hardening network policy by removing unnecessary access. The command deletes
gateway-owned grant configuration; it does not change activity logs
or mutate serving-node host state.

## Usage

```bash
orbit node:revoke [consuming_node] [serving_node] [--force] [--json]
```

## Examples

```bash
orbit node:revoke operator-1 app-1
orbit node:revoke operator-1 app-1 --force
orbit node:revoke operator-1 app-1 --force --json
```

## Arguments and options

- `consuming_node`: node losing permission to make Orbit requests. Must exist in
gateway node configuration.
- `serving_node`: node that was accessible through the grant. Must exist in
gateway node configuration.
- `--force`: explicit destructive consent. Skips the interactive confirmation
  prompt.
- `--json`: Output JSON.

## What Happens

Run `node:revoke` to delete a specific access grant and confirm the result.

`node:revoke` deletes a gateway-owned `node_access` grant configuration from
`consuming_node` to `serving_node`. Every public CLI caller, including one on
the gateway host, sends the request to the gateway over typed HTTPS through
WireGuard.

1. Validates that both nodes exist in gateway node configuration.
2. Validates that the caller is authorized to manage node access grants.
3. Applies destructive consent.
4. Deletes the gateway-owned grant configuration.
5. Reports success, including whether the grant was already absent and whether
   the revocation locks the caller out of the gateway.

A configured caller may revoke its own consuming→gateway grant when that grant
includes `node:revoke` or `*`. The
interactive confirmation calls out that the machine will lose Orbit gateway
access, and the JSON success payload sets `self_lockout=true`. Recovering
gateway access afterwards requires running
`node:grant <caller> <gateway> --preset=gateway-admin --force` from the
gateway itself.

Revoking a self-grant (`consuming_node == serving_node`) is allowed and is
the way to remove a node's explicit self-access. Revoking a gateway-admin
grant still goes through this command. A gateway-admin grant is a grant
from a consumer to the gateway whose permissions include `*`. It does not
get a separate confirmation because every revoke already requires
destructive consent.

This operator-API self-lockout case is distinct from gateway-local direct
mutation. Gateway-local revokes are gateway-owned writes and do not report
`self_lockout=true`.

`node:revoke` does not:

- Change activity logs.
- Mutate serving-node host state.
- End in-flight RPCs, invalidate tokens, or mark sessions stale.
- Block revocation when the grant is referenced by active instances or workspaces.
- Remove the node itself; use [`node:remove`](../8_node-remove/node-remove.md)
for node removal.

## Output

You will see a confirmation prompt in interactive mode unless `--force` is
supplied, then a success message with the revoked nodes.

JSON output returns the command result, consuming node, serving node, and grant
status.

## Requirements

- Must run on the gateway host or from a configured client.
- The caller must hold a grant to the gateway whose permissions include
  `node:revoke` or `*`. Callers without that grant fail before side effects.
- Both target nodes must exist in gateway configuration.
- Revoking a grant that is already absent succeeds idempotently when both nodes
exist. The endpoint node identities are still validated; only the grant
relationship row may already be absent.

This idempotent absent-edge behavior is deliberately narrower than
[`node:remove`](../8_node-remove/node-remove.md). Removing a missing node is a
validation failure because the node record is the target identity itself.
Revoking a missing grant is a successful convergence to the desired relationship
state after both endpoint identities are known.

## Related Commands

Use these commands to manage access grants and inspect node state.

- [`node:grant`](../5_node-grant/node-grant.md) — create a node access grant
- [`node:permissions`](../15_node-permissions/node-permissions.md) — view or
  update permissions on an existing grant
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`node:remove`](../8_node-remove/node-remove.md) — remove a node and all its
grants
- [`doctor --family=node`](../node-doctor.md) — verify node drift

## Technical Contract

See [`node:revoke` technical contract](technical/1_node-revoke.md).
