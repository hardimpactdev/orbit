# Technical Contract: `orbit node role:list [node]`

[Back to public `node role:list` documentation.](../node-role-list.md)

**Owner:** `node`.

**Effects:** `read`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Gateway callers read locally.
- Non-gateway callers have `role:read` on the target node, or an equivalent
  gateway-admin grant.

## Signature

```bash
orbit node role:list [node] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always in non-interactive mode. | Never. | None. | Must match an active node record. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Role Lookup Rules

- `node` identifies one active node record.
- Unknown or non-active nodes fail with `node.not_found`.

### Read Path Rules

- Gateway callers read role assignments locally.
- Non-gateway callers forward to the gateway through `GatewayConnector`.
- The gateway authorizes the request with `role:read` on the target node.

### Role Payload Rules

- Success returns one `node` and a `roles` list.
- Each role item contains `role`, `status`, `settings`, `last_error`, and `converged_at`.

## Renderer Contracts

- [Human renderer](6.1_node-role-list_output-render_human.md)
- [JSON renderer](6.2_node-role-list_output-render_json.md)

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRoleListCommandTest.php` | JSON and human output, missing node validation, and role payload fields. |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node matches `node`. | Failure |

## Doctor Relationship

- Role assignment status and convergence drift remain owned by [`doctor --family=node`](../../node-doctor.md).
- `node role:list` reads current desired state and does not repair drift.
