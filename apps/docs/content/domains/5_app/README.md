# App and Instance Commands

App and instance commands manage gateway-owned app configuration and the concrete runtime or
deployment instances derived from that configuration. An app is a logical
application record. An instance is one concrete place that app can run, such
as an Orbit-managed development node, an Orbit-managed production node, or a
Laravel Cloud environment.

## Domain Rules

These rules govern all instance family commands.

- The gateway owns app registry, instance registry, runtime policy,
  instance deployment policy, and app health configuration.
- App names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- Instance-host artifacts on non-gateway nodes are applied through Agent push.
  Gateway-owned work executes locally. Provisioning is the sole permanent
  Orbit SSH lane.
- Apps may have one or more instances. Instance names are unique within the
  app.
- An instance has exactly one driver. Current drivers are `orbit` and
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
- Apps store shared app identity and runtime policy only. They do
  not store a server, path, root, URL, domain, or environment default.
  Every placement fact belongs to one concrete instance.
- Instance env values and database targets belong to the instance, not the
  app. Rendering an instance env merges explicit instance env values with
  database attachments for that instance.
- App and instance hostnames are represented in `proxy` as app-owned route records.
  App and instance commands create, update, and remove the app configuration that owns those
  routes; proxy route registry, router route convergence, and backend artifact
  convergence belong to the `proxy` family.
- Commands that create or set up instances use explicit `--node` first, then the
  local `node:default` node when configured.
- `app:new` creates or clones one source path, then atomically creates the
  app and its first named instance before using `instance:register`
  behavior to converge that instance's configuration and node artifacts. The
  first instance is named `development` without `--domain` and `production`
  with `--domain`.
- `instance:register` is idempotent for one concrete instance. It can create or
  adopt a named instance, re-apply Orbit management for that instance, move
  only that instance, or retry its production-domain activation. A first
  adoption atomically creates the app and its first instance.
- Development-server behavior for instance and workspace processes is owned by the
  `process` family. App and instance commands record shared runtime policy, while the
  selected instance records URL and document root; they do not create
  Vite-specific proxy routes or rewrite app-side frontend configuration.
- Process definitions for instance and workspace contexts belong to one concrete
  instance. Process commands accept dotted selectors such as `docs.nmbp`; bare
  app shorthand is valid only when the app has exactly one instance.
- PHP app runtime uses a FrankenPHP app runtime container selected by gateway
  app configuration. The concrete FrankenPHP runtime, managed through the
  process lifecycle, is represented as a process with Docker runtime. Changing
  an instance's `php_version` recreates that instance's runtime artifact from
  the selected PHP image; it does not install host PHP or render host FPM pools.
- Ad-hoc PHP, Composer, or Artisan for an instance runs on that instance's
  serving node host PHP toolchain (matched to the resolved instance PHP
  version), against the instance source path the FrankenPHP container serves. Orbit ships no
  command-`exec` surface; deploy steps use the same host toolchain.
- Instance setup is lifecycle-specific, not a generic exec surface.
  `instance-setup-step:*` records ordered setup commands for one instance, and
  `instance:setup` runs those commands on that instance's serving node and path
  through the same host PHP, Composer, and Artisan routing used by deploy
  steps. Dotted selectors address the instance; a bare app selector is
  shorthand only when exactly one instance exists.
- `instance:setup` is idempotent for an unchanged setup-step set. Re-running setup
  with no step changes returns the latest completed run instead of replaying
  commands.
- Worker mode is an opt-in instance runtime setting. It is disabled by
  default for every instance, and `instance:worker enable` must validate readiness
  on the selected instance before changing gateway configuration. Different
  instances of one app may carry different worker policy.
- Instance runtime mounts are extra bind mounts stored on instances for PHP
  runtimes on `app-dev` nodes. Workspaces inherit the selected instance's
  configured mounts; mount intent is exclusively instance-owned.
- Instances record required PHP extensions. For Orbit-driven PHP instances,
  `doctor --family=instance` reports missing or unverifiable extensions against the
  concrete FrankenPHP runtime container.
- Instance WebSocket bindings select one concrete instance and site. They enable
  that instance to use the fleet websocket service, own Reverb credentials,
  allowed origins derived from that instance's domain, public WebSocket hosts,
  and private `websocket.orbit` publishing configuration. App and instance commands own the
  binding state; `ingress`
  owns public route exposure, `router` owns route selection and backend pools,
  and the `websocket` role owns the Reverb runtime.
