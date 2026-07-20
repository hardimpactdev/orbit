# Technical Contract: `orbit instance-setup-step:remove`

[Back to public `instance-setup-step:remove` documentation.](../instance-setup-step-remove.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target instance exists in gateway configuration.
- The authenticated peer has `instance:write` on that instance's serving node.

## Signature

```bash
orbit instance-setup-step:remove [instance] --step=<id> [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Must resolve one concrete instance; bare shorthand is valid only for a sole instance. |
| `instance_option` | `--instance` | Optional. | Never. | None. | Must match `[instance]` when both are present. |
| `step` | `--step` | Always in non-interactive mode. | Never. | Prompted interactively. | Positive integer setup step id. |
| `force` | `--force` | Required in non-interactive mode. | Never. | `false`. | Confirms destructive removal. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Setup step removal rules

The command removes one setup-step record from the selected instance and
preserves that instance's existing setup run history.

## Renderer Contracts

- [Human renderer](6.1_instance-setup-step-remove_output-render_human.md)
- [JSON renderer](6.2_instance-setup-step-remove_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No project record matches `app`. | `error.code=instance.not_found` |
| Instance required | A bare project selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=instance_required`. |
| Step not found | No setup step matches `step`. | `error.code=instance.setup_step_not_found` |

## Doctor Relationship

Setup-step records are app bootstrap intent.
[`doctor --family=instance`](../../instance-doctor.md) does not create, remove, or run
setup steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /apps/{instance}/setup-steps/{step}` |
| Effect | `write` |
| Subject | `App` on success; `none` on validation or authorization failure. |
| Properties | `app`, `instance`, removed setup step id, and title. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI request shape, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupStepControllerTest.php` | Authorized DELETE with destructive consent removes the selected step and returns remaining count. |
