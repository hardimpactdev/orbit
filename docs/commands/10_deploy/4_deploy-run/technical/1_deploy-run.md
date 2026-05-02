# Technical Contract: `orbit deploy:run [app] [--detach] [--json]`

[Back to public `deploy-run` documentation.](../deploy-run.md)

**Owner:** `deploy`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- App-node callers are denied before prompts or side effects because deployment runs mutate app-owned gateway history and execute node-side deployment steps.
- The gateway can reach the selected production app's owning node.

## Signature

```bash
orbit deploy:run [app] [--detach] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production app the caller may deploy. |
| `detach` | `--detach` | `Optional.` | `Never.` | `false` | Boolean flag. Starts the run and returns without streaming output. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Resolve input locally, then forward the deployment request to the gateway when authorized. |
| `gateway` | `valid` | Create history locally and execute deployment steps on the app's owning node when authorized. |
| `app` | `invalid` | Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |
| `unknown` | `invalid` | Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-run_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-run_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Run Lifecycle

- Resolves the selected app through gateway app intent.
- Fails before side effects unless the selected app is production.
- Reads the app's deployment steps ordered by `order` ascending.
- Fails before side effects when the production app has no deployment steps.
- Creates a durable deployment run with `status=running` before executing the
  first step.
- Executes each configured step on the app's owning node through the gateway.
- Executes each step from the gateway-tracked app source path.
- Exposes deploy-step metadata, including `retention`, to the step execution
  context. Orbit does not apply global deployment retention outside the step
  that declares it.
- Enforces the step timeout stored on the deploy-step definition.
- Captures stdout, stderr, exit code, and timing for every executed step.
- Stops at the first failed step and does not execute later steps.
- Updates the run status and the app's latest deployment status to
  `completed`, `failed`, or `cancelled`.

### Detached Run Rules

- `--detach` creates the deployment run and starts execution under gateway
  control.
- Detached runs return after the run is durable and execution has been handed
  off to the gateway.
- Detached human and JSON output do not stream step output.
- The gateway updates deployment history and latest deployment status when the
  detached run finishes.

### Scope Boundaries

`deploy:run` must not create, update, remove, or reorder deployment steps. It
must not create process definitions, schedules, proxy routes, workspace setup
steps, standalone release records, or global retention policy. It does not
prove application HTTP readiness; production app health belongs to
[`app-doctor.md`](../../../5_app/app-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_deploy-run_output-render_human.md)
- [JSON renderer](6.2_deploy-run_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing or malformed. | `error.code=validation_failed` |
| Caller role not allowed | The caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to deploy the app. | `error.code=authorization_failed` |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Pipeline empty | The production app has no configured deployment steps. | `error.code=deploy.pipeline_empty` |
| Execution failed | The gateway cannot execute a step on the owning app node. | `error.code=deploy.execution_failed` |
| Step failed | A deployment step exits non-zero. | `error.code=deploy.step_failed` |
| History write failed | The gateway cannot persist the run or final run status. | `error.code=deploy.history_write_failed` |

## Doctor Relationship

Deployment run history is app-owned gateway state. `deploy:run` does not own a
doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) may use latest
deployment status when reporting `app.latest_deployment_failed` or
`app.deployment_run_stuck`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployRunCommandTest.php` | Command contract for production app lookup, app-node denial before prompts or side effects, authorization, empty pipeline failure, run creation, ordered step execution through the gateway from the app source path, timeout enforcement, metadata exposure, step-failure stop behavior, latest deployment status updates, detached handoff, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-run DTO shape, ordered step selection, app source path execution context, timeout metadata, execution context metadata, status taxonomy, captured output mapping, and latest deployment status mapping. |
