# Technical Contract: `orbit firewall:deny [name] --port=<port> [--node=<node>] [--direction=<incoming|outgoing>] [--from=<cidr>] [--to=<cidr>] [--protocol=<tcp|udp>] [--reason=<text>] [--json]`

[Back to public `firewall-deny` documentation.](../firewall-deny.md)

**Owner:** `firewall`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage firewall policy for the resolved node.

## Signature

```bash
orbit firewall:deny [name] --port=<port> [--node=<node>] [--direction=<incoming|outgoing>] [--from=<cidr>] [--to=<cidr>] [--protocol=<tcp|udp>] [--reason=<text>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | `Firewall rule name unique on the target node.` |
| `node` | `--node` | `Required when no local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible active Ubuntu node with role `gateway` or `app`. |
| `direction` | `--direction` | `Optional.` | `Never.` | `incoming` | `incoming` or `outgoing`. |
| `source` | `--from` | `Optional.` | `Never.` | `any` | CIDR or `any`. |
| `destination` | `--to` | `Optional.` | `Never.` | `None.` | CIDR when supported by the backend. |
| `port` | `--port` | `Always.` | `Never.` | `None.` | Destination port or supported port range. |
| `protocol` | `--protocol` | `Optional.` | `Never.` | `tcp` | `tcp` or `udp`. |
| `reason` | `--reason` | `Optional.` | `Never.` | `None.` | Operator note. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may manage firewall rules only when their node identity has
explicit firewall-management authorization for the resolved target. Management
remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

- [Interactive input mode](5.1_firewall-deny_input-mode_interactive.md)
- [Non-interactive input mode](5.2_firewall-deny_input-mode_non-interactive.md)

## Behavior Contract

### Firewall Intent And Enactment Rules

- Resolves a firewall-eligible target node.
- Validates the rule shape and baseline policy boundary before side effects.
- Treats an existing same-node, same-name rule with the same policy shape as
  idempotent. Reusing the name for a different policy fails before mutation.
- Writes gateway firewall-rule intent with action `deny`.
- Enacts the backend firewall rule through the gateway.
- Reports intent and backend enactment as one command outcome.
- If backend enactment fails after intent is written, Orbit keeps the rule as
  expected gateway intent and reports doctor recovery.

### Scope Boundaries

`firewall-deny` must not create nodes, change node bootstrap policy, infer app
or proxy ownership from the port, mutate app/proxy/process/tool state, or adopt
observed backend rules. Related drift belongs to the owning family doctor.

## Renderer Contracts

- [Human renderer](6.1_firewall-deny_output-render_human.md)
- [JSON renderer](6.2_firewall-deny_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
intent writes.

| Field | Value |
| --- | --- |
| Type | `api:POST /firewall-rules` |
| Effect | `write` |
| Subject | The caller `Node`. |
| Properties | `name` (string), `node` (string), `action` (`deny`), `port` (string). |
| Description | `derived` |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage firewall policy for the selected target. | `error.code=authorization_failed` |
| Name collision | A different firewall rule already uses the selected name on the target node. | `error.code=firewall_rule.name_collision` |
| Baseline conflict | The requested rule would mutate node bootstrap policy. | `error.code=firewall_rule.baseline_conflict` |
| Enactment failed | Gateway intent was written, but backend firewall enactment failed. | `error.code=firewall_rule.enactment_failed` |

## Doctor Relationship

`firewall-deny` changes gateway firewall-rule intent and performs command-owned
enactment only. [`firewall-doctor.md`](../../firewall-doctor.md) owns the
authoritative `firewall_rule` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Firewall/FirewallDenyCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Firewall/FirewallCommandContractTest.php` | Shared in-memory firewall command DTO shape, target resolution rules, baseline policy validation, and firewall-rule entity mapping. |
