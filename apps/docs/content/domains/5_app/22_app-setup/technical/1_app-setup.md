# Technical Contract: `orbit app:setup`

[Back to public `app:setup` documentation.](../app-setup.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app instance exists in gateway configuration and resolves an Orbit
  serving node.
- The authenticated peer has `app:write` on that instance's serving node.

## Signature

```bash
orbit app:setup [app] [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Dotted app-instance selector. A bare name or hostname auto-resolves only a sole instance; otherwise fail with `validation_failed` and `meta.reason=app_instance_required`. |
| `json` | `--json` | Optional. | `--stream-json` is present. | `false`. | Selects the JSON renderer. |
| `stream_json` | `--stream-json` | Optional. | `--json` is present. | `false`. | Selects the JSONL progress renderer. |

## Behavior Contract

### Setup run rules

1. Resolves one concrete app instance and its serving node. Logical app
   node/path/root/domain defaults are never runtime placement.
2. Loads that instance's setup steps ordered by `sort_order`.
3. Returns a skipped result when no setup steps exist.
4. Returns the latest completed run when its step-set hash matches.
5. Creates an app-instance-owned setup run when execution is needed.
6. Routes setup commands through the app user's host tool path on the selected
   instance, including its serving node's host PHP toolchain for PHP commands.
7. Dispatches each routed setup command through typed `internal:app-setup-step` over agent-push on agent-capable nodes. Setup environment values travel only in the token-bound stdin payload, not in transport metadata or activity summaries.
8. Stops at the first failed setup step.
9. Stores per-step result status and captured output.

### Setup Step Environment

Setup steps run on the selected instance's serving node and path and receive
lifecycle variables for that placement plus Laravel Vite-compatible URL and
TLS fields.

| Variable | Value | Why it is exposed |
| --- | --- | --- |
| `ORBIT_APP` | App slug | Lets scripts identify the app being set up. |
| `ORBIT_APP_INSTANCE` | App instance name | Lets scripts distinguish placements for one logical app. |
| `ORBIT_APP_PATH` | Selected instance root path | Lets scripts use the concrete path without recomputing it. |
| `ORBIT_URL` | Selected instance HTTPS URL | Lets scripts write canonical URL config such as `.env` values. |
| `ORBIT_PHP_VERSION` | App PHP version | Lets scripts run PHP-version-specific setup. |
| `APP_URL` | App HTTPS URL | Gives Laravel and framework tooling the canonical public URL. |
| `VITE_APP_URL` | App HTTPS URL | Keeps Vite-aware app config aligned with the app URL. |
| `VITE_VALET_HOST` | App host without scheme | Supports Herd/Valet-style Laravel Vite configuration that keys off a host. |
| `VITE_DEV_SERVER_KEY` | Orbit-managed TLS key path on the app node | Lets Laravel Vite use Orbit cert material through its standard env bridge. |
| `VITE_DEV_SERVER_CERT` | Orbit-managed TLS cert path on the app node | Lets Laravel Vite use Orbit cert material through its standard env bridge. |

## Renderer Contracts

- [Human renderer](6.1_app-setup_output-render_human.md)
- [JSON renderer](6.2_app-setup_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| App instance required | A bare app selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=app_instance_required`. |
| Setup step failed | A setup command exits non-zero. | `error.code=app.setup_failed` |

## Doctor Relationship

App setup is lifecycle-specific app bootstrap. Doctor does not replay setup
runs. App runtime drift remains owned by [`doctor --family=app`](../../app-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/setup` |
| Effect | `write` |
| Subject | `AppInstance` on success; `none` on validation or authorization failure. |
| Properties | `app`, `app_instance`, and setup run status. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppSetupCommandTest.php` | CLI request shape, stream mode, validation, and rendering. |
| `apps/gateway/tests/Feature/Http/Api/AppSetupControllerTest.php` | API setup execution, idempotent skip, and authorization. |
| `apps/gateway/tests/Unit/Services/Apps/AppSetupStepRunnerTest.php` | Step routing, agent-push dispatch, output capture, failure stop, and run status. |
| `apps/cli/tests/Feature/InternalAppSetupStepCommandTest.php` | Token rejection, payload validation, success/failure output capture, timeout handling, and forbidden env keys. |
