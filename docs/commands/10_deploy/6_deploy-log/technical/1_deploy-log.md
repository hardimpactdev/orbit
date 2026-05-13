# Technical Contract: `orbit deploy:log [app] [run] [--step=<id>] [--lines=<count>] [--json]`

[Back to public `deploy-log` documentation.](../deploy-log.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the WireGuard peer to inspect deployment history for the selected app.

## Signature

```bash
orbit deploy:log [app] [run] [--step=<id>] [--lines=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required.` | `Never.` | `None.` | Visible production app the caller may inspect. |
| `run` | `argument` | `Required.` | `Never.` | `None.` | Positive integer deployment run id owned by the selected app. |
| `step` | `--step` | `Optional.` | `Never.` | `None.` | Existing step id inside the selected run. |
| `lines` | `--lines` | `Optional.` | `Never.` | `500`. | Positive integer output line limit per stream. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment Log Visibility Rules

- Resolves the selected app through gateway app configuration.
- Fails before reading logs unless the selected app is production.
- Resolves the run id against deployment history for the selected app.
- Reads stored per-step stdout, stderr, exit code, and timing from gateway
  deployment history.
- Applies `--step` after the run is resolved.
- Applies `--lines` to each captured stdout and stderr stream.

### History Boundary Rules

`deploy:log` reads past captured deployment output only. It must not stream
live deployment output, SSH into the owning app node, read process manager
logs, mutate gateway configuration, mutate deployment history, or repair failed
deployments.

## Renderer Contracts

- [Human renderer](6.1_deploy-log_output-render_human.md)
- [JSON renderer](6.2_deploy-log_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Run not found | No visible deployment run matches the run id for the selected app. | `error.code=deploy.run_not_found` |
| Step not found | `--step` does not match a step in the selected run. | `error.code=deploy.step_not_found` |
| Log read failed | The gateway cannot read stored deployment output. | `error.code=deploy.log_read_failed` |

## Doctor Relationship

`deploy:log` explains past deployment behavior. It does not own a doctor family.
[`app-doctor.md`](../../../5_app/app-doctor.md) owns current production app
health checks and may reference latest deployment status through
`app.latest_deployment_failed` and `app.deployment_run_stuck`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployLogCommandTest.php` | Command contract for production app lookup, authorization, run lookup, step filtering, line filtering, stored-output read behavior, read-only side-effect boundary, no live SSH or process manager log reads, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-run DTO shape, per-step output shape, run-to-app ownership validation, step lookup rules, line filtering, and stored output mapping. |
