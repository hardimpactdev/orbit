# Technical Contract: `orbit deploy:step-remove [instance] [step] [--force] [--json]`

[Back to public `deploy-step-remove` documentation.](../deploy-step-remove.md)

**Owner:** `deploy`.

**Effects:** `write, destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:step` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:step-remove [instance] [step] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production instance selector. A bare app is valid only when it has exactly one instance. |
| `step` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Existing step id or exact title for the selected instance. |
| `force` | `--force` | `Required in non-interactive mode.` | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-step-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-step-remove_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Policy Removal Rules

- Resolves one concrete production instance through gateway configuration.
- Fails before side effects unless the selected instance belongs to a production app.
- Resolves one deployment step by id or exact title.
- Requires destructive consent before removing gateway policy.
- Removes the step definition from the instance's deployment pipeline.
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
| Instance not found | No visible instance matches the selector. | `error.code=instance.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |
| Instance required | A bare app has more than one instance. | `error.code=validation_failed`, `error.meta.reason=instance_required` |
| Step not found | No step matches the supplied id or title. | `error.code=deploy.step_not_found` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required` |

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /deploy/steps/{step}` |
| Effect | `destructive` |
| Subject | `DeployStep` when resolved; `none` otherwise. |
| Properties | `app`, `instance`, `step_id`, `title`. History is preserved. |
| Description | derived |

## Doctor Relationship

Deployment policy is instance-owned gateway state. `deploy:step-remove` does not own
a doctor family. [`instance-doctor.md`](../../../5_app/instance-doctor.md) may use
deployment policy when reporting `instance.deployment_pipeline_invalid`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | CLI `deploy:step-remove` destructive-consent gate before gateway contact, DELETE payload with `destructive_consent`, and JSON removed-step envelope with `history_preserved` metadata. |
| `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | Interactive app and step prompts plus destructive confirmation before DELETE. |
| `apps/gateway/tests/Feature/Actions/Deploy/DeployStepActionsTest.php` | Gateway step removal and order compaction after deletion. |

Coverage gaps until focused tests land: production-app eligibility, grant denial before side effects, step lookup failures, exhaustive documented `error.code` values, and instance-doctor handoff behavior.
