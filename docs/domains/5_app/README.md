# App Commands

App commands manage gateway-owned app configuration and the app-role artifacts derived
from that configuration. Apps belong to nodes. Gateway and clients are not
valid app targets.

## Domain Rules

These rules govern all app family commands.

- The gateway owns app registry, runtime policy, deployment policy, and app
  health configuration.
- App names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- App-role artifacts are applied by the gateway over SSH.
- Apps may be development or production apps.
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
  app configuration. Changing `php_version` recreates the app runtime container
  from the selected PHP image; it does not install host PHP or render host
  PHP-FPM pools.
- App command execution that needs PHP, Composer, or Artisan runs inside the
  app runtime container through explicit app execution surfaces such as
  `app:exec`. Host PHP and host Composer are not fallbacks.
- Worker mode is an opt-in app runtime setting. It is disabled by default and
  `app:worker enable` must validate app readiness before changing gateway
  configuration.
- App WebSocket bindings are explicit app-owned configuration. They enable one
  app to use the fleet websocket service, own per-app Reverb credentials,
  allowed origins, public WebSocket hosts, and private `websocket.orbit`
  publishing configuration. App commands own the binding state; `ingress`
  owns public route exposure, `router` owns route selection and backend pools,
  and the `websocket` role owns the Reverb runtime.
- Production deployment pipeline definitions belong to apps. Deployments and
  releases are not standalone state families.
- `app:prune` is source-of-truth cleanup, not doctor drift repair. It checks
  configured agent IDE adapters for the app, uses workspace removal semantics
  for stale workspaces, and can be scheduled through normal schedules.

Read commands over app registry state are fast gateway database reads unless
their command contract explicitly opts into live inspection. App runtime drift
belongs to [`app-doctor.md`](app-doctor.md). Implementation-shape details for
gateway-to-node application and process managers live in
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node) and
[tech-stack.md#process-manager](../../tech-stack.md#process-manager).

Production app routes enter through `ingress`, are forwarded over
WireGuard to the gateway-coupled `router`, and only then fan out to private
`app-production` backend artifacts. App commands choose the ingress
placement; the router owns private route selection and backend-pool targeting.
The backend artifact terminates at `orbit-caddy` on the app-production node and
then reaches the app's FrankenPHP container over the node Docker network.

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
| `node` | string | Owning node slug. The node's active role (`app-development` or `app-production`) determines the app's environment; the app entity does not carry a separate `environment` field. |
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
the `app-development` and `app-production` self-grant baselines include the
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
10. Planned Docker-first runtime command: `orbit app:exec [app] -- <command>`.
11. Planned Docker-first worker commands: `orbit app:worker show|enable|disable`.

## Related

- [`doctor --family=app`](app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
