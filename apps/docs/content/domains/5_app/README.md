# App Commands

App commands manage gateway-owned app configuration and the concrete runtime or
deployment instances derived from that configuration. An app is a logical
application record. An app instance is one concrete place that app can run, such
as an Orbit-managed development node, an Orbit-managed production node, or a
Laravel Cloud environment.

## Domain Rules

These rules govern all app family commands.

- The gateway owns app registry, app-instance registry, runtime policy,
  app-instance deployment policy, and app health configuration.
- App names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- App-host artifacts on non-gateway nodes are applied through Agent push.
  Gateway-owned work executes locally. Provisioning is the sole permanent
  Orbit SSH lane.
- Apps may have one or more app instances. Instance names are unique within the
  app.
- An app instance has exactly one driver. Current drivers are `orbit` and
  `laravel-cloud`.
- `orbit` instances describe Orbit-managed placements on nodes with an active
  `app-dev` or `app-prod` role.
  `laravel-cloud` instances describe external Laravel Cloud application and
  environment targets; Orbit stores the relationship but does not deploy to
  Laravel Cloud in this slice.
- Laravel Cloud driver flows reuse an existing Cloud environment by default.
  When the adapter has discovery data and the operator did not choose an
  environment, Orbit selects the Cloud default environment, then an environment
  named `main`, then the sole existing environment. If multiple environments
  remain possible, Orbit fails and returns the candidates instead of creating
  another environment.
- Logical apps store shared project identity and runtime policy only. They do
  not store a server, path, root, URL, domain, or environment default.
  Every placement fact belongs to one concrete app instance.
- App instance env values and database targets belong to the instance, not the
  logical app. Rendering an instance env merges explicit app env values with
  database attachments for that instance.
- App hostnames are represented in `proxy` as app-owned route records.
  App commands create, update, and remove the app configuration that owns those
  routes; proxy route registry, router route convergence, and backend artifact
  convergence belong to the `proxy` family.
- Commands that create or set up apps use explicit `--node` first, then the
  local `node:default` node when configured.
- `app:new` creates or clones one source path, then atomically creates the
  logical app and its first named instance before using `app:register`
  behavior to converge that instance's configuration and node artifacts. The
  first instance is named `development` without `--domain` and `production`
  with `--domain`.
- `app:register` is idempotent for one concrete instance. It can create or
  adopt a named instance, re-apply Orbit management for that instance, move
  only that instance, or retry its production-domain activation. A first
  adoption atomically creates the logical app and its first instance.
- App instances may configure an agent IDE adapter through `app:agent-ide`.
  Effective resolution is instance override, then that instance's serving-node
  default, then no adapter.
- Development-server behavior for app and workspace processes is owned by the
  `process` family. App commands record shared runtime policy, while the
  selected app instance records URL and document root; they do not create
  Vite-specific proxy routes or rewrite app-side frontend configuration.
- Process definitions for app and workspace contexts belong to one concrete app
  instance. Process commands accept dotted selectors such as `docs.nmbp`; bare
  logical-app shorthand is valid only when the app has exactly one instance.
- PHP app runtime uses a FrankenPHP app runtime container selected by gateway
  app configuration. The concrete FrankenPHP runtime, managed through the
  process lifecycle, is represented as a process with Docker runtime. Changing
  `php_version` recreates the app runtime artifact from the selected PHP image;
  it does not install host PHP or render host FPM pools.
