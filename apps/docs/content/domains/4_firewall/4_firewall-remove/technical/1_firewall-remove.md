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
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required` |
| Baseline conflict | The selected rule is node bootstrap policy, not an Orbit-owned firewall rule. | `error.code=firewall_rule.baseline_conflict` |
| Cleanup failed | Backend firewall cleanup failed before configuration could be removed safely. | `error.code=firewall_rule.cleanup_failed` |

## Doctor Relationship

`firewall-remove` changes gateway firewall-rule configuration and performs command-owned cleanup only. [`firewall-doctor.md`](../../firewall-doctor.md) owns the authoritative `firewall_rule` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php` | CLI `firewall:remove` destructive consent handling, forced DELETE forwarding, JSON success envelope, human progress output, idempotent absence prose, and gateway error passthrough. |
| `apps/cli/tests/Feature/Commands/Firewall/FirewallInteractiveInputModeTest.php` | Interactive `firewall:remove` destructive confirmation prompt before contacting the gateway. |
| `apps/gateway/tests/Feature/Http/Api/FirewallRuleMutationControllerTest.php` | Gateway firewall remove mutation authorization, destructive consent, DELETE request handling, protected-rule rejection, and mutation envelopes. |

Coverage for the in-memory firewall command DTO shape, target resolution rules, baseline policy validation, and mapping from rules to entities is not currently linked; keep it as a gap until focused tests land.
