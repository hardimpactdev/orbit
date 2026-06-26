# Technical Contract: `orbit firewall:remove <name> [--node=<node>] [--force] [--json]`

[Back to public `firewall-remove` documentation.](../firewall-remove.md)

**Owner:** `firewall`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage firewall policy for the resolved node.

## Signature

```bash
orbit firewall:remove <name> [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `argument` | `Always.` | `Never.` | `None.` | `Firewall rule name on the target node.` |
| `node` | `--node` | `Required when no local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible active Ubuntu node with at least one active role assignment.` |
| `force` | `--force` | `Required in non-interactive input mode.` | `Never.` | `false` | `Explicit destructive consent.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Mode Contracts

- [Interactive input mode](5.1_firewall-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_firewall-remove_input-mode_non-interactive.md)

## Behavior Contract

### Firewall configuration and cleanup rules

- Resolves a firewall-eligible target node.
- Reads gateway firewall-rule configuration for the selected node and name.
- Requires destructive consent before side effects.
- Removes the managed backend firewall rule through the gateway when configuration exists.
- Removes gateway firewall-rule configuration after backend cleanup succeeds.
- If backend cleanup fails, Orbit keeps gateway firewall-rule configuration and reports doctor/manual recovery.
- Orbit does not forget an expected rule while node reality may still contain it.
- Succeeds idempotently when the rule is already absent from gateway configuration.

### Scope Boundaries

`firewall-remove` must not remove node bootstrap policy, delete unmanaged backend rules, infer app/proxy ownership from ports, mutate app/proxy/process/tool state, or adopt observed backend rules. Leftover backend drift belongs to the firewall doctor.

## Renderer Contracts

- [Human renderer](6.1_firewall-remove_output-render_human.md)
- [JSON renderer](6.2_firewall-remove_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
destructive removals.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /firewall-rules/{name}` |
| Effect | `destructive` |
| Subject | The caller `Node`. |
| Properties | `name` (string from route parameter), `node` (string from query). |
| Description | `derived` |

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Baseline conflict | The selected rule is node bootstrap policy, not an Orbit-owned firewall rule. | `error.code=firewall_rule.baseline_conflict` |
| Cleanup failed | Backend firewall cleanup failed before configuration could be removed safely. | `error.code=firewall_rule.cleanup_failed` |

## Doctor Relationship

`firewall-remove` changes gateway firewall-rule configuration and performs command-owned cleanup only. [`firewall-doctor.md`](../../firewall-doctor.md) owns the authoritative `firewall_rule` probe, issue codes, fix map, and adopt map.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php` | CLI firewall:remove destructive consent, DELETE forwarding, JSON success envelope, removed footer prose, idempotent absence handling, and cleanup failure recovery. |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive remove confirmation prompts, `--force` bypass, and side-effect prevention before consent. |

There is no gateway-side coverage for this command contract: CLI contract tests above own the mapped behavior; gateway API surfaces stay coverage gaps until focused gateway tests land.

There is no current firewall command contract unit test. Shared firewall DTO and entity mapping stay as coverage gaps until a focused unit test lands.

Input-mode-specific test mapping lives in:

- [`5.1_firewall-remove_input-mode_interactive.md`](5.1_firewall-remove_input-mode_interactive.md#test-mapping)
- [`5.2_firewall-remove_input-mode_non-interactive.md`](5.2_firewall-remove_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_firewall-remove_output-render_human.md`](6.1_firewall-remove_output-render_human.md#test-mapping)
- [`6.2_firewall-remove_output-render_json.md`](6.2_firewall-remove_output-render_json.md#test-mapping)
