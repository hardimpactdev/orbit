# Technical Contract: `orbit node role:remove [node] [role]`

[Back to public `node role:remove` documentation.](../node-role-remove.md)

**Owner:** `node`.

**Effects:** `destructive`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Every public CLI caller uses the typed gateway HTTPS API.
- Normal callers have `role:remove` on the target node, or an equivalent
  gateway-admin grant; gateway-role callers use implicit authority.

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
| `force` | `--force` | Required for destructive consent in non-interactive mode. | Never. | `false`. | Explicit destructive consent; skips preview confirmation. |
| `purge-data` | `--purge-data` | Optional. | Never. | `false`. | Requests purge cleanup after destructive consent. Non-interactive use therefore also requires `--force`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Consent Rules

- `gateway` role is rejected before side effects.
- `vpn` and `router` roles are rejected before side effects with
  `validation_failed`. The failure message explains that they are
  gateway-coupled infrastructure roles in v1 and cannot be removed
  independently through `node role:remove`.
- Every supported role removal requires destructive consent, including a role
  with no dependent resources.
- Interactive mode previews dependents and asks for confirmation unless
  `--force` is supplied.
- Non-interactive mode without `--force` fails with
  `error.code=validation_failed`, `error.meta.field=force`, and
  `error.meta.reason=destructive_consent_required`.

### Dependency Rules

- The gateway computes the Orbit-owned dependent-resource list before
  mutation. Interactive mode renders that list when non-empty before asking
  for consent.
- Confirmed removal cleans up Orbit-owned dependents while preserving user
  data. `--purge-data` additionally requests purge cleanup.
- Removal first commits the assignment's `removing` state, then performs role
  baseline and remote runtime cleanup without holding a gateway database
  transaction open. Orbit-owned dependents and the role assignment are deleted
  only after that cleanup succeeds.
- If cleanup fails after removal starts, the role assignment remains in `error`
  with the cleanup error recorded. The dependent records owned by Orbit remain
  intact, and the command returns `node_role.remove_failed`.

### Caller Path Rules

- Every public CLI caller forwards through typed gateway HTTPS, including a CLI
  running on the gateway host.
- The gateway authorizes the request with `role:remove` on the target node.

## Renderer Contracts

- [Interactive input](5.1_node-role-remove_input-mode_interactive.md)
- [Non-interactive input](5.2_node-role-remove_input-mode_non-interactive.md)
- [Human renderer](6.1_node-role-remove_output-render_human.md)
- [JSON renderer](6.2_node-role-remove_output-render_json.md)

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI role-removal preview, dependent rendering, interactive confirmation, non-interactive force validation, and purge forwarding. |
| `apps/gateway/tests/Feature/Http/Api/NodeRoleRemoveControllerTest.php` | Gateway destructive-consent preview metadata, forced removal, and purge cleanup behavior. |

Input-mode-specific test mapping lives in:

- [`5.1_node-role-remove_input-mode_interactive.md`](5.1_node-role-remove_input-mode_interactive.md#test-mapping)
- [`5.2_node-role-remove_input-mode_non-interactive.md`](5.2_node-role-remove_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-role-remove_output-render_human.md`](6.1_node-role-remove_output-render_human.md#test-mapping)
- [`6.2_node-role-remove_output-render_json.md`](6.2_node-role-remove_output-render_json.md#test-mapping)


Destructive consent coverage note: routine tests cover only the mapped `--force`, destructive consent, or confirmation paths above; prompt-only variants and operator forwarding stay as coverage gaps when no path is listed.
## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Destructive consent missing | Non-interactive input omitted `--force`, or interactive confirmation was rejected. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required`; dependent summaries remain in metadata when available. |
| Remove failed | Cleanup fails after removal starts. | Failure; assignment remains errored for doctor repair. |
| Node not found | No active node matches `node`. | Failure |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:remove` changes desired state and requests cleanup.
