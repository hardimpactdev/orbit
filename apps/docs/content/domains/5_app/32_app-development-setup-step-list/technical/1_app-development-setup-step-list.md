# Technical Contract: `orbit app-development-setup-step:list`

**Owner:** `app`. **Effects:** `read`.

**Prerequisites:** Gateway reachable, app exists, and caller has `app:read`.

## Signature

```bash
orbit app-development-setup-step:list [app] [--json]
```

The command resolves one app, checks `app:read` through a visible app
instance, and returns gateway-owned defaults sorted by `order`. It performs no
node probe, copy, execution, or mutation. Only new app-dev instance creation
consumes these defaults. Unknown apps return `app.not_found`; unauthorized
callers return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-list_output-render_human.md)
- [JSON](6.2_app-development-setup-step-list_output-render_json.md)

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model). The app selector and
renderer flags follow the signature above.

## Behavior Contract

The command reads ordered app defaults and performs no runtime work.

## Failure Semantics

Shared not-found and authorization failures apply.

## Doctor Relationship

[`doctor --family=instance`](../../instance-doctor.md) reports instance
runtime health. It does not inspect or copy app defaults.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/development-setup-steps` |
| Effect | `read` |
| Subject | `none` |
| Properties | App selector. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppDevelopmentSetupStepCommandTest.php` | Human table and gateway request shape. |
| `apps/gateway/tests/Feature/Http/Api/AppDevelopmentSetupStepControllerTest.php` | Authorized ordered list, envelopes, and activity logging. |
