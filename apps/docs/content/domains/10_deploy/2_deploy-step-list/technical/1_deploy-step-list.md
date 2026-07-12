# Technical Contract: `orbit deploy:step-list [app] [--json]`

[Back to public `deploy-step-list` documentation.](../deploy-step-list.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:read` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:step-list [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required.` | `Never.` | `None.` | Visible production app-instance selector. A bare app is valid only when it has exactly one instance. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment Policy Visibility Rules

- Reads deployment step definitions for one concrete production app instance
  from gateway state.
- Returns steps ordered by `order` ascending.
- Returns an empty list for production app instances with no configured deployment
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
| App instance required | A bare app has more than one instance. | `error.code=validation_failed`, `error.meta.reason=app_instance_required` |

## Doctor Relationship

`deploy:step-list` reads deployment policy that the app instance owns. It does not own a
doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) owns production
app health checks that may incorporate deployment pipeline validity.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | CLI `deploy:step-list` GET forwarding, JSON envelope and metadata, human table output with ordered step cells, empty list behavior, missing-app validation before gateway contact, and gateway `authorization_failed` and `app.not_found` passthrough. |
| `apps/gateway/tests/Feature/Http/Api/DeployControllerTest.php` | Gateway authorized empty deployment-step listing for callers with `deploy:read`. |

Coverage gaps until focused tests land: production-app eligibility, read-only side-effect boundary proof, exhaustive documented `error.code` values, and app-doctor handoff behavior.
