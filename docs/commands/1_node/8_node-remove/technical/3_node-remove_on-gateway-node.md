# Technical Contract: `node:remove` Authorized For Gateway Callers

[Back to `node:remove` technical contract.](1_node-remove.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.
- The gateway can access gateway-managed WireGuard state.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs removal directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and
  WireGuard identity.
- Gateway execution may delete durable node state directly.
- Gateway execution may remove gateway-managed WireGuard peers.
- Gateway execution must not SSH to the target node.

## Removal Flow

1. Resolve `node_remove.name`.
2. Validate the node exists and is not any gateway node.
3. Apply destructive consent.
4. Delete node access grants.
5. Remove the gateway-managed WireGuard peer.
6. Remove gateway-owned development DNS mappings for development app nodes.
7. Delete the node record.
8. Return the result.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when the node is a gateway node, regardless of
  gateway count. Gateway retirement is outside `node:remove` and requires a
  future explicit gateway migration/removal flow.
- Fail before side effects when destructive consent is missing in
  non-interactive input mode or when interactive confirmation is declined.
- Report partial WireGuard detach as a structured warning in the success
  response.
- Report partial development DNS cleanup as a structured warning in the success
  response.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRemoveCommandTest.php` | Gateway-local removal, destructive consent coverage, grant cleanup, peer teardown, structured warning reporting for failed peer teardown, any-gateway-node refusal. |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Integration: grants and peer removed together. |
