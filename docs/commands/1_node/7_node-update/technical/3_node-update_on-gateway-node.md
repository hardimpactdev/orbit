# Technical Contract: `node:update` On A Gateway Node

[Back to `node:update` technical contract.](1_node-update.md)

This page describes caller-role behavior when `orbit node:update` is invoked on
the gateway node.

**Prerequisites:**
- `general.local_node_role` is explicitly set to `gateway`.
- The gateway can read and write gateway-owned node intent.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs the update directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and metadata.
- Gateway execution may update durable node state directly.
- Gateway execution may trigger node-side artifact re-enactment when a changed
  field requires it.
- Gateway execution must not SSH to the target node unless re-enactment of a
  changed field requires it.

## Update Flow

1. Resolve `node_update.name`.
2. Validate the node exists.
3. Validate role-conditional field eligibility.
4. Compute the intent delta (which fields actually changed).
5. Write the updated node record.
6. Re-enact node-owned host artifacts for changed fields.
7. Return the result.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when no supported field flags are provided in
  non-interactive input mode.
- Fail before side effects when a field is supplied for an incompatible node
  role (`--environment` on a non-app node, or `--host`, `--public-ipv4`, or
  `--public-ipv6` on a control node).
- Fail before side effects when the same field flag is supplied more than
  once in a single invocation.
- Report artifact enactment failures as structured warnings under
  `success.meta.warnings[]` when intent was written successfully. The
  remaining drift is owned by `doctor --family=node`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` | Gateway-local update, field validation, role-conditional field rules, no-op success, artifact re-enactment reporting, and warning payload shape for partial-success drift. |
