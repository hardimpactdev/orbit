# Technical Contract: `orbit node role:remove [node] [role]`

[Back to public `node role:remove` documentation.](../node-role-remove.md)

**Owner:** `node`.

**Effects:** `destructive`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Gateway callers execute locally.
- Non-gateway callers have `role:remove` on the target node, or an equivalent
  gateway-admin grant.

## Signature

```bash
orbit node role:remove [node] [role] [--force] [--purge-data] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always. | Never. | None. | Must match an active node record. |
| `role` | `[role]` | Always. | Never. | None. | `gateway`, `vpn`, and `router` are rejected. |
| `force` | `--force` | Required for destructive consent in non-interactive destructive cleanup paths. | Never. | `false`. | Enables dependent cleanup. |
| `purge-data` | `--purge-data` | Optional. | Never. | `false`. | Requires `--force`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Consent Rules

- `gateway` role is rejected before side effects.
- `vpn` and `router` roles are rejected before side effects with
  `validation_failed`. The failure message explains that they are
  gateway-coupled infrastructure roles in v1 and cannot be removed
  independently through `node role:remove`.
- `--purge-data` without `--force` fails with `validation_failed`.

### Dependency Rules

- Removal without `--force` blocks when dependents exist and returns `node_role.remove_blocked`.
- `--force` removes Orbit-owned dependents while preserving user data.
- `--force --purge-data` requests purge cleanup.
- If cleanup fails after removal starts, the role assignment remains in `error`
  with the cleanup error recorded and the command returns `node_role.remove_failed`.

### Caller Path Rules

- Gateway callers execute locally.
- Non-gateway callers forward to the gateway through typed HTTPS.
- The gateway authorizes the request with `role:remove` on the target node.

## Renderer Contracts

- [Interactive input](5.1_node-role-remove_input-mode_interactive.md)
- [Non-interactive input](5.2_node-role-remove_input-mode_non-interactive.md)
- [Human renderer](6.1_node-role-remove_output-render_human.md)
- [JSON renderer](6.2_node-role-remove_output-render_json.md)

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRoleRemoveCommandTest.php` | Blocked removal, destructive consent through `--force`, purge behavior, missing `--purge-data` consent validation, and JSON success/failure shapes. |
| `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php` | Exactly one top-level `success` key on remove success and exactly one top-level `error` key on remove validation failure. |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Remove blocked | Dependents exist and `--force` is absent. | Failure |
| Remove failed | Cleanup fails after removal starts. | Failure; assignment remains errored for doctor repair. |
| Node not found | No active node matches `node`. | Failure |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:remove` changes desired state and requests cleanup.
