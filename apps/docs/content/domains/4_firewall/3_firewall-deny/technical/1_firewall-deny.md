# Technical Contract: `orbit firewall:deny [name] --port=<port> [--node=<node>] [--direction=<incoming|outgoing>] [--from=<cidr>] [--to=<cidr>] [--protocol=<tcp|udp>] [--reason=<text>] [--json]`

[Back to public `firewall-deny` documentation.](../firewall-deny.md)

**Owner:** `firewall`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
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
| `node` | `--node` | `Required when no local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible active Ubuntu node with at least one active role assignment.` |
| `direction` | `--direction` | `Optional.` | `Never.` | `incoming` | `incoming` or `outgoing`. |
| `source` | `--from` | `Optional.` | `Never.` | `any` | CIDR or `any`. |
| `destination` | `--to` | `Optional.` | `Never.` | `None.` | CIDR when supported by the backend. |
| `port` | `--port` | `Always.` | `Never.` | `None.` | Destination port or supported port range. |
| `protocol` | `--protocol` | `Optional.` | `Never.` | `tcp` | `tcp` or `udp`. |
| `reason` | `--reason` | `Optional.` | `Never.` | `None.` | Operator note. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Mode Contracts

- [Interactive input mode](5.1_firewall-deny_input-mode_interactive.md)
- [Non-interactive input mode](5.2_firewall-deny_input-mode_non-interactive.md)

## Behavior Contract

### Firewall configuration and apply rules

- Resolves a firewall-eligible target node.
- Validates the rule shape and baseline policy boundary before side effects.
- Treats an existing same-node, same-name rule with the same policy shape as idempotent. Reusing the name for a different policy fails before mutation.
- Writes gateway firewall-rule configuration with action `deny`.
- Applies the backend firewall rule through the gateway.
- Reports configuration and backend apply as one command outcome.
- If the backend apply fails after configuration is written, Orbit keeps the rule as expected gateway configuration and reports doctor recovery.

### Scope Boundaries

`firewall-deny` must not create nodes, change node bootstrap policy, infer app or proxy ownership from the port, mutate app/proxy/process/tool state, or adopt observed backend rules. Related drift belongs to the owning family doctor.

## Renderer Contracts

- [Human renderer](6.1_firewall-deny_output-render_human.md)
- [JSON renderer](6.2_firewall-deny_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed configuration writes.

| Field | Value |
| --- | --- |
| Type | `api:POST /firewall-rules` |
| Effect | `write` |
| Subject | The caller `Node`. |
| Properties | `name` (string), `node` (string), `action` (`deny`), `port` (string). |
| Description | `derived` |

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Name collision | A different firewall rule already uses the selected name on the target node. | `error.code=firewall_rule.name_collision` |
| Baseline conflict | The requested rule would mutate node bootstrap policy. | `error.code=firewall_rule.baseline_conflict` |
| Apply failed | Gateway configuration was written, but the backend firewall apply failed. | `error.code=firewall_rule.enactment_failed` |

## Doctor Relationship

`firewall-deny` changes gateway firewall-rule configuration and performs command-owned apply only. [`firewall-doctor.md`](../../firewall-doctor.md) owns the authoritative `firewall_rule` probe, issue codes, fix map, and adopt map.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php` | CLI firewall:deny validation, default-node deny POST forwarding, JSON success envelope, progress tree output, and backend apply failure recovery prose. |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive deny prompts, prompt validation, and side-effect prevention before input resolution. |

There is no gateway-side coverage for this command contract: CLI contract tests above own the mapped behavior; gateway API surfaces stay coverage gaps until focused gateway tests land.

There is no current firewall command contract unit test. Shared firewall DTO and entity mapping stay as coverage gaps until a focused unit test lands.

Input-mode-specific test mapping lives in:

- [`5.1_firewall-deny_input-mode_interactive.md`](5.1_firewall-deny_input-mode_interactive.md#test-mapping)
- [`5.2_firewall-deny_input-mode_non-interactive.md`](5.2_firewall-deny_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_firewall-deny_output-render_human.md`](6.1_firewall-deny_output-render_human.md#test-mapping)
- [`6.2_firewall-deny_output-render_json.md`](6.2_firewall-deny_output-render_json.md#test-mapping)
