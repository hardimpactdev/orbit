# Technical Contract: `orbit app:worker show|enable|disable [app]`

[Back to public `app:worker` documentation.](../app-worker.md)

**Owner:** `app`.

**Effects:** `read` for `show`; `write` for `enable` and `disable`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `app:read` on the app's owning node for `show`,
  and `app:worker` on the app's owning node for `enable` and `disable`.
- The target app exists in gateway configuration.
- For `enable`, the owning node is reachable so the readiness probe can run
  against the installed app source.

## Signature

```bash
orbit app:worker {action} [app] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be one of `show`, `enable`, `disable`. |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins; the hostname match is consulted only when no name match exists. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `action`. Reject any value other than `show`, `enable`, or
   `disable` with `validation_failed`.
2. Resolve `app` from `[app]`. When omitted, an interactive prompt selects an
   app the caller can see; non-interactive callers without an `[app]`
   argument fail with `validation_failed`.
3. Validate the target app exists in gateway configuration.

## State Model

Gateway-owned app configuration stores two structural fields for worker mode:

- `worker_enabled` (boolean): defaults to `false` for every app and is the
  on/off switch.
- `worker_config` (object | `null`): defaults to `null`. Populated on first
  successful `enable` with the worker policy and retained across `disable`
  so re-enabling restores the prior configuration.

The default `worker_config` written on first enable is:

```json
{
  "workers": "auto",
  "max_requests": 500
}
```

`workers` is either the literal string `"auto"` (FrankenPHP picks a count
based on CPU) or a positive integer pinning the worker count.
`max_requests` is a positive integer that Laravel's stock
`public/frankenphp-worker.php` reads from the `MAX_REQUESTS` env to recycle
the worker after that many requests.

A `max_consecutive_failures` knob is intentionally not modeled by Orbit
today. FrankenPHP does document `max_consecutive_failures` as a worker
Caddyfile option, but Orbit's current runtime renderer contract emits the
inline `FRANKENPHP_CONFIG=worker FILE [NUM]` form alongside `MAX_REQUESTS`
and does not surface that worker option. No `MAX_CONSECUTIVE_FAILURES` env
is consumed by the rendered runtime either. Orbit will add the knob — and
render it through whichever surface actually consumes it — once the
renderer is extended to do so.

## Readiness

`enable` runs a readiness probe on the owning node before any state mutation.
Every required token must be present in the probe output:

| Token | Meaning |
| --- | --- |
| `octane:installed` | `vendor/laravel/octane/` exists under the app source path. |
| `frankenphp-worker-file:present` | The FrankenPHP worker file exists at the path the runtime renderer points `FRANKENPHP_CONFIG` at. The path is resolved as `<document_root>/frankenphp-worker.php` so an app with `document_root=web` is checked at `web/frankenphp-worker.php`, not the legacy hardcoded `public/frankenphp-worker.php`. |
| `frankenphp:configured` | `config/octane.php` references `frankenphp` outside of `//`, `#`, and `/* */` comments. |

Probe output is captured in `error.meta.probe_output` when readiness fails.
The probe runs against the same `<document_root>/frankenphp-worker.php` path
the renderer emits in `FRANKENPHP_CONFIG`, so a passing readiness implies the
runtime can actually find the worker file at boot.
The `missing` array names each absent condition using the literal source
paths: `vendor/laravel/octane`, the worker file path resolved against the
app's configured `document_root` (for example `public/frankenphp-worker.php`
or `web/frankenphp-worker.php`), and `octane.server=frankenphp`.
`error.meta.worker_file` carries the exact path the probe used.

Failure reasons that bypass the probe (and never mutate state):

- `runtime_kind != php`: `error.code=app.worker_unsupported_runtime`,
  `error.meta.runtime_kind` reports the current value.
- Owning node record is missing: `error.code=app.worker_unknown_node`.
- App source path is empty: `error.code=app.worker_missing_path`.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-worker_output-render_human.md) |
| `--json` | [JSON output](6.2_app-worker_output-render_json.md) |

## API Surface

