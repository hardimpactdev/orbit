# Technical Contract: `orbit node:revoke [consuming_node] [serving_node]`

[Back to public `node:revoke` documentation.](../node-revoke.md)

**Owner:** `node`.

**Effects:** `destructive`, `write`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
`general.local_node_role` contract.
- The caller role is not `app`.
- Gateway callers can read and write gateway-owned node intent.
- Control callers have configured gateway access as defined in
[`2_node-revoke_on-control-node.md`](2_node-revoke_on-control-node.md).
- Both `consuming_node` and `serving_node` exist in gateway node intent.

**Post-input path eligibility:**
- Destructive consent is resolved by interactive confirmation or `--force`.
- The caller is authorized through gateway-owned node access policy to manage
node access grants.

## Signature

```bash
orbit node:revoke [consuming_node] [serving_node] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `consuming_node` | `[consuming_node]` | Always. | Never. | None. | Must match an existing active node record. |
| `serving_node` | `[serving_node]` | Always. | Never. | None. | Must match an existing active node record. |
| `force` | `--force` | Non-interactive input mode, or when an interactive caller wants to skip the confirmation prompt. | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

## Caller Role Behavior

`node:revoke` resolves the caller role before command inputs are read. App-node
callers are denied before prompts, forwarding, local writes, SSH, WireGuard
changes, or other side effects. Configured control callers resolve input locally
and forward the revocation request to the gateway over HTTPS through WireGuard.

| Caller role | Behavior |
| --- | --- |
| `control` | With configured gateway access, forward to the gateway over HTTPS through WireGuard. Without configured gateway access, fail before prompts or side effects. See [`2_node-revoke_on-control-node.md`](2_node-revoke_on-control-node.md). |
| `gateway` | Executes locally on the gateway. See [`3_node-revoke_on-gateway-node.md`](3_node-revoke_on-gateway-node.md). |
| `app` | Not allowed. Fail before prompts or side effects with `This command may only be run from a control or gateway node.` See [`4_node-revoke_on-app-node.md`](4_node-revoke_on-app-node.md). |

Role-specific behavior is defined in these companion contracts:

- [`2_node-revoke_on-control-node.md`](2_node-revoke_on-control-node.md):
control caller gateway-forwarding behavior.
- [`3_node-revoke_on-gateway-node.md`](3_node-revoke_on-gateway-node.md):
gateway-local execution behavior.
- [`4_node-revoke_on-app-node.md`](4_node-revoke_on-app-node.md): app caller
behavior.

## Input Resolution

1. Resolve caller role.
   - If `general.local_node_role` is `app`, apply
     [`4_node-revoke_on-app-node.md`](4_node-revoke_on-app-node.md) and fail
     before reading command arguments, rendering prompts, forwarding, local
     writes, SSH, WireGuard changes, or other side effects.
   - If `general.local_node_role` is unreadable or unsupported, fail before
     prompts or side effects.
2. Resolve execution context.
   - If the caller role is `gateway`, execute locally on the gateway.
   - If the caller role is `control`, require configured gateway access and
     prepare a typed gateway request.
3. Resolve `node_revoke.consuming_node` from `[consuming_node]` or the selected
   input mode.
   - In interactive mode, prompt when `[consuming_node]` is missing.
   - In non-interactive mode, fail before side effects when `[consuming_node]`
     is absent.
4. Resolve `node_revoke.serving_node` from `[serving_node]` or the selected
   input mode.
   - In interactive mode, prompt when `[serving_node]` is missing.
   - In non-interactive mode, fail before side effects when `[serving_node]`
     is absent.
5. Validate both node names immediately.
   - Each must match an existing active node record.
6. Resolve `node_revoke.force` from `--force`. Default `false`.
7. Detect self-lockout.
   - When `consuming_node` matches the authenticated control caller's own node
     identity and `serving_node` matches the gateway node, set
     `node_revoke.self_lockout=true`. Otherwise set `node_revoke.self_lockout`
     to `false`.
   - Self-lockout uses the same destructive consent model as any other
     revocation; no extra flag is required beyond `--force`.
8. Apply destructive consent.
   - If `--force` is present, destructive consent is resolved and no
     confirmation prompt is rendered.
   - In interactive mode without `--force`, render a confirmation prompt after
     both node names are valid. When `node_revoke.self_lockout=true`, render
     the self-lockout confirmation label. If the operator cancels, fail before
     side effects.
   - In non-interactive mode without `--force`, fail before side effects.
9. If running from a control node, forward the typed request to the gateway over
   HTTPS through WireGuard. The gateway authenticates the control node identity
   and authorizes the request through gateway-owned access policy before
   gateway-owned side effects begin.

## Input Mode Contracts

- [Interactive input mode](5.1_node-revoke_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-revoke_input-mode_non-interactive.md)

## Behavior Contract

### Grant Endpoint Rules

- Find both node records by name.
- If either node record is not found, fail before side effects.

### Authorization And Consent Rules

- Verify the caller is authorized to manage node access grants through
  gateway-owned access policy.
- Apply the destructive consent rules from the selected input mode.

### Grant Revocation Rules

- Delete the gateway-owned `node_access` record for `consuming_node` →
  `serving_node`.
- If the grant is already absent, succeed idempotently.
  - Rationale: the endpoint node identities have already been validated, and
    the grant row is only a relationship edge. The desired policy state is that
    the edge is absent, so an already-absent edge is a successful no-op.
- Return the consuming node, serving node, action, whether the grant was
  already absent, and whether the revocation locks the caller out of the gateway
  (`self_lockout`).

### Scope Boundaries

`node:revoke` must not:
- Change historical activity logs.
- Mutate serving-node host state.
- End in-flight RPCs, invalidate tokens, or mark sessions stale.
- Block revocation when the grant is referenced by active apps or workspaces.
- Remove the node itself.

## Renderer Contracts

- [Human renderer](6.1_node-revoke_output-render_human.md)
- [JSON renderer](6.2_node-revoke_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node record matches `consuming_node` or `serving_node`. | Failure |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure |
| Caller role not allowed | The caller role is `app`. | Failure |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure |
| Authorization failed | A forwarded control caller is not authorized to manage node access grants. | Failure |
| Local context invalid | `general.local_node_role` is unreadable or unsupported. | Failure |

Grant already absent is a successful idempotent result, not a failure. This is
intentionally narrower than `node:remove` absent-target behavior: `node:remove`
targets a primary node identity and reports a missing node as `node.not_found`,
while `node:revoke` targets a relationship edge after both endpoint identities
are known.

## Doctor Relationship

- Node grants are gateway policy. No node artifact is expected.
- `doctor --family=node` may report invalid grants when a referenced node no
  longer exists. See [`node-doctor.md`](../../node-doctor.md).
- Stale authorization decisions after revocation are not node-family drift.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed grant
revocations.

| Field | Value |
| --- | --- |
| Type | `api:POST /nodes/revoke` |
| Effect | `destructive` |
| Subject | Serving `Node` when the serving node is resolved; `none` when validation fails before a serving node can be resolved. |
| Properties | `consuming_node` is the node losing access; `serving_node` is the node being made invisible to the consuming node; `self_lockout` records whether the revocation removes the caller's gateway access. |
| Description | `<consuming_node> revoked access to <serving_node>` |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Command contract: revocation of a grant, idempotent absent success, node-not-found validation, control-caller gateway forwarding, app-node caller denial before prompts or side effects, interactive confirmation, non-interactive missing-`--force` failure, `--force` success, and authorization failure. |
| `tests/Feature/Commands/Nodes/NodeRevokeOnControlNodeContractTest.php` | Primary owner for control-caller behavior: configured control callers forward over HTTPS through WireGuard, unconfigured control callers fail before prompts or side effects, forwarded requests require access to the gateway node, and no SSH-to-gateway path is used. |

Input-mode-specific test mapping lives in:

- [`5.1_node-revoke_input-mode_interactive.md`](5.1_node-revoke_input-mode_interactive.md#test-mapping)
- [`5.2_node-revoke_input-mode_non-interactive.md`](5.2_node-revoke_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-revoke_output-render_human.md`](6.1_node-revoke_output-render_human.md#test-mapping)
- [`6.2_node-revoke_output-render_json.md`](6.2_node-revoke_output-render_json.md#test-mapping)

Role-specific test mapping lives in:

- [`2_node-revoke_on-control-node.md`](2_node-revoke_on-control-node.md#test-mapping)
- [`3_node-revoke_on-gateway-node.md`](3_node-revoke_on-gateway-node.md#test-mapping)
- [`4_node-revoke_on-app-node.md`](4_node-revoke_on-app-node.md#test-mapping)
