# Technical Contract: `orbit instance-setup-step:list`

[Back to public `instance-setup-step:list` documentation.](../instance-setup-step-list.md)

**Owner:** `instance`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target instance exists in gateway configuration.
- The authenticated peer has `instance:read` on that instance's serving node.

## Signature

```bash
orbit instance-setup-step:list [instance] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Must resolve one concrete instance; bare shorthand is valid only for a sole instance. |
| `instance_option` | `--instance` | Optional. | Never. | None. | Must match `[instance]` when both are present. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Setup step list rules

The command returns setup steps for the resolved instance ordered by
`sort_order`. It does not run remote work.

## Renderer Contracts

- [Human renderer](6.1_instance-setup-step-list_output-render_human.md)
- [JSON renderer](6.2_instance-setup-step-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No concrete instance matches `instance`. | `error.code=instance.not_found` |
| Instance required | A bare project selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=instance_required`. |

## Doctor Relationship

Setup-step records are app bootstrap intent.
[`doctor --family=instance`](../../instance-doctor.md) does not create, remove, or run
setup steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/setup-steps` |
| Effect | `read` |
| Subject | `none` (read-only list). |
| Properties | `project`, `instance`, and setup step count. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI request shape and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupStepControllerTest.php` | API list payload and authorization. |
