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
  field requires it. Gateway-targeted work executes locally; non-gateway
  node-local work uses Agent push.
- Gateway execution must not SSH to the target node.

## Update Flow

1. Resolve `node_update.name`.
2. Validate the node exists.
3. Validate role-conditional field eligibility.
4. Validate `node_update.tld` when present: it must not be reserved `orbit`,
   and no other active node may already own it.
5. Validate `node_update.user` when present: it must not target the gateway
   node.
6. Validate `node_update.gateway_endpoint` when present: it must be a valid IP
   address or dotted DNS name.
7. Validate `node_update.managed` when present: `true` requires an eligible
   roleless non-gateway operator, while `false` is valid for every node.
8. Compute the configuration delta (which fields actually changed).
9. Write the updated node record.
10. Re-apply node-owned host artifacts for changed fields.
11. Return the result.

Environment switching between `app-dev` and `app-prod` is a role-assignment
change outside `node:update`.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when no supported field flags are provided in
  non-interactive input mode.
- Fail before side effects when a field is supplied for an incompatible target
  (`--host`, `--gateway-endpoint`, `--public-ipv4`, or `--public-ipv6` on an
  operator-identity node, or `--user` on the gateway node).
- Fail before side effects with `node.tld_in_use` when `--tld` matches another
  active node's stored TLD.
- Fail before side effects when both managed Agent intent flags are supplied
  in one invocation.
- Fail before side effects with `node.field_role_incompatible` when
  `--managed` targets a gateway or role-bearing node, or a node without the
  required active platform and WireGuard identity.
- Fail before side effects when the same field flag is supplied more than
  once in a single invocation.
- Report artifact applying failures as structured warnings under
  `success.meta.warnings[]` when configuration was written successfully. The
  remaining drift is owned by `doctor --family=node`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/NodeUpdateControllerTest.php` | Gateway-local update authorization, field validation, no-op updates, TLD conflicts, artifact warning payloads, and not-found envelopes. |

Warning payload coverage note: gateway API tests cover the artifact warning payload shape for partial re-application failure; other warning variants stay coverage gaps until focused tests land.
