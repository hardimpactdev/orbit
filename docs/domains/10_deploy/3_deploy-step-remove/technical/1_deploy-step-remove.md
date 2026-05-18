# Technical Contract: `orbit deploy:step-remove [app] [step] [--force] [--json]`

[Back to public `deploy-step-remove` documentation.](../deploy-step-remove.md)

**Owner:** `deploy`.

**Effects:** `write, destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- App-node callers are denied by the gateway before prompts or side effects because deployment policy is app-owned gateway configuration.

## Signature

```bash
orbit deploy:step-remove [app] [step] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production app the caller may manage. |
| `step` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Existing step id or exact title for the selected app. |
| `force` | `--force` | `Required in non-interactive mode.` | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-step-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-step-remove_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Policy Removal Rules

- Resolves the selected app through gateway app configuration.
- Fails before side effects unless the selected app is production.
- Resolves one deployment step by id or exact title.
- Requires destructive consent before removing gateway policy.
- Removes the step definition from the app deployment pipeline.
- Reorders remaining steps to keep a stable ascending order.

### History Boundary Rules

- Does not mutate deployment run history.
- Does not delete deployment logs for previous runs.
- Does not inspect node state or stop any running deployment.

## Renderer Contracts

- [Human renderer](6.1_deploy-step-remove_output-render_human.md)
- [JSON renderer](6.2_deploy-step-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Step not found | No step matches the supplied id or title. | `error.code=deploy.step_not_found` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=destructive_consent_required` |

## Doctor Relationship

Deployment policy is app-owned gateway state. `deploy:step-remove` does not own
a doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) may use
deployment policy when reporting `app.deployment_pipeline_invalid`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployStepRemoveCommandTest.php` | Command contract for production app lookup, app-node denial before prompts or side effects, authorization, step lookup, destructive consent, policy removal, history preservation, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-step DTO shape, step lookup by id or title, destructive consent mapping, reorder rules, and removed step entity mapping. |
