# Project and Instance Commands

Project and instance commands manage gateway-owned project configuration and the concrete runtime or
deployment instances derived from that configuration. A project is a logical
application record. An instance is one concrete place that project can run, such
as an Orbit-managed development node, an Orbit-managed production node, or a
Laravel Cloud environment.

## Domain Rules

These rules govern all instance family commands.

- The gateway owns project registry, instance registry, runtime policy,
  instance deployment policy, and project health configuration.
- Project names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- Instance-host artifacts on non-gateway nodes are applied through Agent push.
  Gateway-owned work executes locally. Provisioning is the sole permanent
  Orbit SSH lane.
- Projects may have one or more instances. Instance names are unique within the
  project.
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
- Projects store shared project identity and runtime policy only. They do
  not store a server, path, root, URL, domain, or environment default.
  Every placement fact belongs to one concrete instance.
- Instance env values and database targets belong to the instance, not the
  project. Rendering an instance env merges explicit instance env values with
  database attachments for that instance.
- Project and instance hostnames are represented in `proxy` as project-owned route records.
  Project and instance commands create, update, and remove the project configuration that owns those
  routes; proxy route registry, router route convergence, and backend artifact
  convergence belong to the `proxy` family.
- Commands that create or set up instances use explicit `--node` first, then the
  local `node:default` node when configured.
- `project:new` creates or clones one source path, then atomically creates the
  project and its first named instance before using `instance:register`
  behavior to converge that instance's configuration and node artifacts. The
  first instance is named `development` without `--domain` and `production`
  with `--domain`.
- `instance:register` is idempotent for one concrete instance. It can create or
  adopt a named instance, re-apply Orbit management for that instance, move
  only that instance, or retry its production-domain activation. A first
  adoption atomically creates the project and its first instance.
- Instances may configure an agent IDE adapter through `instance:agent-ide`.
  Effective resolution is instance override, then that instance's serving-node
  default, then no adapter.
- Development-server behavior for instance and workspace processes is owned by the
  `process` family. Project and instance commands record shared runtime policy, while the
  selected instance records URL and document root; they do not create
  Vite-specific proxy routes or rewrite app-side frontend configuration.
- Process definitions for instance and workspace contexts belong to one concrete
  instance. Process commands accept dotted selectors such as `docs.nmbp`; bare
  project shorthand is valid only when the project has exactly one instance.
- PHP app runtime uses a FrankenPHP app runtime container selected by gateway
  project configuration. The concrete FrankenPHP runtime, managed through the
  process lifecycle, is represented as a process with Docker runtime. Changing
  `php_version` recreates the app runtime artifact from the selected PHP image;
  it does not install host PHP or render host FPM pools.
