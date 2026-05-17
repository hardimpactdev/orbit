# Technical Contract: `orbit node role:add [node] [role]`

[Back to public `node role:add` documentation.](../node-role-add.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Gateway callers execute locally.
- Non-gateway callers require gateway authorization through the existing grant pattern.

## Signature

```bash
orbit node role:add [node] [role] [--tld=] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always. | Never. | None. | Must match an active node record. |
| `role` | `[role]` | Always. | Never. | None. | `gateway` is rejected. |
| `tld` | `--tld` | Required for `app-development`. | Forbidden for roles that do not support it. | None. | Must be non-empty when required. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Role Eligibility Rules

- `gateway` role is rejected before side effects.
- `app-development` requires `--tld`.
- `app-production` and `database` reject role-local options they do not support.
- Role conflicts are validated by `NodeRoleAssignmentService`.

### Convergence Rules

- Adding a role triggers convergence through `NodeRoleAssignmentService`.
- Success returns the stored assignment payload after convergence.

### Caller Path Rules

- Gateway callers execute the service locally.
- Non-gateway callers forward to the gateway through typed HTTPS.

## Renderer Contracts

- [Interactive input](5.1_node-role-add_input-mode_interactive.md)
- [Non-interactive input](5.2_node-role-add_input-mode_non-interactive.md)
- [Human renderer](6.1_node-role-add_output-render_human.md)
- [JSON renderer](6.2_node-role-add_output-render_json.md)

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRoleAddCommandTest.php` | Add success, role validation, tld validation, conflict validation, and control forwarding. |
| `tests/Feature/Commands/Nodes/NodeRoleJsonRendererTest.php` | Exactly one top-level `success` key on add success and exactly one top-level `error` key on add validation failure. |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node matches `node`. | Failure |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:add` only changes desired state and triggers convergence.
