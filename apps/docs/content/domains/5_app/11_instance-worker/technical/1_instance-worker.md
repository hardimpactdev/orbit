# Technical Contract: `orbit instance:worker show|enable|disable [instance]`

[Back to public `instance:worker` documentation.](../instance-worker.md)

**Owner:** `instance`.

**Effects:** `read` for `show`; `write` for `enable` and `disable`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `instance:read` on the selected instance's serving
  node for `show`, and `instance:worker` on that node for `enable` and `disable`.
- The target instance exists in gateway configuration.
- For `enable`, the serving node is reachable so the readiness probe can run
  against the selected instance's installed source path.

## Signature

```bash
orbit instance:worker [action] [instance] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be one of `show`, `enable`, `disable`. |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted instance selector. A bare app name or hostname is shorthand only when exactly one instance exists; otherwise fail with `validation_failed` and `meta.reason=instance_required`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `action`. Reject any value other than `show`, `enable`, or
   `disable` with `validation_failed`.
2. Resolve the concrete instance from `[instance]`. When omitted, an interactive prompt selects an
   instance the caller can see; non-interactive callers without an `[instance]`
   argument fail with `validation_failed`.
3. Require one concrete instance. A dotted selector is explicit. A bare
   app selector auto-resolves only a sole instance; zero or multiple instances
   fail before authorization or side effects with `validation_failed`,
   `error.meta.field=instance`, and
   `error.meta.reason=instance_required`.
4. Resolve authorization, readiness, and runtime placement from that instance's
   serving node and driver configuration. App defaults never replace
   the selected placement.

## State Model

Gateway-owned instance configuration stores two structural fields for
worker mode:

- `worker_enabled` (boolean): defaults to `false` for every instance and is the
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
Caddyfile option, but Orbit's current worker configuration does not surface
that option. No `MAX_CONSECUTIVE_FAILURES` env is consumed by the rendered
runtime either. Orbit will add the knob once the command contract and
renderer expose it deliberately.

## Readiness

`enable` runs a readiness probe on the selected instance's serving node and
source path before any state mutation.
Every required token must be present in the probe output:

| Token | Meaning |
| --- | --- |
| `octane:installed` | `vendor/laravel/octane/` exists under the selected instance's source path. |
| `frankenphp-worker-file:present` | The FrankenPHP worker file exists at the path the runtime renderer points `FRANKENPHP_CONFIG` at. The path is resolved as `<document_root>/frankenphp-worker.php`, so an app with `document_root=web` is checked at `web/frankenphp-worker.php`. |
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

- `runtime != php`: `error.code=instance.worker_unsupported_runtime`,
  `error.meta.runtime` reports the current value.
- The selected instance's `driver_config.node` is missing:
  `error.code=instance.worker_unknown_node`.
- The selected instance's `driver_config.path` is empty:
  `error.code=instance.worker_missing_path`.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_instance-worker_output-render_human.md) |
| `--json` | [JSON output](6.2_instance-worker_output-render_json.md) |

## API Surface

The gateway HTTP API mirrors the command:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/instances/{instance}/worker` | `instance:read` | Maps to `show`; `{instance}` is the dotted selector or unambiguous bare shorthand. |
| `POST` | `/api/instances/{instance}/worker/enable` | `instance:worker` | Maps to `enable` for the selected instance. |
| `POST` | `/api/instances/{instance}/worker/disable` | `instance:worker` | Maps to `disable` for the selected instance. |

API responses share the JSON envelope and error codes documented in the
[JSON renderer contract](6.2_instance-worker_output-render_json.md).
HTTP status codes: `200` for success, `404` for `instance.not_found`, `422` for
all other documented `error.code` values, `403` for permission denials.

## Behavior Contract

### Worker Mode Rules

1. **Off by default.** Every instance starts with `worker_enabled=false` and
   `worker_config=null`. Classic FrankenPHP is the steady-state runtime.
2. **State transitions only through `instance:worker`.** No other command writes
   `worker_enabled` or `worker_config`. Migrations and factories use the
   same defaults.
3. **Enable proves readiness first.** Run the readiness probe on the selected
   instance's serving
   node and require every token in the table above. Any missing token
   leaves both `worker_enabled` and `worker_config` unchanged.
4. **Disable preserves configuration.** Set `worker_enabled=false` but keep
   the stored `worker_config` so the next enable restores the prior
   configuration without re-prompting for values.
5. **PHP-only.** Apps with `runtime != php` always fail readiness.
   Worker mode requires the FrankenPHP app runtime container.
6. **No source mutation.** `enable` never runs `composer require`, publishes
   Octane config, edits bootstrap files, or otherwise changes the app
   source to make readiness pass.
