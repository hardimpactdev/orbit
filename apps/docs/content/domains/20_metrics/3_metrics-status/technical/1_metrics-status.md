# Technical Contract: `orbit metrics:status`

[Back to public `metrics:status` documentation.](../metrics-status.md)

**Owner:** `metrics`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `process:read` on each
  returned metrics node.

## Signature

```bash
orbit metrics:status [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | None. | Active node with the `metrics` role. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Visibility Rules

- Read active metrics role nodes from gateway configuration.
- When `--node` is absent, return only metrics nodes for which the caller has
  `process:read`.
- When `--node` selects an active metrics node but the caller lacks
  `process:read`, fail with `authorization_failed`.

### Payload Rules

- Return process definitions named `prometheus`, `grafana`, and
  `node-exporter` when they exist for the selected metrics node.
- Workload node-exporter process intent created by metrics convergence is not
  included in the metrics status payload; inspect it through the process family
  for each workload node.
- Do not probe live process runtime state.

## Renderer Contracts

- [Human renderer](6.1_metrics-status_output-render_human.md)
- [JSON renderer](6.2_metrics-status_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Metrics node required | `--node` does not select an active metrics node. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=metrics` |
| Metrics node not visible | `--node` selects an active metrics node but the caller lacks `process:read`. | `error.code=authorization_failed`, `error.meta.missing_permission=process:read` |

## Doctor Relationship

`metrics:status` reads gateway configuration only. Runtime health and drift for
Prometheus, Grafana, and node-exporter on metrics and active Ubuntu workload nodes belong to
[`doctor --family=process`](../../../7_process/process-doctor.md). Route drift
for `metrics.orbit` belongs to
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Activity Logging

The gateway API emits an activity entry for successful and failed status reads.
Secret values are not part of the status payload.

| Field | Value |
| --- | --- |
| Type | `api:GET /metrics/status` |
| Effect | `read` |
| Subject | The caller node. |
| Properties | None. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Metrics/MetricsCommandsTest.php` | CLI request path and JSON forwarding. |
| `apps/gateway/tests/Feature/Commands/Metrics/MetricsCredentialsCommandTest.php` | Gateway status payload and `process:read` authorization. |
