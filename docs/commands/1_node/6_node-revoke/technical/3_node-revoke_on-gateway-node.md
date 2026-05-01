# Technical Contract: `node:revoke` On A Gateway Node

[Back to `node:revoke` technical contract.](1_node-revoke.md)

This page describes caller-role behavior when `orbit node:revoke` is invoked on
the gateway node.

**Prerequisites:**
- `general.local_node_role` is explicitly set to `gateway`.
- The gateway can read and write gateway-owned node intent.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs revocation directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and node
  access grants.
- Gateway execution may delete durable grant state directly.
- Gateway execution must not SSH to the target node.

## Revocation Flow

1. Resolve `node_revoke.consuming_node`.
2. Resolve `node_revoke.serving_node`.
3. Validate both nodes exist in gateway intent.
4. Apply destructive consent.
5. Delete the `node_access` record for consuming_node → serving_node.
6. Return the result.

## Failure Semantics

- Fail before side effects when either node does not exist.
- Fail before side effects when destructive consent is missing in
  non-interactive input mode or when interactive confirmation is declined.
- Succeed idempotently when the grant is already absent.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Gateway-local revocation, destructive consent coverage, idempotent absent success, node-not-found validation, and grant deletion. |
