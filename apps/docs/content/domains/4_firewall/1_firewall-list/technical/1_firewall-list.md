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
| `node` | `--node` | `Optional.` | `Never.` | `None.` | `Visible active Ubuntu node with at least one active role assignment.` |
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

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallListCommandTest.php` | CLI firewall:list GET forwarding, filter validation, JSON list envelope, authorization_failed passthrough, and read-only boundary. |

There is no gateway-side coverage for this command contract: CLI contract tests above own the mapped behavior; gateway API surfaces stay coverage gaps until focused gateway tests land.

There is no current firewall command contract unit test. Shared firewall DTO and entity mapping stay as coverage gaps until a focused unit test lands.

Renderer-specific test mapping lives in:

- [`6.1_firewall-list_output-render_human.md`](6.1_firewall-list_output-render_human.md#test-mapping)
- [`6.2_firewall-list_output-render_json.md`](6.2_firewall-list_output-render_json.md#test-mapping)
