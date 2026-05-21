# Technical Contract: `orbit node:revoke [consuming_node] [serving_node]`

[Back to public `node:revoke` documentation.](../node-revoke.md)

**Owner:** `node`.

**Effects:** `destructive`, `write`.

**Prerequisites:**
- The gateway authenticates the CLI through the WireGuard identity path.
- The caller holds a grant to the gateway whose permissions include
  `node:revoke` or `*`. Callers without that permission receive
  `authorization_failed`.
- Configured client callers have gateway access as defined in
[`2_node-revoke_on-client.md`](2_node-revoke_on-client.md).
- Both `consuming_node` and `serving_node` exist in gateway node configuration.

**Post-input path eligibility:**
- Destructive consent is resolved by interactive confirmation or `--force`.
- The caller is authorized through the node access policy that the gateway owns to manage
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
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

## Input Resolution

1. Resolve `node_revoke.consuming_node` from `[consuming_node]` or the selected
   input mode.
   - In interactive mode, prompt when `[consuming_node]` is missing.
   - In non-interactive mode, fail before side effects when `[consuming_node]`
     is absent.
2. Resolve `node_revoke.serving_node` from `[serving_node]` or the selected
   input mode.
   - In interactive mode, prompt when `[serving_node]` is missing.
   - In non-interactive mode, fail before side effects when `[serving_node]`
     is absent.
3. Validate both node names immediately.
   - Each must match an existing active node record.
4. Resolve `node_revoke.force` from `--force`. Default `false`.
5. Detect self-lockout.
   - When `consuming_node` matches the authenticated caller's own node
     identity and `serving_node` matches the gateway node, set
     `node_revoke.self_lockout=true`. Otherwise set `node_revoke.self_lockout`
     to `false`.
   - Self-lockout uses the same destructive consent model as any other
     revocation; no extra flag is required beyond `--force`.
6. Apply destructive consent.
   - If `--force` is present, destructive consent is resolved and no
     confirmation prompt is rendered.
   - In interactive mode without `--force`, render a confirmation prompt after
     both node names are valid. When `node_revoke.self_lockout=true`, render
     the self-lockout confirmation label. If the operator cancels, fail before
     side effects.
   - In non-interactive mode without `--force`, fail before side effects.
7. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller's WireGuard identity, then checks for a
   grant to the gateway whose permissions include `node:revoke` or `*`
   before any side effects.

## Input Mode Contracts

- [Interactive input mode](5.1_node-revoke_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-revoke_input-mode_non-interactive.md)

## Behavior Contract

### Grant Endpoint Rules

- Find both node records by name.
- If either node record is not found, fail before side effects.

### Authorization and consent rules

- Verify the caller is authorized to manage node access grants through
  `node:revoke` or `*` on a grant to the gateway.
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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node record matches `consuming_node` or `serving_node`. | Failure |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure |

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
| `tests/Feature/Commands/NodeAccessCommandsTest.php` | Revocation success, idempotent absence, node-not-found validation, client forwarding, authorization failure, confirmation, and `--force` behavior. |
| `tests/Feature/Commands/Nodes/NodeRevokeOnControlNodeContractTest.php` | Configured-client forwarding, no SSH fallback, `node:revoke` authorization on a gateway grant, self-lockout, destructive consent, and result rendering. |

Input-mode-specific test mapping lives in:

- [`5.1_node-revoke_input-mode_interactive.md`](5.1_node-revoke_input-mode_interactive.md#test-mapping)
- [`5.2_node-revoke_input-mode_non-interactive.md`](5.2_node-revoke_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-revoke_output-render_human.md`](6.1_node-revoke_output-render_human.md#test-mapping)
- [`6.2_node-revoke_output-render_json.md`](6.2_node-revoke_output-render_json.md#test-mapping)

Deployment-context test mapping lives in:

- [`2_node-revoke_on-client.md`](2_node-revoke_on-client.md#test-mapping)
- [`3_node-revoke_on-gateway-node.md`](3_node-revoke_on-gateway-node.md#test-mapping)
