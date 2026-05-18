# App Commands

App commands manage gateway-owned app configuration and the app-node artifacts derived
from that configuration. Apps belong to app nodes. Gateway and control nodes are not
valid app targets.

## Domain Rules

These rules govern all app family commands.

- The gateway owns app registry, runtime policy, deployment policy, and app
  health configuration.
- App names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 40 characters.
- App-node artifacts are applied by the gateway over SSH.
- Apps may be development or production apps.
- App hostnames are represented in `proxy` as app-owned route records.
  App commands create, update, and remove the app configuration that owns those
  routes; proxy route registry and backend artifact convergence belong to the
  `proxy` family.
- Commands that create or set up apps use explicit `--node` first, then the
  local `node:default` development app node when configured.
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
- Production deployment pipeline definitions belong to apps. Deployments and
  releases are not standalone state families.
- `app:prune` is source-of-truth cleanup, not doctor drift repair. It checks
  configured agent IDE adapters for the app, uses workspace removal semantics
  for stale workspaces, and can be scheduled through normal schedules.

Read commands over app registry state are fast gateway database reads unless
their command contract explicitly opts into live inspection. App runtime drift
belongs to [`app-doctor.md`](app-doctor.md). Implementation-shape details for
gateway-to-app-node application and process managers live in
[tech-stack.md#gateway-to-app-node](../../tech-stack.md#gateway-to-app-node) and
[tech-stack.md#process-manager](../../tech-stack.md#process-manager).

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
  "environment": "production",
  "url": "https://docs.example.com",
  "path": "/home/docs/app",
  "root": "public",
  "repository": "git@github.com:my/repo.git",
  "php_version": "8.5",
  "adopted": false
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | App identity slug. Globally unique in the gateway app registry. |
| `node` | string | Owning app-node slug. |
| `environment` | string | `development` or `production`. |
| `url` | string | Primary intended URL for the app. |
| `path` | string | Absolute app path on the owning app node. |
| `root` | string | Document root relative to `path`. |
| `repository` | string \| null | Source repository URL recorded for the app, or `null` when none is configured. |
| `php_version` | string | PHP version recorded in gateway app configuration. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `adopted` | boolean | `true` once the app path was adopted through `app:register`; `false` for app records created by `app:new` or first registered without adoption. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as an absent repository.

In the current converted app command surface, `app:new` is the only command that
records repository metadata. `app:register` preserves an existing app's stored
repository value and stores `repository=null` when adopting an unmanaged path.

## Caller Role Rule

App commands use gateway-owned access policy for visibility and authorization.
App-node callers may run app read commands when authorized for the resolved app.
Hosted-role callers that are not the gateway, including app nodes and
database-only nodes, may not initiate app-level writes, cross-node app creation,
registration/adoption, destructive cleanup, source-of-truth pruning, or
preference changes unless a command explicitly documents a narrow exception.
The current local workflow exception is
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md), as
defined by [architecture.md#app-node](../../architecture.md#app-node) and owned by the
workspace command contract.

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

## Related

- [`doctor --family=app`](app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
