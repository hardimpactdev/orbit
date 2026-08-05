# Technical Contract: `orbit deploy:log [instance] [run] [--step=<id>] [--lines=<count>] [--json]`

[Back to public `deploy-log` documentation.](../deploy-log.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:read` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:log [instance] [run] [--step=<id>] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `argument` | `Required.` | `Never.` | `None.` | Visible production instance selector. A bare app is valid only when it has exactly one instance. |
| `run` | `argument` | `Required.` | `Never.` | `None.` | Positive integer deployment run id owned by the selected instance. |
| `step` | `--step` | `Optional.` | `Never.` | `None.` | Existing step id inside the selected run. |
| `lines` | `--lines` | `Optional.` | `Never.` | `500`. | Positive integer output line limit per stream. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment Log Visibility Rules

- Resolves one concrete production instance through gateway configuration.
- Fails before reading logs unless the selected instance belongs to a production app.
- Resolves the run id against deployment history for the selected instance.
- Reads stored per-step stdout, stderr, process status, and timing from gateway
  deployment history.
- Applies `--step` after the run is resolved.
- Applies `--lines` to each captured stdout and stderr stream.

### History Boundary Rules

`deploy:log` reads past captured deployment output only. It must not stream
live deployment output, SSH into the owning node, read process manager
logs, mutate gateway configuration, mutate deployment history, or repair failed
deployments.

## Renderer Contracts

- [Human renderer](6.1_deploy-log_output-render_human.md)
- [JSON renderer](6.2_deploy-log_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No visible instance matches the selector. | `error.code=instance.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Instance required | A bare app has more than one instance. | `error.code=validation_failed`, `error.meta.reason=instance_required` |
| Run not found | No visible deployment run matches the run id for the selected instance. | `error.code=deploy.run_not_found` |
| Step not found | `--step` does not match a step in the selected run. | `error.code=deploy.step_not_found` |
| Log read failed | The gateway cannot read stored deployment output. | `error.code=deploy.log_read_failed` |

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /deploy/log/{run}` |
| Effect | `read` |
| Subject | `DeploymentRun` when resolved; `none` otherwise. |
| Properties | `app`, `instance`, `run_id`, optional `step_id`. No raw secrets. |
| Description | derived |

## Doctor Relationship

`deploy:log` explains past deployment behavior. It does not own a doctor family.
[`instance-doctor.md`](../../../5_app/instance-doctor.md) owns current production app
health checks and may reference latest deployment status through
`instance.latest_deployment_failed` and `instance.deployment_run_stuck`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | Interactive app and run prompts before GET to `/api/deploy/log/{run}`. |
| `apps/cli/tests/Feature/Commands/LogStreamCommandTest.php` | Human `deploy:log` output with `--step` and `--lines` query forwarding and captured stdout/stderr grouping. |

There is no gateway-side coverage for this mapped surface; linked CLI tests cover only the mapped assertions above. Remaining behavior stays a coverage gap until focused tests land.

Coverage gaps until focused tests land: production-app eligibility, authorization, run and step lookup failures, JSON renderer behavior, read-only boundary proof, exhaustive documented `error.code` values, and instance-doctor handoff behavior.