- Instance analytics bindings select one concrete instance and site. They enable
  that instance to use the fleet analytics service through public tracking
  hostnames such as `analytics.example.com`, derived by default from that
  instance's domain. App and instance commands own the binding state and host list;
  `ingress` owns public route exposure, `router` owns tracking-only route
  selection and backend pools, and the `analytics` role owns the Plausible CE
  runtime. V1 does not inject scripts, provision Plausible sites, or expose the
  Plausible dashboard publicly.
- Apps may be registered in Codex App on an eligible operator node through the
  optional [`codex:app`](../22_codex/1_codex-app/codex-app.md) extension
  command. The command edits only Codex App's config file on the target node
  and applies Codex App's URL callback.
- Production deployment policy, ordered steps, warmup paths, runs, history,
  logs, and latest status belong to one concrete instance. `deploy:run`
  accepts the canonical dotted instance selector, while a bare app name is
  shorthand only when that app has exactly one instance.
- App dependency audit posture is gateway-owned summary state for registered
  app source paths. The v1 storage and presentation slice records compact
  per-manager summaries derived from lockfile-aware audit commands such as
  `composer audit --format=json` and `npm audit --json`, treats Bun as a
  separate manager status, and exposes aggregate `dependency_audit_status`,
  `dependency_warning_count`, `dependency_danger_count`, and
  `last_dependency_audit_at` on `app:list` and `app:show` JSON. Human
  `app:show` labels this aggregate as instance-scoped and does not imply that the
  status belongs to an individual instance or workspace. It does not
  store full package inventories, mutate source, read logs as dependency truth,
  or auto-remediate findings. Remote audit refresh execution, workspace
  coverage, nightly fleet refresh, and full Bun vulnerability normalization are
  follow-up slices.

- Instance doctor, worker, and setup commands resolve concrete placement exactly as
  deployment and process commands do. A dotted selector such as
  `docs.production` is explicit. A selector containing only the app
  name succeeds for an app with exactly one instance; otherwise the command fails with
  a validation error requiring a concrete instance selector before
  authorization or side effects.

The same concrete-instance rule applies to `instance:register`, `instance:root`,
and the other instance-scoped commands in this family. `app:remove` is the
deliberate exception: it accepts only a logical app slug and, with explicit
destructive consent, removes that app plus every owned instance as one
authorized cascade. Dotted selectors and instance hostnames belong to
`instance:remove`.

