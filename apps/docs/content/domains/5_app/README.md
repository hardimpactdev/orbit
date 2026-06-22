# App Commands

App commands manage gateway-owned app configuration and the concrete runtime or
deployment instances derived from that configuration. An app is a logical
application record. An app instance is one concrete place that app can run, such
as an Orbit-managed development node, an Orbit-managed production node, or a
Laravel Cloud environment.

## Domain Rules

These rules govern all app family commands.

- The gateway owns app registry, app-instance registry, runtime policy,
  deployment policy, and app health configuration.
- App names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- App-role artifacts are applied by the gateway over SSH.
- Apps may have one or more app instances. Instance names are unique within the
  app.
- An app instance has exactly one driver. Current drivers are `orbit` and
  `laravel-cloud`.
- `orbit` instances describe Orbit-managed placements on app-role nodes.
  `laravel-cloud` instances describe external Laravel Cloud application and
  environment targets; Orbit stores the relationship but does not deploy to
  Laravel Cloud in this slice.
- Laravel Cloud driver flows reuse an existing Cloud environment by default.
  When the adapter has discovery data and the operator did not choose an
  environment, Orbit selects the Cloud default environment, then an environment
  named `main`, then the sole existing environment. If multiple environments
  remain possible, Orbit fails and returns the candidates instead of creating
  another environment.
- The app `node`, `path`, `root`, URL, and environment fields remain the current
  compatibility/default development placement used by app-level commands until
  those command families are fully instance-aware.
- App instance env values and database targets belong to the instance, not the
  logical app. Rendering an instance env merges explicit app env values with
  database attachments for that instance.
- App hostnames are represented in `proxy` as app-owned route records.
  App commands create, update, and remove the app configuration that owns those
  routes; proxy route registry, router route convergence, and backend artifact
  convergence belong to the `proxy` family.
- Commands that create or set up apps use explicit `--node` first, then the
  local `node:default` development node when configured.
- `app:new` creates or clones app source/path and then uses `app:register`
  behavior to converge app configuration and node artifacts.
- `app:register` is idempotent. It can adopt an existing app path, re-apply
  Orbit management for an existing app, or retry production domain activation.
- Apps may configure an agent IDE adapter through `app:agent-ide`. This
  overrides the owning node default for app and workspace workflows.
- Development-server behavior for app and workspace processes is owned by the
  `process` family. App commands record the app URL, document root, and runtime
  policy; they do not create Vite-specific proxy routes or rewrite app-side
  frontend configuration.
- PHP app runtime uses a FrankenPHP app runtime container selected by gateway
  app configuration. The concrete FrankenPHP runtime, managed through the
  process lifecycle, is represented as a process with Docker runtime. Changing
  `php_version` recreates the app runtime artifact from the selected PHP image;
  it does not install host PHP or render host FPM pools.
