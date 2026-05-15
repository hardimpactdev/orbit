# Technical Contract: `orbit node:grant [consuming_node] [serving_node]`

[Back to public `node:grant` documentation.](../node-grant.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The gateway authenticates the CLI and authorizes `node:grant` only when the
  caller's gateway-known role is `control` or `gateway`. App-role callers are
  rejected.
- Gateway callers can read and write gateway-owned node configuration.
- Control callers have configured gateway access as defined in
  [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md).

**Post-input path eligibility:**
- Both `consuming_node` and `serving_node` resolve to existing active node
  records in gateway configuration. Records with `node.status = provisioning` are
  rejected as `node.not_found`.
- The requested grant does not violate node access policy.
- Self-grant (`consuming_node == serving_node`) is treated as a policy
  violation with `error.meta.reason = self_grant`.

## Signature

```bash
orbit node:grant [consuming_node] [serving_node] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `consuming_node` | `[consuming_node]` | Always. | Never. | None. | Must match an existing active node record in gateway configuration. |
| `serving_node` | `[serving_node]` | Always. | Never. | None. | Must match an existing active node record in gateway configuration. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `node_grant.consuming_node` from `[consuming_node]`.
2. Validate `node_grant.consuming_node` immediately: must match an existing active
   node record in gateway configuration. Records with `node.status = provisioning` are
   rejected as `node.not_found`.
3. Resolve `node_grant.serving_node` from `[serving_node]`.
4. Validate `node_grant.serving_node` immediately: must match an existing active
   node record in gateway configuration. Records with `node.status = provisioning` are
   rejected as `node.not_found`.
5. Evaluate node access policy.
   - If `consuming_node == serving_node`, fail before side effects with a policy
     violation carrying `error.meta.reason = self_grant`.
   - If the requested relationship violates any other node access policy rule,
     fail before side effects with an unspecified denial reason until that rule
     is named in the renderer-specific reason enum.
6. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller's WireGuard identity and authorizes the
   request through gateway-owned access policy before any side effects.

## Behavior Contract

### Grant Endpoint Rules

- Find both active node records by name.
- If either node is not found or is in a non-active lifecycle (e.g.,
  `provisioning`), fail before side effects with `node.not_found`.
- Live reachability is not probed; that responsibility belongs to
  `doctor --family=node`.

### Grant Policy Rules

- Evaluate node access policy for the requested grant.
- If the grant violates policy, fail before side effects with
  `node.grant_policy_violation` and a stable `error.meta.reason` discriminator
  (e.g., `self_grant`).

### Grant Write Rules

- If the grant already exists, succeed without mutation, report
  `action=granted`, and set `already_granted=true`.
- If the grant does not exist, create the `node_access` record from
  `consuming_node` to `serving_node`, report `action=granted`, and set
  `already_granted=false`.
- Return the grant result, both node names, and whether the grant was newly
  created or already present.

### Scope Boundaries

`node:grant` must not:
- SSH into either node.
- Mint node identity or WireGuard peer material.
- Mutate serving-node host state.
- Remove or modify existing grants.
- Grant direct SSH from the consuming node to the serving node.

## Renderer Contracts

- [Human renderer](6.1_node-grant_output-render_human.md)
- [JSON renderer](6.2_node-grant_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Consuming node not found | No active node record matches `consuming_node` (records with `node.status = provisioning` are treated as not found). | Failure |
| Serving node not found | No active node record matches `serving_node` (records with `node.status = provisioning` are treated as not found). | Failure |
| Grant policy violation | The requested relationship violates node access policy. Self-grant is reported with `error.meta.reason = self_grant`. | Failure |

## Doctor Relationship

- Invalid grants where a referenced node no longer exists are reported by
  `doctor --family=node`. See [`node-doctor.md`](../../node-doctor.md#node-issue-codes).
- `doctor --family=node --restore` may clean up grants that reference removed nodes.
- `node:grant` does not repair drift or adopt node reality; those are doctor
  responsibilities.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed grant
writes.

| Field | Value |
| --- | --- |
| Type | `api:POST /nodes/grant` |
| Effect | `write` |
| Subject | Serving `Node` when the serving node is resolved; `none` when validation fails before a serving node can be resolved. |
| Properties | `consuming_node` is the node receiving access; `serving_node` is the node being made visible to the consuming node. |
| Description | `<consuming_node> granted access to <serving_node>` |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php` | Command contract: successful grant, idempotent already-granted success, consuming/serving node not found, grant policy violation including self-grant, control-caller forwarding, app-node denial, and JSON envelope. |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Node access integration: grant creation, idempotence, and policy enforcement. |
| `tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` | Control-caller behavior: configured callers forward over HTTPS through WireGuard, unconfigured callers fail before side effects, forwarded requests require gateway-node access, and no SSH-to-gateway path is used. |

Renderer-specific test mapping lives in:

- [`6.1_node-grant_output-render_human.md`](6.1_node-grant_output-render_human.md#test-mapping)
- [`6.2_node-grant_output-render_json.md`](6.2_node-grant_output-render_json.md#test-mapping)

Role-specific test mapping lives in:

- [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md#test-mapping)
- [`3_node-grant_on-gateway-node.md`](3_node-grant_on-gateway-node.md#test-mapping)
- [`4_node-grant_on-app-node.md`](4_node-grant_on-app-node.md#test-mapping)
