# Technical Contract: `orbit node:grant [consuming_node] [serving_node]`

[Back to public `node:grant` documentation.](../node-grant.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The gateway authenticates the CLI through the WireGuard identity path.
- The caller holds a grant to the gateway whose permissions include
  `node:grant` or `*`. Callers without that permission receive
  `authorization_failed`.
- Configured client callers have gateway access as defined in
  [`2_node-grant_on-operator-node.md`](2_node-grant_on-operator-node.md).

**Post-input path eligibility:**
- Both `consuming_node` and `serving_node` resolve to existing active node
  records in gateway configuration. Records with `node.status = provisioning`
  are rejected as `node.not_found`.
- Self-grants (`consuming_node == serving_node`) are accepted. Explicit
  self-access is required and is not implicit.
- Exactly one of `--preset` or `--permissions` is supplied in non-interactive
  mode. Combining them, or supplying neither, fails with
  `validation_failed`.
- The resolved initial permission set normalizes to a non-empty set.
- Elevated grants (`gateway-admin` or any custom set containing `*` on a
  grant to the gateway) require interactive confirmation or `--force`.

## Signature

```bash
orbit node:grant [consuming_node] [serving_node] [--preset=<preset>] [--permissions=<list>] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `consuming_node` | `[consuming_node]` | Always. | Never. | None. | Must match an existing active node record in gateway configuration. |
| `serving_node` | `[serving_node]` | Always. | Never. | None. | Must match an existing active node record in gateway configuration. |
| `preset` | `--preset` | One of `--preset` or `--permissions` is required in non-interactive mode. | When `--permissions` is supplied. | None. | Must be a known preset name. Presets do not embed wildcard permissions except `gateway-admin`. |
| `permissions` | `--permissions` | One of `--preset` or `--permissions` is required in non-interactive mode. | When `--preset` is supplied. | None. | Comma-separated list of registry-known permissions; normalized before storage. |
| `force` | `--force` | Required to apply `gateway-admin` or any custom permission set containing `*` to a grant whose serving node is the gateway in non-interactive mode. | Never. | `false`. | Boolean flag. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `node_grant.consuming_node` from `[consuming_node]`.
2. Validate `node_grant.consuming_node` immediately: must match an existing
   active node record in gateway configuration. Records with
   `node.status = provisioning` are rejected as `node.not_found`.
3. Resolve `node_grant.serving_node` from `[serving_node]`.
4. Validate `node_grant.serving_node` immediately: must match an existing
   active node record in gateway configuration. Records with
   `node.status = provisioning` are rejected as `node.not_found`.
5. Resolve the initial permission set:
   - If `--preset` is supplied, expand it through the permission registry.
   - If `--permissions` is supplied, parse the comma-separated list and
     validate each entry against the registry.
   - Normalize the resolved set. Reject an empty normalized result with
     `validation_failed`.
6. Apply elevated-grant consent: when the resolved permission set contains
   `*` on a grant whose serving node is the gateway, require interactive
   confirmation or `--force`.
7. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller's WireGuard identity, then checks for a
   grant to the gateway whose permissions include `node:grant` or `*`
   before any side effects.

## Behavior Contract

### Grant Endpoint Rules

- Find both active node records by name.
- If either node is not found or is in a non-active lifecycle (e.g.,
  `provisioning`), fail before side effects with `node.not_found`.
- Live reachability is not probed; that responsibility belongs to
  `doctor --family=node`.

### Grant Policy Rules

- Evaluate node access policy for the requested grant.
- Self-grants are accepted. Explicit self-access is required; node access
  policy no longer rejects same-node grants.
- If the grant violates any remaining policy rule, fail before side effects
  with `node.grant_policy_violation` and a stable `error.meta.reason`
  discriminator.

### Permission Rules

- Resolve the initial permission set from `--preset` or `--permissions`.
- Normalize the resolved set. Redundant permissions (implied or duplicated)
  are removed and reported under `success.meta.warnings[]`. Unknown
  permission strings or unknown preset names fail with `validation_failed`.
- Reject an empty normalized result with `validation_failed`.
- Apply elevated-grant consent when the normalized result contains `*` on a
  grant to the gateway.

### Grant Write Rules

- If the grant already exists, succeed without mutation, report
  `action=granted`, set `already_granted=true`, and return the existing
  permission set without modification. Point the caller to
  `node:permissions` for later permission edits.
- If the grant does not exist, create the `node_access` record from
  `consuming_node` to `serving_node` with the normalized permission set,
  report `action=granted`, and set `already_granted=false`.
- Return the grant result, both node names, the normalized permission set,
  and whether the grant was newly created or already present.

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
| Grant policy violation | The requested relationship violates node access policy. The stable `error.meta.reason` discriminator names the rule. | Failure |
| Mode conflict | Both `--preset` and `--permissions` were supplied. | Failure |
| Empty permission set | The resolved permission set normalizes to the empty set. | Failure |
| Unknown permission | Any supplied permission string is not registry-known. | Failure |
| Unknown preset | The named preset is not registry-known. | Failure |
| Elevated-grant consent missing | `gateway-admin` or `*` to the gateway was requested without `--force` in non-interactive mode. | Failure |

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
| `apps/gateway/tests/Feature/Commands/Nodes/NodeGrantCommandTest.php` | Grant success, idempotence, validation failures, elevated-grant consent, self-grants, client forwarding, authorization failure, JSON envelope, and warning payloads. |
| `apps/gateway/tests/Feature/Commands/NodeAccessCommandsTest.php` | Node access integration: grant creation, idempotence, and policy enforcement. |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` | Configured-client forwarding, no SSH fallback, `node:grant` authorization on a gateway grant, gateway-unavailable failure, and result rendering. |

Renderer-specific test mapping lives in:

- [`6.1_node-grant_output-render_human.md`](6.1_node-grant_output-render_human.md#test-mapping)
- [`6.2_node-grant_output-render_json.md`](6.2_node-grant_output-render_json.md#test-mapping)

Deployment-context test mapping lives in:

- [`2_node-grant_on-operator-node.md`](2_node-grant_on-operator-node.md#test-mapping)
- [`3_node-grant_on-gateway-node.md`](3_node-grant_on-gateway-node.md#test-mapping)
