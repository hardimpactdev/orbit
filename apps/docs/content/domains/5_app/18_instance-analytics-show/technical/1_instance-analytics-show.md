# Technical Contract: `orbit instance:analytics show`

[Back to public `instance:analytics show` documentation.](../instance-analytics-show.md)

**Owner:** `instance`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:read` on the selected instance's serving node.
- The target resolves to one concrete instance.

## Signature

```bash
orbit instance:analytics show [instance] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | `[app.instance]` | Always. | Never. | None. | Dotted selector; bare shorthand succeeds only for exactly one eligible visible instance, otherwise `instance_required`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Read Rules

- Resolve one concrete instance and authorize its serving node; never read project placement.
- Return `instance.not_found` when no instance matches.
- Return `analytics.binding_missing` when that instance has no binding.
- Return the stored binding state without probing route or Plausible runtime
  reality.
- Derive one tracking endpoint object per stored public host.

### Scope Boundaries

`instance:analytics show` must not create, update, remove, fix, adopt, or probe
analytics binding or proxy route artifacts.

## Renderer Contracts

- [Human renderer](6.1_instance-analytics-show_output-render_human.md)
- [JSON renderer](6.2_instance-analytics-show_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | Bare shorthand is ambiguous or ineligible. | `validation_failed` with `error.meta.reason=instance_required` |
| Instance not found | No concrete instance matches. | `error.code=instance.not_found` |
| Binding missing | The selected instance has no analytics binding. | `error.code=analytics.binding_missing` |

## Doctor Relationship

`instance:analytics show` reads binding configuration from the gateway only.
[`doctor --family=instance`](../../instance-doctor.md) owns app binding drift, and
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns analytics
route drift.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/analytics` |
| Effect | `read` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `target_instance`, `target_instance`, and `serving_node`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Show success, binding-missing failure, authorization check, and `instance.not_found` path. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsShowCommandTest.php` | CLI input validation, gateway request payload, human output, and JSON passthrough. |
