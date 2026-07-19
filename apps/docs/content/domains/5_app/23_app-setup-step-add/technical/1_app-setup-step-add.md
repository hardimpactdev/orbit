# Technical Contract: `orbit app-setup-step:add`

[Back to public `app-setup-step:add` documentation.](../app-setup-step-add.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app instance exists in gateway configuration.
- The authenticated peer has `app:write` on that instance's serving node.

## Signature

```bash
orbit app-setup-step:add [app] --command=<command> [--app=<app>] [--before=<id>|--after=<id>] [--timeout=600] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve one concrete app instance; bare shorthand is valid only for a sole instance. |
| `app_option` | `--app` | Optional. | Never. | None. | Must match `[app]` when both are present. |
| `command` | `--command` | Always in non-interactive mode. | Never. | Prompted interactively. | Non-empty finite shell command. |
| `before` | `--before` | Optional. | `--after` is present. | None. | Positive integer setup step id. |
| `after` | `--after` | Optional. | `--before` is present. | None. | Positive integer setup step id. |
| `timeout` | `--timeout` | Optional. | Never. | `600`. | Positive integer seconds. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Setup step creation rules

The command creates one setup-step record owned by the selected app instance.
It does not execute the command.

## Renderer Contracts

- [Human renderer](6.1_app-setup-step-add_output-render_human.md)
- [JSON renderer](6.2_app-setup-step-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| App instance required | A bare app selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=app_instance_required`. |
| Invalid position | `--before` and `--after` are both supplied. | `error.code=app_setup.invalid_position` |

## Doctor Relationship

Setup-step records are app bootstrap intent.
[`doctor --family=app`](../../app-doctor.md) does not create, remove, or run
setup steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/setup-steps` |
| Effect | `write` |
| Subject | `AppSetupStep` on success; `none` on validation or authorization failure. |
| Properties | `app`, `app_instance`, setup step command, order, and timeout. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI payload, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupStepControllerTest.php` | Authorized POST creates a setup step with command, order, timeout, and result action. |
