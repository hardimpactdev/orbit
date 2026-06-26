# Technical Contract: `orbit deploy:run [app] [--detach] [--json|--stream-json]`

[Back to public `deploy-run` documentation.](../deploy-run.md)

**Owner:** `deploy`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:run` on the production app's owning node.
- The gateway can reach the selected production app's owning node.

## Signature

```bash
orbit deploy:run [app] [--detach] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production app the caller may deploy. |
| `detach` | `--detach` | `Optional.` | `Never.` | `false` | Boolean flag. Starts the run and returns without streaming output. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |
| `stream-json` | `--stream-json` | `Optional.` | `Never.` | `false` | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-run_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-run_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Run Lifecycle

- Resolves the selected app through gateway app configuration.
- Uses one gateway API action, `POST /api/deploy/run`. JSON callers receive the
  normal JSON response; interactive client callers request that same
  action with `Accept: text/event-stream` and render streamed progress locally.
- Fails before side effects unless the selected app is production.
- Reads the app's deployment steps ordered by `order` ascending.
- Fails before side effects when the production app has no deployment steps.
- Creates a durable deployment run with `status=running` before executing the
  first step.
- Generates and stores a deployment run context before executing the first
  step. The context includes scalar values for `release`, `app_path`,
  `releases_path`, `release_path`, `live_path`, `env_path`, `storage_path`,
  `database_path`, `app_user`, `app_name`, `domain`, and `repository`, plus
  nested app and node metadata for placeholder resolution.
- Executes each configured step on the app's owning node through the gateway.
- Release-aware steps may create versioned release directories under
  `releases_path` and switch `live_path` to a release inside that app-owned
  boundary. They must not point `live_path`, document root, storage,
  database, or any runtime bind mount at paths outside the app source,
  `releases_path`, or explicitly managed shared paths.
- Executes all steps from the app source path on the host node. Steps that
  invoke `php`, `composer`, or `artisan` use the host PHP toolchain matched to
  the app's PHP version (`php8.4`/`php8.5`) when `runtime=php`.
- Renders `{{ key }}` placeholders against the deployment run context before
  execution. Dot notation may address nested context values such as
  `{{ app.name }}`.
- Exports scalar context values as `ORBIT_DEPLOY_*` environment variables for
  each step.
- Executes every step script in a fresh shell with `set -e` applied by Orbit.
  Shell state such as `cd`, local variables, and exported values inside a step
  persist only until that step exits; separate deployment steps must use run
  context placeholders or environment variables instead of relying on shell
  state from previous steps.
- Exposes deploy-step metadata, including `retention`, to the step execution
  context. Orbit does not apply global deployment retention outside the step
  that declares it.
- Enforces the step timeout stored on the deploy-step definition.
- Captures stdout, stderr, process status, timing, and the rendered command
  for every executed step, so deploy logs show the script that actually
  executed for that run.
- Stops at the first failed step and does not execute later steps.
- After all configured steps complete successfully for a PHP app, runs built-in
  production warmup on the host PHP toolchain (matched to the app's PHP
  version): `composer install --no-dev --optimize-autoloader --no-interaction`
  and `php artisan optimize`, against the app source or active release the
  FrankenPHP runtime container serves.
- When the app defines `deploy_warmup_paths`, sends HTTP warmup requests to
  those paths on the app runtime endpoint before the deployment is marked
  complete.
- Updates the run status and the app's latest deployment status to
  `completed`, `failed`, or `cancelled`.

### Detached Run Rules

- `--detach` creates the deployment run and starts execution under gateway
  control.
- Detached runs return after the run is durable and execution has been handed
  off to the gateway.
- Detached runs do not stream step output after handing execution to the gateway.
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

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Pipeline empty | The production app has no configured deployment steps. | `error.code=deploy.pipeline_empty` |
| Execution failed | The gateway cannot execute a step on the owning node. | `error.code=deploy.execution_failed` |
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
| `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | CLI `deploy:run` detached stream POST to `/api/deploy/run`, missing-app validation before gateway contact, and gateway authorization error passthrough. |
| `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | Interactive app prompt before foreground stream POST with `detach=false`. |
| `apps/cli/tests/Feature/Commands/Deploy/DeployRunStreamCommandTest.php` | Malformed deploy-run progress-stream frame handling. |
| `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php` | Gateway deploy execution routing for PHP/Composer/Artisan commands, host working-directory and environment context, built-in warmup steps, HTTP warmup paths, warmup skip on user-step failure, and failed-run status when warmup fails. |

Coverage gaps until focused tests land: production-app eligibility, grant denial before side effects, empty-pipeline failure, run-history creation semantics, timeout enforcement, progress-tree rendering, foreground success summaries beyond the completion footer, exhaustive documented `error.code` values, step-failure stop behavior, history-write failures, latest-deployment status updates, and app-doctor handoff behavior.
