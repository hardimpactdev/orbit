# Technical Contract: `orbit deploy:step-list [instance] [--json]`

[Back to public `deploy-step-list` documentation.](../deploy-step-list.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:read` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:step-list [instance] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `argument` | `Required.` | `Never.` | `None.` | Visible production instance selector. A bare project is valid only when it has exactly one instance. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment Policy Visibility Rules

- Reads deployment step definitions for one concrete production instance
  from gateway state.
- Returns steps ordered by `order` ascending.
- Returns an empty list for production instances with no configured deployment
  steps.
- Does not read deployment run history.

### Scope Boundaries

`deploy-step-list` must not create, update, remove, execute, or reorder deploy
steps. It must not inspect live node state. Deployment health belongs to
[`instance-doctor.md`](../../../5_project/instance-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_deploy-step-list_output-render_human.md)
- [JSON renderer](6.2_deploy-step-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No visible instance matches the selector. | `error.code=instance.not_found` |
| Production project required | The app exists but is not a production project. | `error.code=deploy.production_project_required` |
| Instance required | A bare project has more than one instance. | `error.code=validation_failed`, `error.meta.reason=instance_required` |

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /deploy/steps` |
| Effect | `read` |
| Subject | `none` (list). |
| Properties | `project`, `instance`, `count`. |
| Description | derived |

## Doctor Relationship

`deploy:step-list` reads deployment policy that the instance owns. It does not own a
doctor family. [`instance-doctor.md`](../../../5_project/instance-doctor.md) owns production
app health checks that may incorporate deployment pipeline validity.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | CLI `deploy:step-list` GET forwarding, JSON envelope and metadata, human table output with ordered step cells, empty list behavior, missing-instance validation before gateway contact, and gateway `authorization_failed` and `instance.not_found` passthrough. |
| `apps/gateway/tests/Feature/Http/Api/DeployControllerTest.php` | Gateway authorized empty deployment-step listing for callers with `deploy:read`. |

Coverage gaps until focused tests land: production-app eligibility, read-only side-effect boundary proof, exhaustive documented `error.code` values, and instance-doctor handoff behavior.
