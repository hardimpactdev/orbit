# Technical Contract: `node:grant` Authorized For Gateway Callers

[Back to `node:grant` technical contract.](1_node-grant.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs grant directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and node
  access grants.
- Gateway execution may create or verify durable `node_access` records directly.
- Gateway execution must not SSH to either node.

## Grant Flow

1. Resolve `node_grant.consuming_node`.
2. Resolve `node_grant.serving_node`.
3. Validate both nodes resolve to existing active node records in gateway
   configuration. Reject `provisioning` records as `node.not_found`. Live
   reachability is not probed; that belongs to `doctor --family=node`.
4. Evaluate node access policy (including self-grant prohibition, surfaced as
   `error.meta.reason = self_grant`).
5. If the grant already exists, return idempotent success.
6. If the grant does not exist, create the `node_access` record.
7. Return the result.

## Failure Semantics

- Fail before side effects when either node has no active record (records with
  `node.status = provisioning` are treated as not found).
- Fail before side effects when the grant violates node access policy. The
  JSON renderer reports the specific reason via `error.meta.reason`.
- Fail before side effects when `consuming_node == serving_node`, with
  `error.meta.reason = self_grant`.
- Idempotent success when the grant already exists.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/NodeGrantCommandTest.php` | Gateway-local grant creation, idempotence, policy enforcement, and node-not-found validation. |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Integration: grant creation and idempotence. |
