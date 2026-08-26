# Technical Contract: `orbit app-development-setup-step:remove`

**Owner:** `app`. **Effects:** `write`.

**Prerequisites:** Gateway reachable, app and step exist, and caller has `app:write`.

## Signature

The command removes one app-owned default by ID after `--force` consent and
`app:write` authorization through a visible app instance. It does not contact
nodes, execute a step, or alter any existing instance pipeline. New app-dev
instances no longer receive the removed default. Unknown IDs return
`app.setup_step_not_found`; missing consent returns the shared destructive
confirmation failure.

## Renderers

- [Human](6.1_app-development-setup-step-remove_output-render_human.md)
- [JSON](6.2_app-development-setup-step-remove_output-render_json.md)

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model). The step ID and
destructive consent follow the public signature.

## Behavior Contract

The command removes app policy only; existing instance rows remain unchanged.

## Failure Semantics

Shared consent, not-found, and authorization failures apply.

## Doctor Relationship

[`doctor --family=instance`](../../instance-doctor.md) reports instance
runtime health. It does not remove app defaults or existing instance steps.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /apps/{app}/development-setup-steps/{step}` |
| Effect | `destructive` |
| Subject | `AppDevelopmentSetupStep` on success; `none` on failure. |
| Properties | App selector and removed setup step id. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppDevelopmentSetupStepCommandTest.php` | Forced destructive consent and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppDevelopmentSetupStepControllerTest.php` | Authorized removal, ordering, envelopes, and activity logging. |
