# Technical Contract: `orbit instance-setup-step:add`

[Back to public `instance-setup-step:add` documentation.](../instance-setup-step-add.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target instance exists in gateway configuration.
- The authenticated peer has `instance:write` on that instance's serving node.

## Signature

```bash
orbit instance-setup-step:add [instance] --command=<command> [--before=<id>|--after=<id>] [--timeout=600] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Must resolve one concrete instance; bare shorthand is valid only for a sole instance. |
| `instance_option` | `--instance` | Optional. | Never. | None. | Must match `[instance]` when both are present. |
| `command` | `--command` | Always in non-interactive mode. | Never. | Prompted interactively. | Non-empty finite shell command. |
| `before` | `--before` | Optional. | `--after` is present. | None. | Positive integer setup step id. |
| `after` | `--after` | Optional. | `--before` is present. | None. | Positive integer setup step id. |
| `timeout` | `--timeout` | Optional. | Never. | `600`. | Positive integer seconds. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Setup step creation rules

The command creates one setup-step record owned by the selected instance.
It does not execute the command.

## Renderer Contracts

- [Human renderer](6.1_instance-setup-step-add_output-render_human.md)
- [JSON renderer](6.2_instance-setup-step-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No concrete instance matches `instance`. | `error.code=instance.not_found` |
| Instance required | A bare project selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=instance_required`. |
| Invalid position | `--before` and `--after` are both supplied. | `error.code=instance.invalid_position` |

## Doctor Relationship

Setup-step records are app bootstrap intent.
[`doctor --family=instance`](../../instance-doctor.md) does not create, remove, or run
setup steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/{instance}/setup-steps` |
| Effect | `write` |
| Subject | `AppSetupStep` on success; `none` on validation or authorization failure. |
| Properties | `project`, `instance`, setup step command, order, and timeout. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI payload, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupStepControllerTest.php` | Authorized POST creates a setup step with command, order, timeout, and result action. |