- Ad-hoc PHP, Composer, or Artisan for an instance runs on that instance's
  serving node host PHP toolchain (matched to the project's PHP version), against
  the instance source path the FrankenPHP container serves. Orbit ships no
  command-`exec` surface; deploy steps use the same host toolchain.
- Instance setup is lifecycle-specific, not a generic exec surface.
  `instance-setup-step:*` records ordered setup commands for one instance, and
  `instance:setup` runs those commands on that instance's serving node and path
  through the same host PHP, Composer, and Artisan routing used by deploy
  steps. Dotted selectors address the instance; a bare project selector is
  shorthand only when exactly one instance exists.
- `instance:setup` is idempotent for an unchanged setup-step set. Re-running setup
  with no step changes returns the latest completed run instead of replaying
  commands.
- Worker mode is an opt-in instance runtime setting. It is disabled by
  default for every instance, and `instance:worker enable` must validate readiness
  on the selected instance before changing gateway configuration. Different
  instances of one project may carry different worker policy.
- Instance runtime mounts are extra bind mounts stored on instances for PHP
  runtimes on `app-dev` nodes. Workspaces inherit the selected instance's
  configured mounts; mount intent is exclusively instance-owned.
- Instances record required PHP extensions. For Orbit-driven PHP instances,
  `doctor --family=instance` reports missing or unverifiable extensions against the
  concrete FrankenPHP runtime container.
- Instance WebSocket bindings select one concrete instance and site. They enable
  that instance to use the fleet websocket service, own Reverb credentials,
  allowed origins derived from that instance's domain, public WebSocket hosts,
  and private `websocket.orbit` publishing configuration. Project and instance commands own the
  binding state; `ingress`
  owns public route exposure, `router` owns route selection and backend pools,
  and the `websocket` role owns the Reverb runtime.
- Instance analytics bindings select one concrete instance and site. They enable
  that instance to use the fleet analytics service through public tracking
  hostnames such as `analytics.example.com`, derived by default from that
  instance's domain. Project and instance commands own the binding state and host list;
  `ingress` owns public route exposure, `router` owns tracking-only route
  selection and backend pools, and the `analytics` role owns the Plausible CE
  runtime. V1 does not inject scripts, provision Plausible sites, or expose the
  Plausible dashboard publicly.
- Projects may be registered in Codex App on an eligible operator node through the
  optional [`codex:app`](../23_codex/1_codex-app/codex-app.md) extension
  command. The command edits only Codex App's config file on the target node
  and applies Codex App's URL callback; it does not configure the project's agent
  IDE adapter.
- Production deployment policy, ordered steps, warmup paths, runs, history,
  logs, and latest status belong to one concrete instance. `deploy:run`
  accepts the canonical dotted instance selector, while a bare project name is
  shorthand only when that project has exactly one instance.
- `instance:prune` is source-of-truth cleanup for one concrete `app-dev` instance,
  not doctor drift repair. It checks that instance's effective agent IDE
  adapter, uses workspace removal semantics for stale workspaces owned by that
  instance, and can be scheduled through normal schedules.
- Project dependency audit posture is gateway-owned summary state for registered
  project source paths. The v1 storage and presentation slice records compact
  per-manager summaries derived from lockfile-aware audit commands such as
  `composer audit --format=json` and `npm audit --json`, treats Bun as a
  separate manager status, and exposes aggregate `dependency_audit_status`,
  `dependency_warning_count`, `dependency_danger_count`, and
  `last_dependency_audit_at` on `project:list` and `project:show` JSON. Human
  `project:show` labels this aggregate as instance-scoped and does not imply that the
  status belongs to an individual instance or workspace. It does not
  store full package inventories, mutate source, read logs as dependency truth,
  or auto-remediate findings. Remote audit refresh execution, workspace
  coverage, nightly fleet refresh, and full Bun vulnerability normalization are
  follow-up slices.

- Instance doctor, worker, and setup commands resolve concrete placement exactly as
  deployment and process commands do. A dotted selector such as
  `docs.production` is explicit. A selector containing only the project
  name succeeds for a project with exactly one instance; otherwise the command fails with
  a validation error requiring a concrete instance selector before
  authorization or side effects.

The same concrete-instance rule applies to `instance:register`, `instance:root`,
`instance:prune`, `instance:agent-ide`, WebSocket, analytics, and other placement-sensitive
commands. `project:remove` is the deliberate exception: it accepts only a logical
project slug and, with explicit destructive consent, removes that project plus every
owned instance as one authorized cascade. Dotted selectors and instance
hostnames belong to `instance:remove`.

Read commands over project registry state are fast gateway database reads unless
their command contract explicitly opts into live inspection. Instance runtime drift
belongs to [`instance-doctor.md`](instance-doctor.md). Implementation-shape details for
gateway-to-node application and process managers live in
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node) and
[tech-stack.md#process-manager](../../tech-stack.md#process-manager).

Orbit-managed production app routes enter through `ingress`, are forwarded over
WireGuard to the gateway-coupled `router`, and only then fan out to private
`app-prod` backend artifacts. Project and instance commands choose the ingress placement for
Orbit-driven production. The router owns private route selection and
backend-pool targeting. The app-prod backend artifact is app-host-owned and
separate from the API Caddy route that is colocated with the gateway router. It
terminates at `orbit-caddy` on the app-prod node and then reaches the app's
FrankenPHP Docker runtime container on internal port `8080` over the node Docker
network. Laravel Cloud production instances are represented as external
driver-backed instances instead of Orbit-owned ingress/router/app-prod artifacts.

Production application runtime policy is project-owned, while the concrete long-running
runtime unit is process-owned. The runtime container uses a
path-derived app user, must not grant that user Docker group or Docker socket
access, and may bind mount only the app source or active release path plus
explicitly managed shared paths. Runnable services such as MySQL, PostgreSQL,
Valkey, SeaweedFS, and Reverb are modeled as process-backed long-running units,
with tool capability records only where a process depends on an installed host
capability; they are not owned by `app-prod`.

## Project and Instance Identity Arguments

Project and instance command signatures use two positional names intentionally:

- `[name]` is a project identity slug for commands that create, adopt, or
  re-converge project configuration. It is not a hostname selector. When the
  command targets placement, it creates or selects one named instance.
- `[instance]` is an existing-instance selector for placement-sensitive commands.
  It may be a dotted `project.instance` selector or an instance hostname when the command
  contract says hostname resolution is supported. Name matches win over
  hostname matches.

Placement-sensitive commands use a dotted `<project>.<instance>` selector. Their
contracts may admit a bare project slug only when exactly one eligible,
visible instance exists. `project:remove` never uses this selector model: its
`[project]` argument is a project slug only.

## Project JSON Entity

When a JSON renderer in the instance family returns a project, it embeds the
canonical project entity under `success.data.project`. Concrete placement is returned
separately under `success.data.instance` or a command-specific instance list.
Command-specific result state belongs beside the entity, not inside it.

`project:list` is the intentional exception: `success.data.projects[]` contains compact
project summaries, not this placement-shaped entity. Each summary carries
`name`, `repository`, aggregate dependency-audit posture, `instance_count`, and
`workspace_count`. Concrete node, URL, path, runtime, instance, and workspace
rows belong to `project:show` and `instance`.

`project:show` follows this rule: `success.data.project` is the canonical
project entity, while show-only registry expansion such as concrete
instances, instance-nested workspaces, process definitions, routes, and
effective agent IDE details lives under `success.data.details`. Do not merge
those show-only relationships into the canonical project entity. There is no flat
project workspace fallback. Workspace expansion includes only active
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
| `name` | string | Project identity slug. Globally unique in the gateway project registry. |
| `repository` | string \| null | Source repository URL recorded for the project, or `null` when none is configured. |
| `runtime` | string | Runtime for the project. `php` uses a FrankenPHP app runtime container; `static` serves without one. |
| `runtime_config` | object \| null | Runtime-specific gateway configuration. PHP/FrankenPHP apps expose `proxy_transport`, which is `http` by default and may be `https` for app-dev inner TLS; static apps report `null`. |
| `php_version` | string | PHP version recorded in gateway project configuration. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `dependency_audit_status` | string | Aggregate dependency posture for the project. |
| `dependency_warning_count` | integer | Number of warning-severity dependency findings in the latest summaries. |
| `dependency_danger_count` | integer | Number of danger-severity dependency findings in the latest summaries. |
| `last_dependency_audit_at` | string \| null | Latest completed dependency audit time, or `null` when no audit has completed. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as an absent repository.

## Instance JSON Entity

Instance renderers return this shape under `success.data.instance`, or under
`success.data.instances[]` for list output.

```json
{
  "project": "docs",
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
| `project` | string | Project identity slug. |
| `name` | string | Instance name, unique within the project. |
| `driver` | string | Instance driver: `orbit` or `laravel-cloud`. |
| `driver_config` | object | Driver-specific Laravel Data object serialized through the gateway. |
| `adopted` | boolean | Whether this concrete path was adopted through `instance:register`. It never belongs to the project. |
| `runtime` | object | Effective runtime metadata for this instance. |
| `runtime.runtime` | string | Project runtime. |
| `runtime.php_version` | string | PHP version recorded for the project runtime. |
| `runtime.frankenphp_image` | string \| null | Resolved FrankenPHP image for PHP apps. |
| `runtime.mode` | string | `classic` or `worker` for PHP apps. |
| `runtime.configured_mounts` | array | Instance-scoped runtime mounts rendered into Orbit PHP runtimes for the selected instance. |
| `runtime.required_php_extensions` | array | Required PHP extensions tracked for the instance. |
| `worker_enabled` | boolean | Whether FrankenPHP worker mode is enabled for this instance. Defaults to `false`. |
| `worker_config` | object \| null | Worker settings owned by this instance and retained across disable/enable cycles. |
| `deploy_warmup_paths` | array | HTTP paths warmed after this instance deploys successfully. |
| `latest_deployment_status` | string \| null | Latest deployment status owned by this instance. |
| `latest_deployment_run_id` | integer \| null | Latest deployment run owned by this instance. |

In the current converted project and instance command surface, `project:new` is the only command that
records repository metadata. `instance:register` preserves an existing project's stored
repository value and stores `repository=null` when adopting an unmanaged path.

## Authorization

Project and instance commands use gateway-owned access policy. The gateway authenticates the
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

Commands that affect several instances, including `project:remove`, authorize the
complete affected serving-node set before consent or side effects.

`app-dev` self-grants also include `instance:register` for the node itself, so a
local CLI on an app-dev node can register or re-apply management for apps hosted
by that same node. `app-prod` self-grants do not include `instance:register`; production
app registration remains operator/deploy-driven. Cross-node app registration is
denied unless the caller has an explicit grant to the target app node.

## Commands

The following commands are available across the `project` and `instance` families.

### Project Lifecycle

Use these commands to create, inspect, and remove project records.

1. [`orbit project:new [name]`](1_project-new/project-new.md)
2. [`orbit instance:register [name]`](2_instance-register/instance-register.md)
3. [`orbit project:list`](3_project-list/project-list.md)
4. [`orbit project:show [project]`](4_project-show/project-show.md)
5. [`orbit instance:root [project.instance] [root]`](5_instance-root/instance-root.md)
6. [`orbit project:remove [project]`](6_project-remove/project-remove.md)
7. [`orbit instance:prune [project.instance]`](7_instance-prune/instance-prune.md)
8. Reserved for a future project metadata update command. No `project:update` command
   contract exists in the current converted surface.

### Instance Runtime Features

Use these commands to configure runtime-facing instance capabilities.

1. [`orbit instance:agent-ide [project.instance] [agent_ide]`](9_instance-agent-ide/instance-agent-ide.md)
2. Reserved. Orbit has no command-`exec` surface.
3. [`orbit instance:worker show|enable|disable [instance]`](11_instance-worker/instance-worker.md)
4. [`orbit instance:websocket enable [project.instance]`](12_instance-websocket-enable/instance-websocket-enable.md)
5. [`orbit instance:websocket disable [project.instance]`](13_instance-websocket-disable/instance-websocket-disable.md)
6. [`orbit instance:websocket credentials [project.instance]`](14_instance-websocket-credentials/instance-websocket-credentials.md)
7. [`orbit instance:mount list|add|remove [project.instance]`](15_instance-mount/instance-mount.md)

### Instance Integrations

Use these commands for analytics, instances, and env values.

1. [`orbit instance:analytics enable [project.instance]`](16_instance-analytics-enable/instance-analytics-enable.md)
2. [`orbit instance:analytics disable [project.instance]`](17_instance-analytics-disable/instance-analytics-disable.md)
3. [`orbit instance:analytics show [project.instance]`](18_instance-analytics-show/instance-analytics-show.md)
4. [`orbit instance:analytics verify [project.instance]`](21_instance-analytics-verify/instance-analytics-verify.md)
5. [`orbit instance:list`](19_instance-list/instance-list.md)
6. [`orbit instance:show [project.instance]`](26_instance-show/instance-show.md)
7. [`orbit instance:add [project.instance]`](27_instance-add/instance-add.md)
8. [`orbit instance:remove [project.instance] --force`](28_instance-remove/instance-remove.md)
9. [`orbit instance:env list|set|render [project.instance]`](20_instance-env/instance-env.md)

### Instance Tooling and Setup

Use these commands for setup steps. Codex App registration lives in the
[`codex`](../23_codex/README.md) extension command domain.

1. [`orbit instance:setup [instance]`](22_instance-setup/instance-setup.md)
2. [`orbit instance-setup-step:add [instance]`](23_instance-setup-step-add/instance-setup-step-add.md)
3. [`orbit instance-setup-step:list [instance]`](24_instance-setup-step-list/instance-setup-step-list.md)
4. [`orbit instance-setup-step:remove [instance]`](25_instance-setup-step-remove/instance-setup-step-remove.md)

## Related

- [`doctor --family=instance`](instance-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
