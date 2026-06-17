# Technical Contract: `orbit metrics:disable --node=<node> --force`

[Back to public `metrics:disable` documentation.](../metrics-disable.md)

**Owner:** `metrics`.

**Effects:** `write`. With `--purge-data`, effects include destructive cleanup
where the role cleanup contract supports deleting metrics-owned data.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `role:remove` on the
  target node.

## Signature

```bash
orbit metrics:disable --node=<node> --force [--purge-data] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Always. | Never. | None. | Active node with a `metrics` role assignment. |
| `force` | `--force` | Always. | Never. | `false` | Explicit removal consent. |
| `purge_data` | `--purge-data` | Optional. | Never. | `false` | Requires `--force`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Destructive Consent Rules

- Interactive input mode prompts for confirmation unless `--force` is present.
- Non-interactive input mode requires `--force`.
- Missing or rejected consent fails before side effects with
  `validation_failed`, `error.meta.field=force`, and
  `error.meta.reason=destructive_consent_required`.

### Gateway Request Rules

- Reject missing `--node` before side effects with `validation_failed`.
- In non-interactive input mode, reject missing `--force` before side effects
  with `validation_failed`.
- Send a gateway request equivalent to removing the `metrics` role from the
  target node with `force=true`.
- Pass `purge_data=true` only when `--purge-data` is supplied.

### Success Rules

- On success, the metrics role assignment is removed or marked through the
  gateway role-removal contract, and metrics-owned process/proxy intent is
  cleaned up.
- If no other active metrics role remains, metrics-owned node-exporter process
  intent and node-exporter tool intent are removed from active workload nodes
  as well as the removed metrics node.

## Renderer Contracts

- [Interactive input](5.1_metrics-disable_input-mode_interactive.md)
- [Non-interactive input](5.2_metrics-disable_input-mode_non-interactive.md)
- [Human renderer](6.1_metrics-disable_output-render_human.md)
- [JSON renderer](6.2_metrics-disable_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node required | `--node` is absent. | `error.code=validation_failed`, `error.meta.field=node` |
| Force required | `--force` is absent. | `error.code=validation_failed`, `error.meta.field=force` |
| Role absent | The target node has no `metrics` role assignment. | `error.code=validation_failed` from the gateway role-removal contract |

## Doctor Relationship

`metrics:disable` changes desired role state and requests cleanup. Failed role
cleanup remains node-family drift and is retried through
[`doctor --family=node`](../../../1_node/node-doctor.md). Leftover process
runtime artifacts are process-family drift; leftover proxy artifacts are
proxy-family drift.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Metrics/MetricsCommandsTest.php` | Required `--node`, required `--force`, purge flag forwarding, and gateway path. |
| `apps/gateway/tests/Feature/Services/Nodes/Roles/MetricsRoleBaselineTest.php` | Metrics role cleanup of process, workload exporter, and route intent. |
