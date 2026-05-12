# Technical Contract: `orbit deploy:step-add [app] [command] [--title=<title>] [--order=<number>] [--timeout=<seconds>] [--retention=<count>] [--json]`

[Back to public `deploy-step-add` documentation.](../deploy-step-add.md)

**Owner:** `deploy`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- App-node callers are denied before prompts or side effects because deployment policy is app-owned gateway intent.

## Signature

```bash
orbit deploy:step-add [app] [command] [--title=<title>] [--order=<number>] [--timeout=<seconds>] [--retention=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production app the caller may manage. |
| `command` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Non-empty shell command or multiline shell script string. |
| `title` | `--title` | `Optional.` | `Never.` | Command-derived title. | Non-empty display label. |
| `order` | `--order` | `Optional.` | `Never.` | Next pipeline position. | Positive integer insertion order. |
| `timeout` | `--timeout` | `Optional.` | `Never.` | `600`. | Positive integer seconds. |
| `retention` | `--retention` | `Optional.` | `Never.` | `null` | Positive integer release-retention metadata. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Resolve input locally, then forward the policy write to the gateway when authorized. |
| `gateway` | `valid` | Execute the policy write locally on the gateway when authorized. |
| `app` | `invalid` | Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |
| `unknown` | `invalid` | Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-step-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-step-add_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Policy Rules

- Resolves the selected app through gateway app intent.
- Fails before side effects unless the selected app is production.
- Writes one deploy-step definition owned by the production app.
- Stores the step command exactly as provided. Context placeholders are not
  resolved during policy writes.
- Inserts the step at the selected order. When another step already has that
  order or a later order, the gateway increments those step orders by one and
  returns the reordered deployment pipeline.
- Stores the step timeout in seconds. `deploy:run` marks the step failed if
  execution exceeds this timeout.
- Stores optional retention metadata only on the step that declares it.
- Allows `{{ key }}` placeholders for deploy-run context values. Unknown or
  non-scalar placeholders are preserved and may fail later during execution.

### Execution Boundary Rules

- Does not execute the deployment step.
- Does not create deployment run history.
- Does not inspect live node state or app source state.
- Does not create process definitions, schedules, proxy routes, or global
  deployment retention policy.

## Renderer Contracts

- [Human renderer](6.1_deploy-step-add_output-render_human.md)
- [JSON renderer](6.2_deploy-step-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing or malformed; order, timeout, or retention is invalid. | `error.code=validation_failed` |
| Caller role not allowed | The caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage deployment policy for the app. | `error.code=authorization_failed` |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |

## Doctor Relationship

Deployment policy is app-owned gateway state. `deploy:step-add` does not own a
doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) may use deployment
policy when reporting `app.deployment_pipeline_invalid`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployStepAddCommandTest.php` | Command contract for input validation, production-app eligibility, app-node denial before prompts or side effects, authorization, deployment policy write, order insertion, timeout metadata, retention metadata, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-step DTO shape, production app resolution, order insertion rules, timeout validation, retention validation, and deploy-step entity mapping. |
