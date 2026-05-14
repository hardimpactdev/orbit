# Technical Contract: `orbit deploy:step-list [app] [--json]`

[Back to public `deploy-step-list` documentation.](../deploy-step-list.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the WireGuard peer to inspect deployment policy for the selected app.

## Signature

```bash
orbit deploy:step-list [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required.` | `Never.` | `None.` | Visible production app the caller may inspect. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment Policy Visibility Rules

- Reads deployment step definitions for one production app from gateway app
  configuration.
- Returns steps ordered by `order` ascending.
- Returns an empty list for production apps with no configured deployment
  steps.
- Does not read deployment run history.

### Scope Boundaries

`deploy-step-list` must not create, update, remove, execute, or reorder deploy
steps. It must not inspect live node state. Deployment health belongs to
[`app-doctor.md`](../../../5_app/app-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_deploy-step-list_output-render_human.md)
- [JSON renderer](6.2_deploy-step-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |

## Doctor Relationship

`deploy:step-list` reads deployment policy that the app owns. It does not own a
doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) owns production
app health checks that may incorporate deployment pipeline validity.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployStepListCommandTest.php` | Command contract for production app lookup, authorization, ordered step listing, empty list behavior, read-only side-effect boundary, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-step DTO shape, app selector resolution, ordered list rules, and deploy-step entity mapping. |
