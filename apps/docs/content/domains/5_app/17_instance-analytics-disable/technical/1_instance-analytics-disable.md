# Technical Contract: `orbit instance:analytics disable`

[Back to public `instance:analytics disable` documentation.](../instance-analytics-disable.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:write` on the selected instance's serving node.
- The target resolves to one concrete instance and its binding.

## Signature

```bash
orbit instance:analytics disable [instance] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app.instance]` | Always. | Never. | None. | Dotted selector; bare shorthand succeeds only for exactly one eligible visible instance, otherwise `instance_required`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Disable Rules

- Resolve one concrete instance and authorize its serving node; never use app placement.
- Return `instance.not_found` when no instance matches.
- Return `analytics.binding_missing` when that instance has no analytics binding.
- Remove active public `app-analytics` ingress and router artifacts, then their
  route rows.
- Set `enabled=false` and clear `public_hosts` only after cleanup succeeds.
- Leave the binding enabled with its previous hosts when cleanup fails.
- Keep the private `analytics.orbit` service route intact while an active
  analytics role exists.

### Scope Boundaries

`instance:analytics disable` must not delete Plausible data, stop Plausible CE,
remove the private analytics dashboard route, or change process versions.

## Renderer Contracts

- [Human renderer](6.1_instance-analytics-disable_output-render_human.md)
- [JSON renderer](6.2_instance-analytics-disable_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | Bare shorthand is ambiguous or ineligible. | `validation_failed` with `error.meta.reason=instance_required` |
| Instance not found | No concrete instance matches. | `error.code=instance.not_found` |
| Binding missing | The selected instance has no analytics binding. | `error.code=analytics.binding_missing` |
| Route cleanup failed | An ingress or router artifact cannot be removed. | `error.code=analytics.route_cleanup_failed` |

## Doctor Relationship

`instance:analytics disable` writes instance-owned binding intent and removes public
analytics route intent. [`doctor --family=instance`](../../instance-doctor.md) owns app
binding drift, and [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns route drift.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/{instance}/analytics/disable` |
| Effect | `write` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `action=disable`, `target_instance`, `target_instance`, and `serving_node`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Disable success, route removal, cleanup-failure rollback, binding-missing failure, authorization check, and `instance.not_found` path. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsDisableCommandTest.php` | CLI input validation, gateway request payload, progress tree, human output, and JSON passthrough. |
