# Technical Contract: `orbit app:analytics show`

[Back to public `app:analytics show` documentation.](../app-analytics-show.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:read` on the selected instance's serving node.
- The target resolves to one concrete app instance.

## Signature

```bash
orbit app:analytics show [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app.instance]` | Always. | Never. | None. | Dotted selector; bare shorthand succeeds only for exactly one eligible visible instance, otherwise `app_instance_required`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Read Rules

- Resolve one concrete app instance and authorize its serving node; never read logical-app placement.
- Return `app_instance.not_found` when no instance matches.
- Return `analytics.binding_missing` when that instance has no binding.
- Return the stored binding state without probing route or Plausible runtime
  reality.
- Derive one tracking endpoint object per stored public host.

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
| App instance required | Bare shorthand is ambiguous or ineligible. | `validation_failed` with `error.meta.reason=app_instance_required` |
| App instance not found | No concrete instance matches. | `error.code=app_instance.not_found` |
| Binding missing | The selected instance has no analytics binding. | `error.code=analytics.binding_missing` |

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
| Subject | App instance resolved from `{app}`. |
| Properties | `target_app`, `target_app_instance`, and `serving_node`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Show success, binding-missing failure, authorization check, and `app.not_found` path. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsShowCommandTest.php` | CLI input validation, gateway request payload, human output, and JSON passthrough. |
