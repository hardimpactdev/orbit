# Technical Contract: `orbit metrics:credentials`

[Back to public `metrics:credentials` documentation.](../metrics-credentials.md)

**Owner:** `metrics`.

**Effects:** `read`; `write` when `--reset` is supplied.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `tool:credentials` on
  the selected active metrics node.

## Signature

```bash
orbit metrics:credentials [--node=<node>] [--node-transport=<transport>] [--reset] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | The only active metrics node when exactly one exists. | Active node with the `metrics` role. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `reset` | `--reset` | Optional. | Never. | `false` | Rotate Grafana admin password before rendering. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Credential Read Rules

- Resolve the selected active metrics node.
- Authorize `tool:credentials` on the selected metrics node.
- Read Grafana credentials from the selected node's `grafana` process runtime
  configuration.

### Credential Reset Rules

- With `--reset`, generate a new admin password, update Grafana runtime
  environment and credentials, refresh the process spec hash, and return the
  new credentials.

### Scope Boundaries

- Return private endpoint metadata for `https://metrics.orbit`.
- Do not probe live Grafana health.

## Renderer Contracts

- [Human renderer](6.1_metrics-credentials_output-render_human.md)
- [JSON renderer](6.2_metrics-credentials_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Active metrics node required | No active metrics node exists, more than one exists without `--node`, or `--node` does not select one. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=metrics` |
| Credentials missing | The selected `grafana` process is absent or lacks admin credentials. | `error.code=metrics.credentials_missing` |

## Doctor Relationship

`metrics:credentials` reads and optionally updates gateway-owned Grafana process
configuration. [`doctor --family=process`](../../../7_process/process-doctor.md)
owns missing Grafana process drift and runtime repair.

## Activity Logging

The gateway API emits an activity entry for successful and failed credential
reads or resets. Secret values must not be written to activity properties.

| Field | Value |
| --- | --- |
| Type | `api:GET /metrics/credentials` or `api:POST /metrics/credentials/reset` |
| Effect | `read` or `write` |
| Subject | The caller node. |
| Properties | `node` only. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Metrics/MetricsCommandsTest.php` | CLI read/reset request paths and JSON forwarding. |
| `apps/gateway/tests/Feature/Commands/Metrics/MetricsCredentialsCommandTest.php` | Active node resolution, authorization, missing credentials, credential payload, and reset behavior. |
| `apps/gateway/tests/Feature/Services/Nodes/Roles/MetricsRoleBaselineTest.php` | Metrics role baseline writes Grafana runtime credentials (`admin_user`, `admin_password`, and `url`) consumed by `metrics:credentials`. |
