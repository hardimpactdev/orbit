# Technical Contract: `orbit node:grant [consuming_node] [serving_node]`

[Back to public `node:grant` documentation.](../node-grant.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  `general.local_node_role` contract.
- The caller role is not `app`.
- Gateway callers can read and write gateway-owned node intent.
- Control callers have configured gateway access as defined in
  [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md).

**Post-input path eligibility:**
- Both `consuming_node` and `serving_node` resolve to existing active node
  records in gateway intent. Records with `node.status = provisioning` are
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
| `consuming_node` | `[consuming_node]` | Always. | Never. | None. | Must match an existing active node record in gateway intent. |
| `serving_node` | `[serving_node]` | Always. | Never. | None. | Must match an existing active node record in gateway intent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`node:grant` resolves the caller role before command inputs are read. App-node
callers are denied before prompts, forwarding, local writes, SSH, WireGuard
changes, or other side effects. Configured control callers resolve input locally
and forward the grant request to the gateway over HTTPS through WireGuard.

| Caller role | Behavior |
| --- | --- |
| `control` | With configured gateway access, forward to the gateway over HTTPS through WireGuard. Without configured gateway access, fail before side effects. See [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md). |
| `gateway` | Executes locally on the gateway. See [`3_node-grant_on-gateway-node.md`](3_node-grant_on-gateway-node.md). |
| `app` | Not allowed. Fail before prompts or side effects with `This command may only be run from a control or gateway node.` See [`4_node-grant_on-app-node.md`](4_node-grant_on-app-node.md). |

Role-specific behavior is defined in these companion contracts:

- [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md):
  control caller gateway-forwarding behavior.
- [`3_node-grant_on-gateway-node.md`](3_node-grant_on-gateway-node.md):
  gateway-local execution behavior.
- [`4_node-grant_on-app-node.md`](4_node-grant_on-app-node.md): app caller
  behavior.

## Input Resolution

1. Resolve caller role.
   - If `general.local_node_role` is `app`, apply
     [`4_node-grant_on-app-node.md`](4_node-grant_on-app-node.md) and fail
     before reading command arguments, rendering prompts, forwarding, local
     writes, SSH, WireGuard changes, or other side effects.
   - If `general.local_node_role` is unreadable or unsupported, fail before
     prompts or side effects.
2. Resolve execution context.
   - If the caller role is `gateway`, execute locally on the gateway.
   - If the caller role is `control`, require configured gateway access and
     prepare a typed gateway request.
3. Resolve `node_grant.consuming_node` from `[consuming_node]`.
4. Validate `node_grant.consuming_node` immediately: must match an existing active
   node record in gateway intent. Records with `node.status = provisioning` are
   rejected as `node.not_found`.
5. Resolve `node_grant.serving_node` from `[serving_node]`.
6. Validate `node_grant.serving_node` immediately: must match an existing active
   node record in gateway intent. Records with `node.status = provisioning` are
   rejected as `node.not_found`.
7. Evaluate node access policy.
   - If `consuming_node == serving_node`, fail before side effects with a policy
     violation carrying `error.meta.reason = self_grant`.
   - If the requested relationship violates any other node access policy rule,
     fail before side effects with `error.meta.reason = unspecified` until that
     rule is named in the JSON renderer's `error.meta.reason` enum.
6. If running from a control node, forward the typed request to the gateway over
   HTTPS through WireGuard. The gateway authenticates the control node identity
   and authorizes the request through gateway-owned node access policy before
   gateway-owned side effects begin.

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

| Failure | Condition | Outcome |
| --- | --- | --- |
| Consuming node not found | No active node record matches `consuming_node` (records with `node.status = provisioning` are treated as not found). | Failure |
| Serving node not found | No active node record matches `serving_node` (records with `node.status = provisioning` are treated as not found). | Failure |
| Grant policy violation | The requested relationship violates node access policy. Self-grant is reported with `error.meta.reason = self_grant`. | Failure |
| Caller role not allowed | The caller role is `app`. | Failure |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure |
| Authorization failed | A forwarded control caller is not authorized to operate on the gateway node. | Failure |
| Local context invalid | `general.local_node_role` is unreadable or unsupported. | Failure |

## Doctor Relationship

- Invalid grants where a referenced node no longer exists are reported by
  `doctor --family=node`. See [`node-doctor.md`](../../node-doctor.md#node-issue-codes).
- `doctor --family=node --fix` may clean up grants that reference removed nodes.
- `node:grant` does not repair drift or adopt node reality; those are doctor
  responsibilities.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/NodeGrantCommandTest.php` | Command contract: successful grant, idempotent already-granted success, consuming node not found, serving node not found, grant policy violation including self-grant, control-caller gateway forwarding, app-node caller denial before prompts or side effects, and JSON envelope shape. |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Node access integration: grant creation, idempotence, and policy enforcement. |
| `tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` | Primary owner for control-caller behavior: configured control callers forward over HTTPS through WireGuard, unconfigured control callers fail before side effects, forwarded requests require access to the gateway node, and no SSH-to-gateway path is used. |

Renderer-specific test mapping lives in:

- [`6.1_node-grant_output-render_human.md`](6.1_node-grant_output-render_human.md#test-mapping)
- [`6.2_node-grant_output-render_json.md`](6.2_node-grant_output-render_json.md#test-mapping)

Role-specific test mapping lives in:

- [`2_node-grant_on-control-node.md`](2_node-grant_on-control-node.md#test-mapping)
- [`3_node-grant_on-gateway-node.md`](3_node-grant_on-gateway-node.md#test-mapping)
- [`4_node-grant_on-app-node.md`](4_node-grant_on-app-node.md#test-mapping)
