# Technical Contract: `orbit app-setup-step:list`

[Back to public `app-setup-step:list` documentation.](../app-setup-step-list.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists in gateway configuration.
- The authenticated peer has `app:read` on the app's owning node.

## Signature

```bash
orbit app-setup-step:list [app] [--app=<app>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record. |
| `app_option` | `--app` | Optional. | Never. | None. | Must match `[app]` when both are present. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Setup step list rules

The command returns setup steps for the resolved app ordered by `sort_order`.
It does not run remote work.

## Renderer Contracts

- [Human renderer](6.1_app-setup-step-list_output-render_human.md)
- [JSON renderer](6.2_app-setup-step-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |

## Doctor Relationship

Setup-step records are app bootstrap intent.
[`doctor --family=app`](../../app-doctor.md) does not create, remove, or run
setup steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/setup-steps` |
| Effect | `read` |
| Subject | `App` on success; `none` on validation or authorization failure. |
| Properties | `app` and setup step count. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI request shape and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupStepControllerTest.php` | API list payload and authorization. |
