# Technical Contract: `orbit node role:update [node] [role]`

[Back to public `node role:update` documentation.](../node-role-update.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Gateway callers execute locally.
- Callers other than the gateway must be authorized by the gateway through the existing grant pattern.

## Signature

```bash
orbit node role:update [node] [role] [--tld=] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always. | Never. | None. | Must match an active node record. |
| `role` | `[role]` | Always. | Never. | None. | `gateway` and `vpn` are rejected. |
| `tld` | `--tld` | Required for `app-development`. | Forbidden for roles that do not support it. | None. | Must be a single lowercase DNS label without a leading dot. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Role Validation Rules

- `gateway` role is rejected before side effects.
- `vpn` role is rejected before side effects with `validation_failed`. The
  failure message explains that `vpn` is a gateway-coupled infrastructure role
  in v1 and cannot be updated independently through `node role:update`.
- `app-development` requires `--tld`.
- Unsupported role-local options are rejected.

### Update Rules

- Updating desired settings re-runs `NodeRoleAssignmentService::update()`.
- Success returns the refreshed assignment payload after convergence.

### Caller Path Rules

- Gateway callers execute locally.
- Non-gateway callers forward to the gateway through typed HTTPS.

## Renderer Contracts

- [Interactive input](5.1_node-role-update_input-mode_interactive.md)
- [Non-interactive input](5.2_node-role-update_input-mode_non-interactive.md)
- [Human renderer](6.1_node-role-update_output-render_human.md)
- [JSON renderer](6.2_node-role-update_output-render_json.md)

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRoleUpdateCommandTest.php` | Update success and gateway-coupled infrastructure role rejection for `gateway` and `vpn`. |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node matches `node`. | Failure |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:update` changes desired state and triggers convergence.
