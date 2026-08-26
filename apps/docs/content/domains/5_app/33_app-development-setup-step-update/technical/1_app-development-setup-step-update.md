# Technical Contract: `orbit app-development-setup-step:update`

**Owner:** `app`. **Effects:** `write`.

**Prerequisites:** Gateway reachable, app and step exist, and caller has `app:write`.

## Signature

The command updates the selected app-owned default by ID. It accepts the
signature shown on the public page; supplied fields replace command, order, or
timeout, and omitted fields remain unchanged. It writes no instance rows and
does not execute or migrate existing pipelines. New app-dev instances use the
updated defaults. Unknown IDs return `app.setup_step_not_found`; denied calls
return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-update_output-render_human.md)
- [JSON](6.2_app-development-setup-step-update_output-render_json.md)

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model). The step ID and
replacement fields follow the public signature.

## Behavior Contract

The command updates app policy only; existing instance rows remain unchanged.

## Failure Semantics

Shared validation, not-found, and authorization failures apply.

## Doctor Relationship

[`doctor --family=instance`](../../instance-doctor.md) reports instance
runtime health. It does not edit or copy app defaults.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:PATCH /apps/{app}/development-setup-steps/{step}` |
| Effect | `write` |
| Subject | `AppDevelopmentSetupStep` on success; `none` on failure. |
| Properties | App selector and setup step id. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppDevelopmentSetupStepCommandTest.php` | Typed update request and fail-closed input validation. |
| `apps/gateway/tests/Feature/Http/Api/AppDevelopmentSetupStepControllerTest.php` | Authorized updates, ordering, envelopes, and activity logging. |
