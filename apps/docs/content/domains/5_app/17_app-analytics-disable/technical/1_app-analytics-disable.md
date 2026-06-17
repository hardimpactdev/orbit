# Technical Contract: `orbit app:analytics disable`

[Back to public `app:analytics disable` documentation.](../app-analytics-disable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the app's owning node.
- The target app exists in the gateway registry.

## Signature

```bash
orbit app:analytics disable [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Disable Rules

- Resolve the app by name or hostname.
- Return `app.not_found` when no app matches.
- Return `analytics.binding_missing` when the app has no analytics binding.
- Set `enabled=false`.
- Clear `public_hosts`.
- Remove active public `app-analytics` routes for the binding.
- Keep the private `analytics.orbit` service route intact while an active
  analytics role exists.

### Scope Boundaries

`app:analytics disable` must not delete Plausible data, stop Plausible CE,
remove the private analytics dashboard route, or change process versions.

## Renderer Contracts

- [Human renderer](6.1_app-analytics-disable_output-render_human.md)
- [JSON renderer](6.2_app-analytics-disable_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| Binding missing | The app has no analytics binding. | `error.code=analytics.binding_missing` |

## Doctor Relationship

`app:analytics disable` writes app-owned binding intent and removes public
analytics route intent. [`doctor --family=app`](../../app-doctor.md) owns app
binding drift, and [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns route drift.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/analytics/disable` |
| Effect | `write` |
| Subject | App record resolved from `{app}`. |
| Properties | `action=disable` and `target_app`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Disable success, route removal, binding-missing failure, authorization check, and `app.not_found` path. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsDisableCommandTest.php` | CLI input validation, gateway request payload, human output, and JSON passthrough. |
