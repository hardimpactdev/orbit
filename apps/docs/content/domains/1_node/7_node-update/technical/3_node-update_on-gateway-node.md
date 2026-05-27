# Technical Contract: `node:update` Authorized For Gateway Callers

[Back to `node:update` technical contract.](1_node-update.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs the update directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and metadata.
- Gateway execution may update durable node state directly.
- Gateway execution may trigger node-side artifact re-applying when a changed
  field requires it.
- Gateway execution must not SSH to the target node unless re-applying a
  changed field requires it.

## Update Flow

1. Resolve `node_update.name`.
2. Validate the node exists.
3. Validate role-conditional field eligibility.
4. Validate `node_update.tld` when present: the target must be a node with an app role whose
   effective environment is `development`, and no other active node may already
   own that TLD.
5. Compute the configuration delta (which fields actually changed).
6. Write the updated node record.
7. Re-apply node-owned host artifacts for changed fields.
8. Return the result.

When the target is currently a production app, `--environment=development
--tld=<tld>` is a valid single update because the effective environment is the
supplied `development` value.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when no supported field flags are provided in
  non-interactive input mode.
- Fail before side effects when a field is supplied for an incompatible node
  role (`--environment` on a non-node, or `--host`, `--public-ipv4`, or
  `--public-ipv6` on a client).
- Fail before side effects when `--tld` is supplied for a gateway target,
  operator target, or app target whose effective environment is `production`.
- Fail before side effects with `node.tld_in_use` when `--tld` matches another
  active node's stored TLD.
- Fail before side effects when the same field flag is supplied more than
  once in a single invocation.
- Report artifact applying failures as structured warnings under
  `success.meta.warnings[]` when configuration was written successfully. The
  remaining drift is owned by `doctor --family=node`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` | Gateway-local update, field validation, role-conditional field rules including `tld`, no-op success, artifact re-applying reporting, and warning payload shape for partial-success drift. |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeUpdateNonInteractiveInputModeTest.php` | Gateway-local non-interactive TLD success, production-effective app rejection, production-to-development plus TLD success, duplicate-TLD conflict, and invalid-TLD syntax. |
