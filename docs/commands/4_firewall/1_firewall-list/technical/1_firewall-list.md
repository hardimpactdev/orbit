# Technical Contract: `orbit firewall:list [--node=<node>] [--json]`

[Back to public `firewall-list` documentation.](../firewall-list.md)

**Owner:** `firewall`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to inspect firewall policy for the selected scope.

## Signature

```bash
orbit firewall:list [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | `Optional.` | `Never.` | `None.` | `Visible active Ubuntu node with role `gateway` or `app`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may read visible firewall policy when authorized;
`firewall:list` never grants write permission or local state authority.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt;
invalid filters fail according to the shared invocation model.

## Behavior Contract

### Firewall Intent Visibility Rules

- Reads gateway firewall-rule intent visible to the caller.
- Applies the optional node filter at the gateway.
- Returns only registered firewall-rule rows.
- Does not inspect live firewall backend state.

### Scope Boundaries

`firewall-list` must not create, update, delete, fix, adopt, or enact firewall
rules. It must not read backend firewall state directly. Drift belongs to
[`firewall-doctor.md`](../../firewall-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_firewall-list_output-render_human.md)
- [JSON renderer](6.2_firewall-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | The node filter is malformed or resolves to an unsupported target. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect firewall policy for the selected scope. | `error.code=authorization_failed` |

## Doctor Relationship

`firewall-list` reads gateway firewall-rule intent only.
[`firewall-doctor.md`](../../firewall-doctor.md) owns the authoritative
`firewall_rule` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Firewall/FirewallListCommandTest.php` | Command contract for input validation, gateway authorization, filter behavior, read-only side-effect boundary, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Firewall/FirewallCommandContractTest.php` | Shared in-memory firewall command DTO shape, node filter rules, and firewall-rule entity mapping. |
