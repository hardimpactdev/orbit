# Technical Contract: `orbit app:setup`

[Back to public `app:setup` documentation.](../app-setup.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists in gateway configuration.
- The authenticated peer has `app:write` on the app's owning node.

## Signature

```bash
orbit app:setup [app] [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. |
| `json` | `--json` | Optional. | `--stream-json` is present. | `false`. | Selects the JSON renderer. |
| `stream_json` | `--stream-json` | Optional. | `--json` is present. | `false`. | Selects the JSONL progress renderer. |

## Behavior Contract

### Setup run rules

1. Resolves the app and owning node.
2. Loads setup steps ordered by `sort_order`.
3. Returns a skipped result when no setup steps exist.
4. Returns the latest completed run when its step-set hash matches.
5. Creates a setup run when execution is needed.
6. Routes PHP, Composer, and Artisan commands through the app host PHP toolchain.
7. Stops at the first failed setup step.
8. Stores per-step result status and captured output.

## Renderer Contracts

- [Human renderer](6.1_app-setup_output-render_human.md)
- [JSON renderer](6.2_app-setup_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| Setup step failed | A setup command exits non-zero. | `error.code=app.setup_failed` |

## Doctor Relationship

App setup is lifecycle-specific app bootstrap. Doctor does not replay setup
runs. App runtime drift remains owned by [`doctor --family=app`](../../app-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/setup` |
| Effect | `write` |
| Subject | `App` on success; `none` on validation or authorization failure. |
| Properties | `app` and setup run status. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI request shape, stream mode, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupControllerTest.php` | API setup execution, idempotent skip, and authorization. |
| `apps/gateway/tests/Unit/Services/Apps/AppSetupStepRunnerTest.php` | Step routing, output capture, failure stop, and run status. |