Read commands over app registry state are fast gateway database reads unless
their command contract explicitly opts into live inspection. Instance runtime drift
belongs to [`instance-doctor.md`](instance-doctor.md). Implementation-shape details for
gateway-to-node application and process managers live in
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node) and
[tech-stack.md#process-manager](../../tech-stack.md#process-manager).

Orbit-managed production app routes enter through `ingress`, are forwarded over
WireGuard to the gateway-coupled `router`, and only then fan out to private
`app-prod` backend artifacts. App and instance commands choose the ingress placement for
Orbit-driven production. The router owns private route selection and
backend-pool targeting. The app-prod backend artifact is app-host-owned and
separate from the API Caddy route that is colocated with the gateway router. It
terminates at `orbit-caddy` on the app-prod node and then reaches the app's
FrankenPHP Docker runtime container on internal port `8080` over the node Docker
network. Laravel Cloud production instances are represented as external
driver-backed instances instead of Orbit-owned ingress/router/app-prod artifacts.

Production application runtime policy is app-owned, while the concrete long-running
runtime unit is process-owned. The runtime container uses a
path-derived app user, must not grant that user Docker group or Docker socket
access, and may bind mount only the app source or active release path plus
explicitly managed shared paths. Runnable services such as MySQL, PostgreSQL,
Valkey, SeaweedFS, and Reverb are modeled as process-backed long-running units,
with tool capability records only where a process depends on an installed host
capability; they are not owned by `app-prod`.

## App and Instance Identity Arguments

App and instance command signatures use two positional names intentionally:

- `[name]` is an app identity slug for commands that create, adopt, or
  re-converge app configuration. It is not a hostname selector. When the
  command targets placement, it creates or selects one named instance.
- `[instance]` is an existing-instance selector for placement-sensitive commands.
  It may be a dotted `app.instance` selector or an instance hostname when the command
  contract says hostname resolution is supported. Name matches win over
  hostname matches.

Placement-sensitive commands use a dotted `<app>.<instance>` selector. Their
contracts may admit a bare app slug only when exactly one eligible,
visible instance exists. `app:remove` never uses this selector model: its
`[app]` argument is an app slug only.

## App JSON Entity

When a JSON renderer in the instance family returns an app, it embeds the
canonical app entity under `success.data.app`. Concrete placement is returned
separately under `success.data.instance` or a command-specific instance list.
Command-specific result state belongs beside the entity, not inside it.

`app:list` is the intentional exception: `success.data.apps[]` contains compact
app summaries, not this placement-shaped entity. Each summary carries
`name`, `repository`, aggregate dependency-audit posture, `instance_count`, and
`workspace_count`. Concrete node, URL, path, runtime, instance, and workspace
rows belong to `app:show` and `instance`.

`app:show` follows this rule: `success.data.app` is the canonical
app entity, while show-only registry expansion such as concrete
instances, instance-nested workspaces, process definitions, routes, and
effective instance details live under `success.data.details`. Do not merge
those show-only relationships into the canonical app entity. There is no flat
app workspace fallback. Workspace expansion includes only active
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
| `php_version` | string | PHP creation template recorded in gateway app configuration. New instances copy it; it does not describe what any existing instance runs. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `dependency_audit_status` | string | Aggregate dependency posture for the app. |
| `dependency_warning_count` | integer | Number of warning-severity dependency findings in the latest summaries. |
| `dependency_danger_count` | integer | Number of danger-severity dependency findings in the latest summaries. |
| `last_dependency_audit_at` | string \| null | Latest completed dependency audit time, or `null` when no audit has completed. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as an absent repository.

## Instance JSON Entity

Instance renderers return the gateway Instance payload under
`success.data.instance`, or under `success.data.instances[]` for list output.
Placement is projected at the top level as `environment`, `node`, `url`,
`path`, `root`, and `domain`. `driver_config` is the serialized driver-specific
stored data object. For the Orbit driver it uses `node_id`, `node`, `path`,
`document_root`, and `domain`.

```json
{
  "app": "docs",
  "name": "production",
  "driver": "orbit",
  "environment": "production",
  "node": "app-1",
  "url": "https://docs.example.com",
  "path": "/home/docs/app",
  "root": "public",
  "domain": "docs.example.com",
  "adopted": false,
  "driver_config": {
    "node_id": 1,
    "node": "app-1",
    "path": "/home/docs/app",
    "document_root": "public",
    "domain": "docs.example.com"
  },
  "runtime": {
    "runtime": "php",
    "php_version": "8.5",
    "frankenphp_image": "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm",
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
| `app` | string | App identity slug. |
| `name` | string | Instance name, unique within the app. |
| `driver` | string | Instance driver: `orbit` or `laravel-cloud`. |
| `environment` | string \| null | Public environment projection. Orbit Instances use the Instance name; Laravel Cloud uses the provider environment name. |
| `node` | string \| null | Public serving-node projection for Orbit Instances. |
| `url` | string \| null | Public HTTPS URL projection. |
| `path` | string \| null | Public source-path projection for Orbit Instances. |
| `root` | string \| null | Public document-root projection for Orbit Instances. |
| `domain` | string \| null | Public domain projection. |
| `adopted` | boolean | Whether this concrete path was adopted through `instance:register`. It never belongs to the app. |
| `driver_config` | object | Driver-specific Laravel Data object serialized through the gateway. |
| `runtime` | object | Effective runtime metadata for this instance. |
| `runtime.runtime` | string | App runtime. |
| `runtime.php_version` | string | The instance's own PHP version, which its runtime container uses. It may differ from the app creation template and from a sibling instance. |
| `runtime.frankenphp_image` | string \| null | Resolved FrankenPHP image for PHP apps. |
| `runtime.mode` | string | `classic` or `worker` for PHP apps. |
| `runtime.configured_mounts` | array | Instance-scoped runtime mounts rendered into Orbit PHP runtimes for the selected instance. |
| `runtime.required_php_extensions` | array | Required PHP extensions tracked for the instance. |
| `worker_enabled` | boolean | Whether FrankenPHP worker mode is enabled for this instance. Defaults to `false`. |
| `worker_config` | object \| null | Worker settings owned by this instance and retained across disable/enable cycles. |
| `deploy_warmup_paths` | array | HTTP paths warmed after this instance deploys successfully. |
| `latest_deployment_status` | string \| null | Status derived from the greatest deployment run ID owned by this instance. |
| `latest_deployment_run_id` | integer \| null | Greatest deployment run ID owned by this instance. The run is the sole durable owner of this state. |

In the current converted app and instance command surface, `app:new` is the only command that
records repository metadata. `instance:register` preserves an existing app's stored
repository value and stores `repository=null` when adopting an unmanaged path.

## Authorization

App and instance commands use gateway-owned access policy. The gateway authenticates the
caller's WireGuard peer and applies the scoped permission set on the grant
linking the caller to each selected instance's serving node. The CLI never
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

`app-dev` self-grants also include `instance:register` for the node itself, so a
local CLI on an app-dev node can register or re-apply management for apps hosted
by that same node. `app-prod` self-grants do not include `instance:register`; production
app registration remains operator/deploy-driven. Cross-node app registration is
denied unless the caller has an explicit grant to the target app node.

## Commands

The following commands are available across the `app` and `instance` families.

### App Lifecycle

Use these commands to create, inspect, and remove app records.

1. [`orbit app:new [name]`](1_app-new/app-new.md)
2. [`orbit instance:register [name]`](2_instance-register/instance-register.md)
3. [`orbit app:list`](3_app-list/app-list.md)
4. [`orbit app:show [app]`](4_app-show/app-show.md)
5. [`orbit instance:root [app.instance] [root]`](5_instance-root/instance-root.md)
6. [`orbit app:remove [app]`](6_app-remove/app-remove.md)
7. Reserved for a future app metadata update command. No `app:update` command
   contract exists in the current converted surface.

### Instance Runtime Features

Use these commands to configure runtime-facing instance capabilities.

1. Reserved. Orbit has no command-`exec` surface.
2. [`orbit instance:worker show|enable|disable [instance]`](11_instance-worker/instance-worker.md)
3. [`orbit instance:websocket enable [app.instance]`](12_instance-websocket-enable/instance-websocket-enable.md)
4. [`orbit instance:websocket disable [app.instance]`](13_instance-websocket-disable/instance-websocket-disable.md)
5. [`orbit instance:websocket credentials [app.instance]`](14_instance-websocket-credentials/instance-websocket-credentials.md)
6. [`orbit instance:mount list|add|remove [app.instance]`](15_instance-mount/instance-mount.md)

### Instance Integrations

Use these commands for analytics, instances, and env values.

1. [`orbit instance:analytics enable [app.instance]`](16_instance-analytics-enable/instance-analytics-enable.md)
2. [`orbit instance:analytics disable [app.instance]`](17_instance-analytics-disable/instance-analytics-disable.md)
3. [`orbit instance:analytics show [app.instance]`](18_instance-analytics-show/instance-analytics-show.md)
4. [`orbit instance:analytics verify [app.instance]`](21_instance-analytics-verify/instance-analytics-verify.md)
5. [`orbit instance:list`](19_instance-list/instance-list.md)
6. [`orbit instance:show [app.instance]`](26_instance-show/instance-show.md)
7. [`orbit instance:add [app.instance]`](27_instance-add/instance-add.md)
8. [`orbit instance:remove [app.instance] --force`](28_instance-remove/instance-remove.md)
9. [`orbit instance:env list|set|render [app.instance]`](20_instance-env/instance-env.md)
10. [`orbit instance:log [target]`](29_instance-log/instance-log.md)
11. [`orbit app:log [target]`](30_app-log/app-log.md)

### Instance Tooling and Setup

Use these commands for setup steps. Codex App registration lives in the
[`codex`](../22_codex/README.md) extension command domain.

1. [`orbit instance:setup [instance]`](22_instance-setup/instance-setup.md)
2. [`orbit instance-setup-step:add [instance]`](23_instance-setup-step-add/instance-setup-step-add.md)
3. [`orbit instance-setup-step:list [instance]`](24_instance-setup-step-list/instance-setup-step-list.md)
4. [`orbit instance-setup-step:remove [instance]`](25_instance-setup-step-remove/instance-setup-step-remove.md)

## Related

- [`doctor --family=instance`](instance-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
