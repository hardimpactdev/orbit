# Technical Contract: `orbit node:permissions [consuming_node] [serving_node]`

[Back to public `node:permissions` documentation.](../node-permissions.md)

**Owner:** `node`.

**Effects:** `read` for read mode, `write` for `--preset`, `--permissions`,
`--add`, and `--remove` modes.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Read mode requires the caller's grant to the gateway to include `node:read`
  or `*`.
- Write modes (`--preset`, `--permissions`, `--add`, `--remove`) require the
  caller's grant to the gateway to include `node:permissions` or `*`.
- Callers without the required permission receive `authorization_failed`
  before side effects.
- Both `consuming_node` and `serving_node` resolve to existing active node
  records in gateway configuration. Records still in `provisioning` are
  rejected as `node.not_found`.

## Signature

```bash
orbit node:permissions [consuming_node] [serving_node] [--preset=<preset>] [--permissions=<list>] [--add=<list>] [--remove=<list>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `consuming_node` | `[consuming_node]` | Always in non-interactive mode. | Never. | None. | Must match an existing active node record. |
| `serving_node` | `[serving_node]` | Always in non-interactive mode. | Never. | None. | Must match an existing active node record. |
| `preset` | `--preset` | Optional. | When `--permissions`, `--add`, or `--remove` is supplied. | None. | Must be a known preset name; presets do not embed wildcard permissions except `gateway-admin`. |
| `permissions` | `--permissions` | Optional. | When `--preset`, `--add`, or `--remove` is supplied. | None. | Comma-separated list of registry-known permission strings; normalized before storage. |
| `add` | `--add` | Optional. | When `--preset`, `--permissions`, or `--remove` is supplied. | None. | Comma-separated list; normalized after merge with current set. |
| `remove` | `--remove` | Optional. | When `--preset`, `--permissions`, or `--add` is supplied. | None. | Comma-separated list; normalized after subtraction from current set. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Mode Selection Rules

- Read mode is selected when none of `--preset`, `--permissions`, `--add`, or
  `--remove` is supplied.
- Exactly one of `--preset`, `--permissions`, `--add`, or `--remove` may be
  supplied. Combining two modes fails with `validation_failed`.
- Interactive selection without mode flags runs the prompt sequence in
  [Interactive input](5.1_node-permissions_input-mode_interactive.md) and
  treats the submitted multiselect as a `--permissions` replacement.

### Authorization Rules

- The gateway resolves the caller's WireGuard identity, then checks for a
  grant from that identity to the gateway. Read mode requires `node:read` or
  `*`; write modes require `node:permissions` or `*`. Callers without the
  required permission receive `authorization_failed`.

### Read Rules

- Read mode requires an existing grant from `consuming_node` to
  `serving_node`. Missing grants fail with `node.grant_not_found`.
- Read mode returns the current normalized permission set without mutation.

### Replacement Rules

- `--preset` expands to its registry-defined permission list, then normalizes.
- `--permissions` parses the comma-separated input, validates each
  permission string against the registry, then normalizes.
- Both modes create a missing grant edge when the normalized result is
  non-empty. An empty normalized result fails with `validation_failed`
  rather than creating an empty grant.

### Merge Rules

- `--add` parses the comma-separated input, validates each permission
  string, merges it with the current set (treating a missing grant as an
  empty current set), then normalizes.
- The gateway creates a missing grant when the normalized merge result is
  non-empty.

### Subtract Rules

- `--remove` parses the comma-separated input, validates each permission
  string, subtracts it from the current set, then normalizes.
- `--remove` requires an existing grant. Missing grants fail with
  `node.grant_not_found`.
- If the normalized subtract result is the empty set, the grant edge is
  preserved with an empty permission set so authorized callers can audit the
  intentional lockout. Removing the grant edge is owned by `node:revoke`.

### Mutation Response Rules

- Mutations set `action` to `created` when the command creates a missing
  grant or `updated` when an existing grant's permission set was changed.
- When the normalized result equals the existing set, the command reports
  `updated` with no diff.

### Warning Rules

- Redundant permissions submitted to `--permissions`, `--add`, or `--preset`
  are normalized away and reported under
  [JSON envelope](6.2_node-permissions_output-render_json.md) warnings.

## Renderer Contracts

- [Interactive input](5.1_node-permissions_input-mode_interactive.md)
- [Non-interactive input](5.2_node-permissions_input-mode_non-interactive.md)
- [Human renderer](6.1_node-permissions_output-render_human.md)
- [JSON renderer](6.2_node-permissions_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Consuming node not found | No active node record matches `consuming_node`. | Failure |
| Serving node not found | No active node record matches `serving_node`. | Failure |
| Mode conflict | More than one of `--preset`, `--permissions`, `--add`, `--remove` is supplied. | Failure |
| Empty replacement | `--preset` or `--permissions` normalizes to an empty set. | Failure |
| Grant not found | Read mode or `--remove` against a missing grant edge. | Failure |
| Unknown permission | Any supplied permission string is not registry-known. | Failure |
| Unknown preset | The named preset is not registry-known. | Failure |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) reports
  `node.access_permission_invalid` when a stored permission set does not
  normalize cleanly against the permission registry.
- `node:permissions` is the explicit grant-management surface for re-normalizing or
  rewriting a grant's permission set after such drift.

## Activity Logging

The gateway API endpoint emits an activity entry for successful reads and
mutations.

| Field | Value |
| --- | --- |
| Type | `api:POST /nodes/permissions` for mutations; `api:GET /nodes/permissions` for reads. |
| Effect | `read` for read mode; `write` for `--preset`, `--permissions`, `--add`, and `--remove` modes. |
| Subject | Serving `Node` when the serving node is resolved; `none` when validation fails before a serving node can be resolved. |
| Properties | `consuming_node` is the node whose grant was viewed or changed; `serving_node` is the grant's target; `mode` is the resolved mode; `permissions` is the post-normalization permission set; `action` is `created` or `updated` for mutations. |
| Description | `<consuming_node> permissions on <serving_node> <action>` for mutations; `<consuming_node> permissions on <serving_node> viewed` for reads. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodePermissionsCommandTest.php` | Mode selection, authorization, read/replace/add/remove semantics, missing-grant create-or-fail rules, mode conflicts, exhaustive `error.code` coverage, JSON envelope, and warning payload shape under `success.meta.warnings[]`. |
| `tests/Feature/Commands/Nodes/NodePermissionsJsonRendererTest.php` | Exactly one top-level `success` key on success and exactly one top-level `error` key on failure. |