7. **No workspace worker mode.** Worker mode is instance state but is not
   inherited by workspaces. Supported `app-dev` workspaces run in classic mode
   and may receive the classic FrankenPHP thread-pool settings documented
   below. `app-prod` workspace operations are rejected before runtime
   rendering.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (action) | `action` is missing or not one of `show`, `enable`, `disable`. | Failure |
| Validation failed (instance) | `instance` is missing in non-interactive mode. | Failure |
| Instance not found | No concrete instance matches `instance`. | Failure |
| Instance required | A bare app selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=instance_required`. |
| Unsupported runtime | `enable` was called for an app with `runtime != php`. State unchanged. | Failure |
| Serving node missing | `enable` was called for an instance whose `driver_config.node` is missing. State unchanged. | Failure |
| Source path missing | `enable` was called for an instance whose `driver_config.path` is empty. State unchanged. | Failure |
| Readiness failed | Probe output is missing at least one required token. State unchanged. | Failure |

## Doctor Relationship

- `doctor --family=instance --instance=<app.instance>` verifies the selected instance's
  runtime configuration against its worker policy. See
  [`instance-doctor.md`](../../instance-doctor.md).
- Worker mode changes do not implicitly trigger a doctor run. The runtime
  renderer picks up the new setting the next time the app runtime is
  enacted; cross-reference `instance-doctor.md` for drift checks instead of
  redefining doctor semantics here.

## Side Effects

`enable` and `disable` are writes against gateway-owned instance
configuration.
Neither command:

- Runs `composer require`, publishes Octane config, edits bootstrap files,
  or otherwise mutates the selected instance's source path.
- Restarts or recreates the FrankenPHP runtime container directly. The
  app runtime renderer picks up the new worker setting the next time the
  runtime is enacted (for example through `instance:root` or doctor adopt).
- Enables worker mode for workspaces or creates production workspaces.

## Runtime Renderer Integration

On nodes with `app-dev`, classic PHP app and supported workspace containers render
`FRANKENPHP_CONFIG` with native FrankenPHP thread-pool settings even when
worker mode is disabled:

```caddyfile
max_threads auto
max_idle_time 1h
```

These settings tune FrankenPHP's request-thread pool and keep idle development
capacity warm. They do not enable Laravel Octane worker mode.

When `worker_enabled=true`, the app runtime container is rendered with the
same app-dev thread-pool settings when applicable, plus two worker-mode env
vars that the runtime actually consumes:

| Env var | Consumer | Source |
| --- | --- | --- |
| `FRANKENPHP_CONFIG` | FrankenPHP reads this as a Caddyfile snippet inside the global `frankenphp` block. | app-dev thread-pool policy + `worker_config.workers` + `document_root` |
| `MAX_REQUESTS` | Laravel's stock `public/frankenphp-worker.php` reads `$_SERVER['MAX_REQUESTS']`. | `worker_config.max_requests` |

The worker portion of `FRANKENPHP_CONFIG` uses FrankenPHP's documented block
directive form:

```caddyfile
worker {
    file /app/<document_root>/frankenphp-worker.php
    num <num>
}
```

The `num` line is included only when `worker_config.workers` is a positive
integer. When `workers` is `"auto"`, Orbit omits `num` and FrankenPHP picks a
default count based on CPU.

When `worker_enabled=false`, `MAX_REQUESTS` is not rendered and the container
runs the classic FrankenPHP request path. `app-dev` classic containers still
render the thread-pool `FRANKENPHP_CONFIG` shown above; `app-prod` classic
containers render no `FRANKENPHP_CONFIG` unless worker mode is enabled.

The runtime container `spec_hash` differs between classic and worker mode so
the runtime manager recreates the container after a toggle.

`OCTANE_*` and `MAX_CONSECUTIVE_FAILURES` env vars are intentionally not
emitted. Stock Laravel Octane does not read its config from environment
variables of that shape. FrankenPHP does support a `max_consecutive_failures`
Caddyfile option inside the `worker` block, but Orbit's current worker
contract does not include it and no env consumer reads
`MAX_CONSECUTIVE_FAILURES`; emitting either today would be naming theater.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI show/enable/disable human and JSON output. |
| `apps/gateway/tests/Feature/Http/Api/AppWorkerControllerTest.php` | API surface for `show`, `enable`, `disable`; HTTP status mapping; `instance:worker` vs `instance:read` permission split; `instance.not_found` 404; readiness failure 422. |
| `apps/gateway/tests/Unit/Services/Apps/AppWorkerReadinessTest.php` | Probe token vocabulary and false-positive guards: bare composer.json, missing vendor, comment-only configuration (line and block), and the trailing-comment positive case. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php` | App-dev thread-pool config, worker env vars, worker block shapes, document-root paths, and spec-hash changes. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php` | App-dev workspace classic FrankenPHP thread-pool config without inheriting instance worker mode. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceProductionBoundaryTest.php` | `app-prod` workspace operations are rejected before any workspace runtime can be rendered. |