The gateway HTTP API mirrors the command:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/worker` | `app:read` | Maps to `show`. |
| `POST` | `/api/apps/{app}/worker/enable` | `app:worker` | Maps to `enable`. |
| `POST` | `/api/apps/{app}/worker/disable` | `app:worker` | Maps to `disable`. |

API responses share the JSON envelope and error codes documented in the
[JSON renderer contract](6.2_app-worker_output-render_json.md).
HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
all other documented `error.code` values, `403` for permission denials.

## Behavior Contract

### Worker Mode Rules

1. **Off by default.** Every app starts with `worker_enabled=false` and
   `worker_config=null`. Classic FrankenPHP is the steady-state runtime.
2. **State transitions only through `app:worker`.** No other command writes
   `worker_enabled` or `worker_config`. Migrations and factories use the
   same defaults.
3. **Enable proves readiness first.** Run the readiness probe on the owning
   node and require every token in the table above. Any missing token
   leaves both `worker_enabled` and `worker_config` unchanged.
4. **Disable preserves configuration.** Set `worker_enabled=false` but keep
   the stored `worker_config` so the next enable restores the prior
   configuration without re-prompting for values.
5. **PHP-only.** Apps with `runtime_kind != php` always fail readiness.
   Worker mode requires the FrankenPHP app runtime container.
6. **No source mutation.** `enable` never runs `composer require`, publishes
   Octane config, edits bootstrap files, or otherwise changes the app
   source to make readiness pass.
7. **No workspace worker mode.** Worker mode is an app-level setting only.
   Workspaces always run in classic mode regardless of the owning app's
   worker setting.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (action) | `action` is missing or not one of `show`, `enable`, `disable`. | Failure |
| Validation failed (app) | `app` is missing in non-interactive mode. | Failure |
| App not found | No app record matches `app`. | Failure |
| Unsupported runtime | `enable` was called for an app with `runtime_kind != php`. State unchanged. | Failure |
| Owning node missing | `enable` was called for an app whose `node` relation is `null`. State unchanged. | Failure |
| App path missing | `enable` was called for an app with an empty `path`. State unchanged. | Failure |
| Readiness failed | Probe output is missing at least one required token. State unchanged. | Failure |

## Doctor Relationship

- `doctor --family=app --app=<app>` verifies the FrankenPHP app runtime
  container matches the app's worker mode configuration. See
  [`app-doctor.md`](../../app-doctor.md).
- Worker mode changes do not implicitly trigger a doctor run. The runtime
  renderer picks up the new setting the next time the app runtime is
  enacted; cross-reference `app-doctor.md` for drift checks instead of
  redefining doctor semantics here.

## Side Effects

`enable` and `disable` are writes against gateway-owned app configuration.
Neither command:

- Runs `composer require`, publishes Octane config, edits bootstrap files,
  or otherwise mutates the app source.
- Restarts or recreates the FrankenPHP runtime container directly. The
  app runtime renderer picks up the new worker setting the next time the
  runtime is enacted (for example through `app:root` or doctor adopt).
- Changes workspace runtime behavior.

## Runtime Renderer Integration

When `worker_enabled=true`, the app runtime container is rendered with two
env vars that the runtime actually consumes:

| Env var | Consumer | Source |
| --- | --- | --- |
| `FRANKENPHP_CONFIG` | FrankenPHP reads this as a Caddyfile snippet inside the global `frankenphp` block. | `worker_config.workers` + `document_root` |
| `MAX_REQUESTS` | Laravel's stock `public/frankenphp-worker.php` reads `$_SERVER['MAX_REQUESTS']`. | `worker_config.max_requests` |

The `FRANKENPHP_CONFIG` value uses the documented inline directive form
`worker FILE [NUM]`:

- `worker /app/<document_root>/frankenphp-worker.php <num>` when
  `worker_config.workers` is a positive integer.
- `worker /app/<document_root>/frankenphp-worker.php` when it is `"auto"`.
  FrankenPHP picks a default count based on CPU.

When `worker_enabled=false`, none of these env vars are rendered and the
container runs the classic FrankenPHP request path.

The runtime container `spec_hash` differs between classic and worker mode so
the runtime manager recreates the container after a toggle.

`OCTANE_*` and `MAX_CONSECUTIVE_FAILURES` env vars are intentionally not
emitted. Stock Laravel Octane does not read its config from environment
variables of that shape. FrankenPHP does support a `max_consecutive_failures`
Caddyfile option inside the `worker` block, but Orbit's current
`FRANKENPHP_CONFIG=worker FILE [NUM]` inline form does not include it and
no env consumer reads `MAX_CONSECUTIVE_FAILURES`; emitting either today
would be naming theater.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Apps/AppWorkerCommandTest.php` | Action validation, app resolution, show/enable/disable state mutations, readiness failure leaves state unchanged, and the `app.not_found` and `app.worker_unsupported_runtime` paths. |
| `apps/gateway/tests/Feature/Http/Api/AppWorkerControllerTest.php` | API surface for `show`, `enable`, `disable`; HTTP status mapping; `app:worker` vs `app:read` permission split; `app.not_found` 404; readiness failure 422. |
| `apps/gateway/tests/Unit/Services/Apps/AppWorkerReadinessTest.php` | Probe token vocabulary and false-positive guards: bare composer.json, missing vendor, comment-only configuration (line and block), and the trailing-comment positive case. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php` | Worker-mode runtime env vars consumed by FrankenPHP and Octane, the `worker` directive shape for `auto` and integer worker counts, the configured `document_root` worker-file path, and the spec hash change on toggle. |
