# Technical Contract: `orbit app-development-setup-step:add`

**Owner:** `app`. **Effects:** `write`.

**Prerequisites:** Gateway reachable, app exists, and caller has `app:write`.

## Signature

```bash
orbit app-development-setup-step:add [app] --command=<command> [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

`--command` is non-empty; `--before` and `--after` are mutually exclusive
positive step IDs; `--timeout` is positive seconds and defaults to `600`;
`--json` selects JSON. The caller needs `app:write`, checked through a visible
app instance.

The gateway writes one ordered app-owned default. It contacts no node and does
not execute the command. New `app-dev` instances copy the row into their
existing instance setup pipeline. App-prod instances do not copy it. Invalid
input returns `validation_failed`; unknown apps return `app.not_found`; denied
calls return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-add_output-render_human.md)
- [JSON](6.2_app-development-setup-step-add_output-render_json.md)

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model). The app selector and
flags follow the signature above.

## Behavior Contract

The command writes one app-owned default and does not execute it.

## Failure Semantics

Shared validation, not-found, authorization, and consent failures apply.

## Doctor Relationship

[`doctor --family=instance`](../../instance-doctor.md) reports instance
runtime health. It does not create, remove, or copy app defaults.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/development-setup-steps` |
| Effect | `write` |
| Subject | `AppDevelopmentSetupStep` on success; `none` on failure. |
| Properties | App selector and setup step id. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppDevelopmentSetupStepCommandTest.php` | CLI request shape, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppDevelopmentSetupStepControllerTest.php` | Authorized creation, ordering, envelopes, and activity logging. |
