# Technical Contract: `orbit app:websocket credentials`

[Back to public `app:websocket credentials` documentation.](../app-websocket-credentials.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:credentials` on the selected app
  instance's serving node.
- The target resolves to one concrete app instance with an enabled WebSocket
  binding.

## Signature

```bash
orbit app:websocket credentials [app] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Dotted `<app.instance>` selector. A bare logical app is shorthand only when exactly one eligible visible instance exists for `app:credentials`; otherwise fail with `error.meta.reason=app_instance_required`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve exactly one app instance and serving node. Ambiguous shorthand fails
   before authorization or secret access.
2. Authorize `app:credentials` on that serving node, then read only that
   instance's binding.

## Behavior Contract

### Credentials Retrieval Rules

1. **Instance resolution.** Resolve one concrete instance; logical-app
   placement fields are never consulted.
2. **Binding required and enabled.** Require an existing binding with
   `enabled=true`. Return `websocket.binding_missing` when no binding exists or
   when the binding has `enabled=false`.
3. **Return credentials.** Return the full credentials payload from the stored
   binding: internal host, public hosts, allowed origins, `reverb_app_id`,
   `reverb_app_key`, and `reverb_app_secret` (decrypted from storage).

### Scope Boundaries

`app:websocket credentials` must not:
- Rotate, regenerate, or modify any credential values.
- Write to the binding record or any gateway state.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-websocket-credentials_output-render_human.md) |
| `--json` | [JSON output](6.2_app-websocket-credentials_output-render_json.md) |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/websocket/credentials` | `app:credentials` on instance serving node | Read only the selected instance's credentials. |

HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
`websocket.binding_missing`, `403` for permission denials.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply;
command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (app) | `app` is missing from the CLI invocation. | Failure — no gateway request sent. |
| App instance required | A bare selector resolves zero or multiple eligible instances. | `validation_failed` with `error.meta.reason=app_instance_required`. |
| App instance not found | No concrete app instance matches `app`. | `app_instance.not_found`. |
| WebSocket binding missing | The selected instance has no enabled binding. | Failure. |

## Doctor Relationship

`app:websocket credentials` is a read-only registry command. It does not probe
live Reverb reachability or verify runtime state. Binding configuration drift
belongs to `doctor --family=app`. See
[`app-doctor.md`](../../app-doctor.md) for the authoritative app-family probe
and repair contract; doctor semantics are not restated here.

## Activity Logging

The gateway API endpoint emits an activity entry for every credentials read.

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/websocket/credentials` |
| Effect | `read` |
| Subject | App instance resolved from `{app}`. |
| Properties | `action=credentials`, `target_app`, `target_app_instance`, and `serving_node`. Secret values are never logged. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Credentials payload shape, `reverb_app_key` and `reverb_app_secret` presence, `websocket.binding_missing` for absent binding and for disabled binding, authorization check, and `app.not_found` path. |