- Ad-hoc PHP, Composer, or Artisan for an app instance runs on that instance's
  serving node host PHP toolchain (matched to the app's PHP version), against
  the instance source path the FrankenPHP container serves. Orbit ships no
  command-`exec` surface; deploy steps use the same host toolchain.
- App setup is lifecycle-specific, not a generic exec surface.
  `app-setup-step:*` records ordered setup commands for one app instance, and
  `app:setup` runs those commands on that instance's serving node and path
  through the same host PHP, Composer, and Artisan routing used by deploy
  steps. Dotted selectors address the instance; a bare logical-app selector is
  shorthand only when exactly one instance exists.
- `app:setup` is idempotent for an unchanged setup-step set. Re-running setup
  with no step changes returns the latest completed run instead of replaying
  commands.
- Worker mode is an opt-in app-instance runtime setting. It is disabled by
  default for every instance, and `app:worker enable` must validate readiness
  on the selected instance before changing gateway configuration. Different
  instances of one logical app may carry different worker policy.
- App runtime mounts are extra bind mounts stored on app instances for PHP
  runtimes on `app-dev` nodes. Workspaces inherit the selected app instance's
  configured mounts; mount intent is exclusively app-instance-owned.
- App instances record required PHP extensions. For Orbit-driven PHP instances,
  `doctor --family=app` reports missing or unverifiable extensions against the
  concrete FrankenPHP runtime container.
- App WebSocket bindings select one concrete app instance and site. They enable
  that instance to use the fleet websocket service, own Reverb credentials,
  allowed origins derived from that instance's domain, public WebSocket hosts,
  and private `websocket.orbit` publishing configuration. App commands own the
  binding state; `ingress`
  owns public route exposure, `router` owns route selection and backend pools,
  and the `websocket` role owns the Reverb runtime.
- App analytics bindings select one concrete app instance and site. They enable
  that instance to use the fleet analytics service through public tracking
  hostnames such as `analytics.example.com`, derived by default from that
  instance's domain. App commands own the binding state and host list;
  `ingress` owns public route exposure, `router` owns tracking-only route
  selection and backend pools, and the `analytics` role owns the Plausible CE
  runtime. V1 does not inject scripts, provision Plausible sites, or expose the
  Plausible dashboard publicly.
- Apps may be registered in Codex App on an eligible operator node through the
  optional [`codex:app`](../23_codex/1_codex-app/codex-app.md) extension
  command. The command edits only Codex App's config file on the target node
  and applies Codex App's URL callback; it does not configure the app's agent
  IDE adapter.
- Production deployment policy, ordered steps, warmup paths, runs, history,
  logs, and latest status belong to one concrete app instance. `app:deploy`
  accepts the canonical dotted instance selector, while a bare app name is
  shorthand only when that app has exactly one instance.
- `app:prune` is source-of-truth cleanup for one concrete `app-dev` instance,
  not doctor drift repair. It checks that instance's effective agent IDE
  adapter, uses workspace removal semantics for stale workspaces owned by that
  instance, and can be scheduled through normal schedules.
- App dependency audit posture is gateway-owned summary state for registered
  app source paths. The v1 storage and presentation slice records compact
  per-manager summaries derived from lockfile-aware audit commands such as
  `composer audit --format=json` and `npm audit --json`, treats Bun as a
  separate manager status, and exposes aggregate `dependency_audit_status`,
  `dependency_warning_count`, `dependency_danger_count`, and
  `last_dependency_audit_at` on `app:list` and `app:show` JSON. Human
  `app:show` labels this aggregate as app-scoped and does not imply that the
  status belongs to an individual instance or workspace. It does not
  store full package inventories, mutate source, read logs as dependency truth,
  or auto-remediate findings. Remote audit refresh execution, workspace
  coverage, nightly fleet refresh, and full Bun vulnerability normalization are
  follow-up slices.

- App doctor, worker, and setup commands resolve concrete placement exactly as
  deployment and process commands do. A dotted selector such as
  `docs.production` is explicit. A selector containing only the logical app
  name succeeds for an app with exactly one instance; otherwise the command fails with
  a validation error requiring a concrete app-instance selector before
  authorization or side effects.

The same concrete-instance rule applies to `app:register`, `app:root`,
`app:prune`, `app:agent-ide`, WebSocket, analytics, and other placement-sensitive
commands. `app:remove` is the deliberate exception: it accepts only a logical
app slug and, with explicit destructive consent, removes that app plus every
owned instance as one authorized cascade. Dotted selectors and instance
hostnames belong to `app:instance remove`.

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
backend-pool targeting. The app-prod backend artifact is app-host-owned and
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
Valkey, SeaweedFS, and Reverb are modeled as process-backed long-running units,
with tool capability records only where a process depends on an installed host
capability; they are not owned by `app-prod`.

## App Identity Arguments

App command signatures use two positional names intentionally:

- `[name]` is an app identity slug for commands that create, adopt, or
  re-converge app configuration. It is not a hostname selector. When the
  command targets placement, it creates or selects one named instance.
- `[app]` is an existing-app selector for commands that read, update, prune, or
  remove an app. It may be an app name or app hostname when the command
  contract says hostname resolution is supported. Name matches win over
  hostname matches.

Placement-sensitive commands use a dotted `<app>.<instance>` selector. Their
contracts may admit a bare logical app slug only when exactly one eligible,
visible instance exists. `app:remove` never uses this selector model: its
`[app]` argument is a logical slug only.

## App JSON Entity

When a JSON renderer in the app family returns a logical app, it embeds the
canonical app entity under `success.data.app`. Concrete placement is returned
separately under `success.data.instance` or a command-specific instance list.
Command-specific result state belongs beside the entity, not inside it.

`app:list` is the intentional exception: `success.data.apps[]` contains compact
logical-app summaries, not this placement-shaped entity. Each summary carries
`name`, `repository`, aggregate dependency-audit posture, `instance_count`, and
`workspace_count`. Concrete node, URL, path, runtime, instance, and workspace
rows belong to `app:show` and `app:instance`.

`app:show` follows this rule: `success.data.app` is the canonical
logical-app entity, while show-only registry expansion such as concrete
instances, instance-nested workspaces, process definitions, routes, and
effective agent IDE details lives under `success.data.details`. Do not merge
those show-only relationships into the canonical app entity. There is no flat
logical-app workspace fallback. Workspace expansion includes only active
`app-dev` placements and is omitted entirely for `app-prod` callers.

```json
{
  "name": "docs",
  "repository": "git@github.com:my/repo.git",
  "runtime": "php",
  "runtime_config": {
    "proxy_transport": "http"
  },
  "php_version": "8.5",
  "dependency_audit_status": "unknown",
  "dependency_warning_count": 0,
  "dependency_danger_count": 0,
  "last_dependency_audit_at": null
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | App identity slug. Globally unique in the gateway app registry. |
| `repository` | string \| null | Source repository URL recorded for the app, or `null` when none is configured. |
| `runtime` | string | Runtime for the app. `php` uses a FrankenPHP app runtime container; `static` serves without one. |
| `runtime_config` | object \| null | Runtime-specific gateway configuration. PHP/FrankenPHP apps expose `proxy_transport`, which is `http` by default and may be `https` for app-dev inner TLS; static apps report `null`. |
| `php_version` | string | PHP version recorded in gateway app configuration. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `dependency_audit_status` | string | Aggregate dependency posture for the logical app. |
| `dependency_warning_count` | integer | Number of warning-severity dependency findings in the latest summaries. |
| `dependency_danger_count` | integer | Number of danger-severity dependency findings in the latest summaries. |
| `last_dependency_audit_at` | string \| null | Latest completed dependency audit time, or `null` when no audit has completed. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as an absent repository.

## App Instance JSON Entity

App-instance renderers return this shape under `success.data.instance`, or under
`success.data.instances[]` for list output.

```json
{
  "app": "docs",
  "name": "production",
  "driver": "orbit",
  "driver_config": {
    "environment": "production",
    "node": "app-1",
    "url": "https://docs.example.com",
    "path": "/home/docs/app",
    "root": "public",
    "domain": "docs.example.com"
  },
  "adopted": false,
  "runtime": {
    "runtime": "php",
    "php_version": "8.5",
    "frankenphp_image": "ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm",
    "mode": "classic",
    "configured_mounts": [],
    "required_php_extensions": ["intl", "redis"]
  },
  "worker_enabled": false,
  "worker_config": null,
  "deploy_warmup_paths": [],
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
| `adopted` | boolean | Whether this concrete path was adopted through `app:register`. It never belongs to the logical app. |
| `runtime` | object | Effective runtime metadata for this instance. |
| `runtime.runtime` | string | Logical app runtime. |
| `runtime.php_version` | string | PHP version recorded for the app runtime. |
| `runtime.frankenphp_image` | string \| null | Resolved FrankenPHP image for PHP apps. |
| `runtime.mode` | string | `classic` or `worker` for PHP apps. |
| `runtime.configured_mounts` | array | Instance-scoped runtime mounts rendered into Orbit PHP runtimes for the selected instance. |
| `runtime.required_php_extensions` | array | Required PHP extensions tracked for the instance. |
| `worker_enabled` | boolean | Whether FrankenPHP worker mode is enabled for this instance. Defaults to `false`. |
| `worker_config` | object \| null | Worker settings owned by this instance and retained across disable/enable cycles. |
| `deploy_warmup_paths` | array | HTTP paths warmed after this instance deploys successfully. |
| `latest_deployment_status` | string \| null | Latest deployment status owned by this instance. |
| `latest_deployment_run_id` | integer \| null | Latest deployment run owned by this instance. |

In the current converted app command surface, `app:new` is the only command that
records repository metadata. `app:register` preserves an existing app's stored
repository value and stores `repository=null` when adopting an unmanaged path.

## Authorization

App commands use gateway-owned access policy. The gateway authenticates the
caller's WireGuard peer and applies the scoped permission set on the grant
linking the caller to each selected app instance's serving node. The CLI never
branches on caller role. Self-targeting commands are authorized by the node's
self-grant — see [Architecture: Self-grants and
self-serving](../../architecture.md#self-grants-and-self-serving).
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md) is
the most visible self-serving command in this family today; it works because
the `app-dev` self-grant baseline includes the workspace permissions it needs.
`app-prod` self-grants are read-only and include no wildcard or workspace
permission; production app services never operate workspaces.

Commands that affect several instances, including `app:remove`, authorize the
complete affected serving-node set before consent or side effects.

`app-dev` self-grants also include `app:register` for the node itself, so a
local CLI on an app-dev node can register or re-apply management for apps hosted
by that same node. `app-prod` self-grants do not include `app:register`; production
app registration remains operator/deploy-driven. Cross-node app registration is
denied unless the caller has an explicit grant to the target app node.

## Commands

The following commands are available in the `app` family.

### App Lifecycle

Use these commands to create, inspect, and remove app records.

1. [`orbit app:new [name]`](1_app-new/app-new.md)
2. [`orbit app:register [name]`](2_app-register/app-register.md)
3. [`orbit app:list`](3_app-list/app-list.md)
4. [`orbit app:show [app]`](4_app-show/app-show.md)
5. [`orbit app:root [app.instance] [root]`](5_app-root/app-root.md)
6. [`orbit app:remove [app-slug]`](6_app-remove/app-remove.md)
7. [`orbit app:prune [app.instance]`](7_app-prune/app-prune.md)
8. Reserved for a future app metadata update command. No `app:update` command
   contract exists in the current converted surface.

### App Runtime Features

Use these commands to configure runtime-facing app capabilities.

1. [`orbit app:agent-ide [app.instance] [agent_ide]`](9_app-agent-ide/app-agent-ide.md)
2. Reserved. `app:exec` was removed; Orbit has no command-`exec` surface.
3. [`orbit app:worker show|enable|disable [app]`](11_app-worker/app-worker.md)
4. [`orbit app:websocket enable [app.instance]`](12_app-websocket-enable/app-websocket-enable.md)
5. [`orbit app:websocket disable [app.instance]`](13_app-websocket-disable/app-websocket-disable.md)
6. [`orbit app:websocket credentials [app.instance]`](14_app-websocket-credentials/app-websocket-credentials.md)
7. [`orbit app:mount list|add|remove [app.instance]`](15_app-mount/app-mount.md)

### App Integrations

Use these commands for analytics, app instances, and env values.

1. [`orbit app:analytics enable [app.instance]`](16_app-analytics-enable/app-analytics-enable.md)
2. [`orbit app:analytics disable [app.instance]`](17_app-analytics-disable/app-analytics-disable.md)
3. [`orbit app:analytics show [app.instance]`](18_app-analytics-show/app-analytics-show.md)
4. [`orbit app:analytics verify [app.instance]`](21_app-analytics-verify/app-analytics-verify.md)
5. [`orbit app:instance list|show|add|remove [app]`](19_app-instance/app-instance.md)
6. [`orbit app:env list|set|render [app]`](20_app-env/app-env.md)

### App tooling and setup

Use these commands for setup steps. Codex App registration lives in the
[`codex`](../23_codex/README.md) extension command domain.

1. [`orbit app:setup [app]`](22_app-setup/app-setup.md)
2. [`orbit app-setup-step:add [app]`](23_app-setup-step-add/app-setup-step-add.md)
3. [`orbit app-setup-step:list [app]`](24_app-setup-step-list/app-setup-step-list.md)
4. [`orbit app-setup-step:remove [app]`](25_app-setup-step-remove/app-setup-step-remove.md)

## Related

- [`doctor --family=app`](app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