- Ad-hoc PHP, Composer, or Artisan for an app runs on the app node's host PHP
  toolchain (matched to the app's PHP version), against the app source the
  FrankenPHP container serves. Orbit ships no command-`exec` surface; deploy
  steps use the same host toolchain.
- Worker mode is an opt-in app runtime setting. It is disabled by default and
  `app:worker enable` must validate app readiness before changing gateway
  configuration.
- App runtime mounts are extra bind mounts stored on the app for PHP runtimes
  on `app-dev` nodes. Workspaces inherit the parent app's configured mounts.
- App instances record required PHP extensions. For Orbit-driven PHP instances,
  `doctor --family=app` reports missing or unverifiable extensions against the
  concrete FrankenPHP runtime container.
- App WebSocket bindings are explicit app-owned configuration. They enable one
  app to use the fleet websocket service, own per-app Reverb credentials,
  allowed origins, public WebSocket hosts, and private `websocket.orbit`
  publishing configuration. App commands own the binding state; `ingress`
  owns public route exposure, `router` owns route selection and backend pools,
  and the `websocket` role owns the Reverb runtime.
- App analytics bindings are explicit app-owned configuration. They enable one
  app to use the fleet analytics service through public tracking hostnames such
  as `analytics.example.com`. App commands own the binding state and host list;
  `ingress` owns public route exposure, `router` owns tracking-only route
  selection and backend pools, and the `analytics` role owns the Plausible CE
  runtime. V1 does not inject scripts, provision Plausible sites, or expose the
  Plausible dashboard publicly.
- Apps may be registered in Codex App on an eligible operator node through
  `app:codex`. The command edits only Codex App's config file on the target
  node and applies Codex App's URL callback; it does not configure the app's
  agent IDE adapter.
- Production deployment pipeline definitions currently remain app-owned for
  compatibility. The product direction is for deployment steps, runs, logs, and
  latest deployment status to move to app instances so `app:deploy` can be
  driver-aware.
- `app:prune` is source-of-truth cleanup, not doctor drift repair. It checks
  configured agent IDE adapters for the app, uses workspace removal semantics
  for stale workspaces, and can be scheduled through normal schedules.

Read commands over app registry state are fast gateway database reads unless
their command contract explicitly opts into live inspection. App runtime drift
belongs to [`app-doctor.md`](app-doctor.md). Implementation-shape details for
gateway-to-node application and process managers live in
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node) and
[tech-stack.md#process-manager](../../tech-stack.md#process-manager).

Orbit-managed production app routes enter through `ingress`, are forwarded over
WireGuard to the gateway-coupled `router`, and only then fan out to private
`app-prod` backend artifacts. App commands choose the ingress placement for
Orbit-driven production. The router owns private route selection and
backend-pool targeting. The app-prod backend artifact is app-role-owned and
separate from the API Caddy route that is colocated with the gateway router. It
terminates at `orbit-caddy` on the app-prod node and then reaches the app's
FrankenPHP Docker runtime container on internal port `8080` over the node Docker
network. Laravel Cloud production instances are represented as external
driver-backed instances instead of Orbit-owned ingress/router/app-prod artifacts.

Production app runtime policy is app-owned, while the concrete long-running
runtime unit is process-owned. The runtime container uses a
path-derived app user, must not grant that user Docker group or Docker socket
access, and may bind mount only the app source or active release path plus
explicitly managed shared paths. Runnable services such as MySQL, PostgreSQL,
Redis, SeaweedFS, and Reverb are modeled as process-backed long-running units,
with tool capability records only where a process depends on an installed host
capability; they are not owned by `app-prod`.

## App Identity Arguments

App command signatures use two positional names intentionally:

- `[name]` is an app identity slug for commands that create, adopt, or
  re-converge app configuration. It is not a hostname selector.
- `[app]` is an existing-app selector for commands that read, update, prune, or
  remove an app. It may be an app name or app hostname when the command
  contract says hostname resolution is supported. Name matches win over
  hostname matches.

## App JSON Entity

App-family JSON renderers that return an app entity embed the same canonical
shape under `success.data.app`, or directly under `success.data.apps[]` for
list items. Command-specific result state belongs beside the entity, not inside
it.

`app:show` follows that same rule: `success.data.app` is the canonical app
entity, while show-only registry expansion such as bound workspaces, process
definitions, routes, and effective agent IDE details lives under
`success.data.details`. Do not merge those show-only relationships into the
canonical app entity.

```json
{
  "name": "docs",
  "node": "app-1",
  "url": "https://docs.example.com",
  "path": "/home/docs/app",
  "root": "public",
  "repository": "git@github.com:my/repo.git",
  "runtime_kind": "php",
  "php_version": "8.5",
  "worker_enabled": false,
  "worker_config": null,
  "adopted": false
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | App identity slug. Globally unique in the gateway app registry. |
| `node` | string | Owning node slug. The node's active role (`app-dev` or `app-prod`) determines the app's environment; the app entity does not carry a separate `environment` field. |
| `url` | string | Primary intended URL for the app. |
| `path` | string | Absolute app path on the owning node. |
| `root` | string | Document root relative to `path`. |
| `repository` | string \| null | Source repository URL recorded for the app, or `null` when none is configured. |
| `runtime_kind` | string | Runtime kind for the app. `php` uses a FrankenPHP app runtime container; `static` serves without one. |
| `php_version` | string | PHP version recorded in gateway app configuration. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `worker_enabled` | boolean | Whether FrankenPHP worker mode is enabled for this app. Defaults to `false`. |
| `worker_config` | object \| null | Worker settings used only when worker mode is enabled. |
| `adopted` | boolean | `true` once the app path was adopted through `app:register`; `false` for app records created by `app:new` or first registered without adoption. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as an absent repository.

## App Instance JSON Entity

App-instance renderers return this shape under `success.data.instance`, or under
`success.data.instances[]` for list output.

```json
{
  "app": "docs",
  "name": "production-cloud",
  "driver": "laravel-cloud",
  "driver_config": {
    "application_id": "app_123",
    "environment_id": "env_123",
    "environment_reused": true,
    "environment_created": false,
    "domain": "docs.example.com"
  },
  "runtime": {
    "runtime_kind": "php",
    "php_version": "8.5",
    "frankenphp_image": "dunglas/frankenphp:1-php8.5",
    "mode": "classic",
    "configured_mounts": [],
    "required_php_extensions": ["intl", "redis"]
  },
  "latest_deployment_status": null,
  "latest_deployment_run_id": null
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `app` | string | Logical app identity slug. |
| `name` | string | Instance name, unique within the app. |
| `driver` | string | Instance driver: `orbit` or `laravel-cloud`. |
| `driver_config` | object | Driver-specific Laravel Data object serialized through the gateway. |
| `runtime` | object | Effective runtime metadata for this instance. |
| `runtime.runtime_kind` | string | Logical app runtime kind. |
| `runtime.php_version` | string | PHP version recorded for the app runtime. |
| `runtime.frankenphp_image` | string \| null | Resolved FrankenPHP image for PHP apps. |
| `runtime.mode` | string | `classic` or `worker` for PHP apps. |
| `runtime.configured_mounts` | array | App-level runtime mounts rendered into Orbit PHP runtimes. |
| `runtime.required_php_extensions` | array | Required PHP extensions tracked for the instance. |
| `latest_deployment_status` | string \| null | Reserved for instance-scoped deployment history. |
| `latest_deployment_run_id` | integer \| null | Reserved for instance-scoped deployment history. |

In the current converted app command surface, `app:new` is the only command that
records repository metadata. `app:register` preserves an existing app's stored
repository value and stores `repository=null` when adopting an unmanaged path.

## Authorization

App commands use gateway-owned access policy. The gateway authenticates the
caller's WireGuard peer and applies the scoped permission set on the grant
linking the caller to the app's owning node. The CLI never branches on
caller role. Self-targeting commands are authorized by the node's
self-grant — see [Architecture: Self-grants and
self-serving](../../architecture.md#self-grants-and-self-serving).
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md) is
the most visible self-serving command in this family today; it works because
the `app-dev` and `app-prod` self-grant baselines include the
workspace permissions it needs.

## Commands

The following commands are available in the `app` family.

1. [`orbit app:new [name]`](1_app-new/app-new.md)
2. [`orbit app:register [name]`](2_app-register/app-register.md)
3. [`orbit app:list`](3_app-list/app-list.md)
4. [`orbit app:show [app]`](4_app-show/app-show.md)
5. [`orbit app:root [app] [root]`](5_app-root/app-root.md)
6. [`orbit app:remove [app]`](6_app-remove/app-remove.md)
7. [`orbit app:prune [app]`](7_app-prune/app-prune.md)
8. Reserved for a future app metadata update command. No `app:update` command
   contract exists in the current converted surface.
9. [`orbit app:agent-ide [app] [agent_ide]`](9_app-agent-ide/app-agent-ide.md)
10. Reserved. `app:exec` was removed; Orbit has no command-`exec` surface.
11. [`orbit app:worker show|enable|disable [app]`](11_app-worker/app-worker.md)
12. [`orbit app:websocket enable [app]`](12_app-websocket-enable/app-websocket-enable.md)
13. [`orbit app:websocket disable [app]`](13_app-websocket-disable/app-websocket-disable.md)
14. [`orbit app:websocket credentials [app]`](14_app-websocket-credentials/app-websocket-credentials.md)
15. [`orbit app:mount list|add|remove [app]`](15_app-mount/app-mount.md)
16. [`orbit app:analytics enable [app]`](16_app-analytics-enable/app-analytics-enable.md)
17. [`orbit app:analytics disable [app]`](17_app-analytics-disable/app-analytics-disable.md)
18. [`orbit app:analytics show [app]`](18_app-analytics-show/app-analytics-show.md)
19. [`orbit app:instance list|show|add|remove [app]`](19_app-instance/app-instance.md)
20. [`orbit app:env list|set|render [app]`](20_app-env/app-env.md)
21. [`orbit app:codex add|remove|list [app]`](21_app-codex/app-codex.md)

## Related

- [`doctor --family=app`](app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
