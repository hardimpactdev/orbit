# Technical Contract: `orbit analytics:update`

[Back to public `analytics:update` documentation.](../analytics-update.md)

**Owner:** `analytics`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `process:update` on the
  selected active analytics node.
- At least one visible active analytics role node exists.

## Signature

```bash
orbit analytics:update --version=<version> [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `--version` | Always. | Never. | None. | Plausible CE version such as `3.2.2`. Public flag is `--version`. |
| `node` | `--node` | Optional. | Never. | The fleet's singleton visible active analytics node. | Must match the active node with the `analytics` role. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Input Resolution

1. Resolve the requested Plausible CE version from `--version`. Reject missing or malformed values before
   gateway side effects.
2. Resolve `node` from `--node` or the single visible active analytics role
   node.
3. Forward `version` and the resolved node to the gateway API.

## Behavior Contract

### Version Update Rules

- Resolve the selected active analytics role node.
- Load the node-owned Plausible CE process row for that analytics role.
- Store the requested Plausible CE version on the process row runtime
  configuration and process labels using the generic `version` field.
- Re-render and apply the Plausible CE process runtime through the process
  family. The process family owns restart behavior, logs, events, and runtime
  verification.
- Return the previous version when one was stored and the requested version
  after the update.

### Scope Boundaries

`analytics:update` must not create app analytics bindings, inject tracking
scripts, provision Plausible sites, mutate PostgreSQL or ClickHouse versions,
or create a tool-owned Plausible lifecycle.

## Renderer Contracts

- [Human renderer](6.1_analytics-update_output-render_human.md)
- [JSON renderer](6.2_analytics-update_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Active analytics node required | No visible active analytics node exists, or `--node` does not select one. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=analytics` |
| Version required | `--version` is absent or malformed. | `error.code=validation_failed`, `error.meta.field=version` |
| Process missing | The selected analytics node has no Plausible CE process row. | `error.code=process.not_found` |

## Doctor Relationship

`analytics:update` changes Plausible CE version intent on the process row and
asks the process family to apply it.
[`doctor --family=process`](../../../7_process/process-doctor.md)
owns runtime drift verification and repair after partial apply.
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns
`analytics.orbit` and public app analytics route drift.

## Activity Logging

The gateway API emits an activity entry for successful and failed analytics
update requests.

| Field | Value |
| --- | --- |
| Type | `api:POST /analytics/update` |
| Effect | `write` |
| Subject | The selected analytics node. |
| Properties | `node`, `previous_version`, and `version`. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AnalyticsUpdateControllerTest.php` | Active analytics node resolution, authorization, version validation, process row mutation, missing process failure, and response shape. |
| `apps/cli/tests/Feature/Commands/Analytics/AnalyticsUpdateCommandTest.php` | CLI input validation, typed gateway request payload, human output, and JSON passthrough. |
