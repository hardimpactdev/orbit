# Technical Contract: `orbit metrics:enable --node=<node>`

[Back to public `metrics:enable` documentation.](../metrics-enable.md)

**Owner:** `metrics`.

**Effects:** `write`. Writes or refreshes gateway role assignment intent,
records role-baseline process/proxy/tool intent through the gateway, and
converges the owned metrics runtime units.

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
- Include the gateway reconvergence flag so an existing metrics assignment is
  updated and reconverged instead of returning an already-assigned validation
  failure.
- Reject missing `--node` before side effects with `validation_failed`.
- Reject role conflicts, unsupported platforms, missing nodes, and gateway
  authorization failures through the gateway role-assignment contract.

### Success Rules

- On success, the target node has an active `metrics` role assignment and the
  metrics baseline intent is recorded and applied. When the assignment already
  existed, the existing assignment is reconverged with empty metrics settings.
- The baseline records and starts Prometheus and Grafana Docker Swarm process
  units on the target metrics node.
- The Grafana process intent includes file provisioning for the Orbit
  Prometheus datasource, a Grafana dashboard provider, and the built-in
  `Orbit Node Resources` dashboard with a `node` selector.
- The baseline records node-exporter tool/process intent on the target metrics
  node and every active workload node, installs the node-exporter host binary
  when missing, and starts the node-exporter systemd process units.
- The baseline records protected `orbit-metrics-node-exporter` firewall rules
  for Ubuntu node-exporter hosts, allowing the metrics node private WireGuard
  access to TCP port 9100. Debian metrics nodes may still run node-exporter, but
  do not receive firewall rule intent because firewall rules are Ubuntu-owned.
- The baseline records the `metrics.orbit` proxy route.

## Renderer Contracts

- [Human renderer](6.1_metrics-enable_output-render_human.md)
- [JSON renderer](6.2_metrics-enable_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node required | `--node` is absent. | `error.code=validation_failed`, `error.meta.field=node` |
| Role conflict | The target node has a conflicting role assignment. | `error.code=validation_failed` with gateway role-conflict metadata |
| Convergence failed | The metrics role assignment was stored or refreshed but baseline convergence ended in `error`. | `error.code=node_role.convergence_failed`, `error.meta.last_error=<recorded convergence error>` |

## Doctor Relationship

`metrics:enable` creates or refreshes desired state and immediately converges
the metrics runtime units it owns. Later role assignment readiness belongs to
[`doctor --family=node`](../../../1_node/node-doctor.md). Docker substrate drift
and node-exporter host binary drift belong to
[`doctor --family=tool`](../../../3_tool/tool-doctor.md). Metrics process
runtime drift belongs to
[`doctor --family=process`](../../../7_process/process-doctor.md), firewall
drift for private node-exporter scrape access belongs to
[`doctor --family=firewall_rule`](../../../4_firewall/firewall-doctor.md), and
`metrics.orbit` route drift belongs to
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Metrics/MetricsCommandsTest.php` | CLI request path, required node validation, and JSON forwarding. |
| `apps/gateway/tests/Feature/Http/Api/NodeRoleAddControllerTest.php` | Existing metrics assignments reconverge when the metrics enable request carries the reconvergence flag. |
| `apps/gateway/tests/Feature/Services/Nodes/Roles/MetricsRoleBaselineTest.php` | Role baseline intent and runtime convergence for Docker, process definitions, Prometheus scrape config, Grafana datasource/dashboard provisioning, credentials, node-exporter, node-exporter firewall intent, and `metrics.orbit`. |
