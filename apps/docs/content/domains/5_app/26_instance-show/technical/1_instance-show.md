# Technical Contract: `orbit instance:show`

[Back to public `instance:show` documentation.](../instance-show.md)

**Owner:** `instance`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The selected instance exists and is visible.

## Signature

```bash
orbit instance:show [instance] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted `app.instance` selector. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## State Model

The selected `Instance` owns placement and instance-specific configuration;
its `Project` owns shared logical identity and runtime defaults.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/instances/{instance}` | `instance:read` | Show one instance. |

## Behavior Contract

### Instance Show Rules

1. Require a dotted selector and resolve one concrete instance.
2. Authorize the serving node before returning Orbit placement.
3. Use the gateway-only authority path for external drivers.
4. Return registry and compatibility data without probing the runtime.

## Renderer Contracts

- [Human renderer](6.1_instance-show_output-render_human.md)
- [JSON renderer](6.2_instance-show_output-render_json.md)

## Failure Semantics

Missing input returns `validation_failed`. Unknown apps return
`app.not_found`; unknown instances return `instance.not_found`.

## Doctor Relationship

[`instance-doctor.md`](../../instance-doctor.md) owns live verification for the
selected placement.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/instances/{instance}` |
| Effect | `read` |
| Subject | Selected `Project`. |
| Properties | The API path carries the concrete instance identity. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppInstanceCommandTest.php` | Verifies dotted selector validation and human and JSON rendering. |
| `apps/gateway/tests/Feature/InstanceControllerTest.php` | Show authorization and payload shape. |
