# Technical Contract: `orbit instance:websocket disable`

[Back to public `instance:websocket disable` documentation.](../instance-websocket-disable.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:write` on the selected instance's
  serving node.
- The target resolves to one concrete instance with an existing WebSocket
  binding.

## Signature

```bash
orbit instance:websocket disable [instance] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted `<project.instance>` selector. A bare project is shorthand only when exactly one eligible visible instance exists; otherwise fail with `error.meta.reason=instance_required`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve exactly one instance and its serving node. Ambiguous or missing
   bare shorthand fails before authorization.
2. Authorize `instance:write` on that serving node, then forward the request.

## Behavior Contract

### WebSocket Disable Rules

1. **Instance resolution.** Resolve one concrete instance; project
   placement fields are never consulted.
2. **Binding required.** Require an existing binding for that instance. Return
   `websocket.binding_missing` when none exists.
3. **Binding state.** Set `enabled=false` and clear `public_hosts` to `[]`.
   The `reverb_app_id`, `reverb_app_key`, `reverb_app_secret`, and
   `allowed_origins` fields are preserved unchanged.
4. **Route sync.** Sync public host routes so the cleared `public_hosts` list
   takes effect on the router node.
5. **Runtime sync.** Sync the Reverb runtime project configuration to remove this
   app from the running Reverb node.

### Scope Boundaries

`instance:websocket disable` must not:
- Delete the binding record or its Reverb credentials.
- Clear `allowed_origins`.
- Touch app source, node SSH configuration, or non-WebSocket routes.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_instance-websocket-disable_output-render_human.md) |
| `--json` | [JSON output](6.2_instance-websocket-disable_output-render_json.md) |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/instances/{instance}/websocket/disable` | `instance:write` on instance serving node | Disable the selected instance binding. |

The request body is empty for disable.

HTTP status codes: `200` for success, `404` for `instance.not_found`, `422` for
`websocket.binding_missing`, `403` for permission denials.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply;
command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (app) | `app` is missing from the CLI invocation. | Failure — no gateway request sent. |
| Instance required | A bare selector resolves zero or multiple eligible instances. | `validation_failed` with `error.meta.reason=instance_required`. |
| Instance not found | No concrete instance matches `app`. | `instance.not_found`. |
| WebSocket binding missing | The selected instance has no WebSocket binding record. | Failure — no state written. |

## Doctor Relationship

Binding drift — a disabled binding with stale router routes or runtime entries
still present — belongs to `doctor --family=instance`. See
[`instance-doctor.md`](../../instance-doctor.md) for the authoritative app-family probe
and repair contract; doctor semantics are not restated here.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
disable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{instance}/websocket/disable` |
| Effect | `write` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `action=disable`, `target_instance`, `target_instance`, `serving_node`, and `public_hosts=[]`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Disable binding state mutation, `public_hosts` cleared to `[]`, credential preservation, `websocket.binding_missing` path, authorization check, and `instance.not_found` path. |
| `apps/gateway/tests/Unit/Services/WebSockets/WebSocketBindingServiceTest.php` | Service-level disable state transition and credential retention across disable. |
