# Technical Contract: `orbit firewall:list [--node=<node>] [--json]`

[Back to public `firewall-list` documentation.](../firewall-list.md)

**Owner:** `firewall`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect firewall policy for the selected scope.

## Signature

```bash
orbit firewall:list [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | `Optional.` | `Never.` | `None.` | `Visible active Ubuntu node with at least one active role assignment from `gateway`, `app-development`, `app-production`, `database`, or `agent`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Behavior Contract

### Firewall Configuration Visibility Rules

- Reads gateway firewall-rule configuration visible to the caller.
- Applies the optional node filter at the gateway.
- Returns only registered firewall-rule rows.
- Does not inspect live firewall backend state.

### Scope Boundaries

`firewall-list` must not create, update, delete, fix, adopt, or apply firewall rules. It must not read backend firewall state directly. Drift belongs to [`firewall-doctor.md`](../../firewall-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_firewall-list_output-render_human.md)
- [JSON renderer](6.2_firewall-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /firewall-rules` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | `derived` |

## Doctor Relationship

`firewall-list` reads gateway firewall-rule configuration only. [`firewall-doctor.md`](../../firewall-doctor.md) owns the authoritative `firewall_rule` probe, drift, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Firewall/FirewallListCommandTest.php` | Command contract for input validation, gateway authorization, filter behavior, read-only side-effect boundary, failure codes, and doctor handoff behavior. |
| `apps/gateway/tests/Unit/Services/Firewall/FirewallCommandContractTest.php` | Shared in-memory firewall command DTO shape, node filter rules, and firewall-rule entity mapping. |
