# Technical Contract: `orbit app:analytics show`

[Back to public `app:analytics show` documentation.](../app-analytics-show.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:read` on the app's owning node.
- The target app exists in the gateway registry.

## Signature

```bash
orbit app:analytics show [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Read Rules

- Resolve the app by name or hostname.
- Return `app.not_found` when no app matches.
- Return `analytics.binding_missing` when the app has no analytics binding.
- Return the stored binding state without probing route or Plausible runtime
  reality.

### Scope Boundaries

`app:analytics show` must not create, update, remove, fix, adopt, or probe
analytics binding or proxy route artifacts.

## Renderer Contracts

- [Human renderer](6.1_app-analytics-show_output-render_human.md)
- [JSON renderer](6.2_app-analytics-show_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| Binding missing | The app has no analytics binding. | `error.code=analytics.binding_missing` |

## Doctor Relationship

`app:analytics show` reads binding configuration from the gateway only.
[`doctor --family=app`](../../app-doctor.md) owns app binding drift, and
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns analytics
route drift.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/analytics` |
| Effect | `read` |
| Subject | App record resolved from `{app}`. |
| Properties | `target_app`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Show success, binding-missing failure, authorization check, and `app.not_found` path. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsShowCommandTest.php` | CLI input validation, gateway request payload, human output, and JSON passthrough. |
