# Technical Contract: `orbit metrics:enable --node=<node>`

[Back to public `metrics:enable` documentation.](../metrics-enable.md)

**Owner:** `metrics`.

**Effects:** `write`. Writes gateway role assignment intent and role-baseline
process/proxy/tool intent through the gateway.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `role:add` on the target
  node.

## Signature

```bash
orbit metrics:enable --node=<node> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Always. | Never. | None. | Active node that can accept the `metrics` role. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Gateway Request Rules

- Send a gateway request equivalent to adding the `metrics` role with empty
  role settings to the target node.
- Reject missing `--node` before side effects with `validation_failed`.
- Reject role conflicts, unsupported platforms, missing nodes, and gateway
  authorization failures through the gateway role-assignment contract.

### Success Rules

- On success, the target node has an active `metrics` role assignment and the
  metrics baseline intent is recorded.
- The baseline records Prometheus and Grafana process intent on the target
  metrics node, node-exporter tool/process intent on the target metrics node
  and every active workload node, and the `metrics.orbit` proxy route.

## Renderer Contracts

- [Human renderer](6.1_metrics-enable_output-render_human.md)
- [JSON renderer](6.2_metrics-enable_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node required | `--node` is absent. | `error.code=validation_failed`, `error.meta.field=node` |
| Role conflict | The target node has a conflicting role assignment. | `error.code=validation_failed` with gateway role-conflict metadata |

## Doctor Relationship

`metrics:enable` creates desired state. Role assignment readiness belongs to
[`doctor --family=node`](../../../1_node/node-doctor.md). Docker substrate drift
and node-exporter host binary drift belong to
[`doctor --family=tool`](../../../3_tool/tool-doctor.md). Metrics process
runtime drift belongs to
[`doctor --family=process`](../../../7_process/process-doctor.md), and
`metrics.orbit` route drift belongs to
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Metrics/MetricsCommandsTest.php` | CLI request path, required node validation, and JSON forwarding. |
| `apps/gateway/tests/Feature/Services/Nodes/Roles/MetricsRoleBaselineTest.php` | Role baseline intent for Docker, process definitions, credentials, and `metrics.orbit`. |
