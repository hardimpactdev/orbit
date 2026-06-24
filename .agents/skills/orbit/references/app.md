# App Commands

Manage Orbit apps. Apps are logical gateway records with one or more concrete
app instances. Orbit-driven instances live on nodes with an active `app-dev` or
`app-prod` role; Laravel Cloud instances store the external app/environment
relationship. Gateway-only nodes and client identities with no app role are
never valid Orbit app-instance targets. All app commands flow through the
gateway. Spec:
[`apps/docs/content/domains/5_app/`](../../../apps/docs/content/domains/5_app/).

Development apps are served at `{name}.{node-tld}` (e.g. `myapp.beast`). Production apps are served at the configured `--domain`, which must be globally unique across the fleet.

PHP apps use dedicated FrankenPHP runtime containers represented by
process-backed Docker runtime units. Static apps serve files through
`orbit-caddy` and do not get a PHP runtime container. Production PHP apps run
as a dedicated app runtime user and are reachable only through the private
app-role backend route unless the same node also carries `ingress`.

App instances record driver config, required PHP extensions, and instance env
state. Keep FrankenPHP as the web runtime; do not switch app/workspace runtime
workflows to host PHP-FPM.

## `orbit app:new [name]`

Create or clone a new app on an app-role node.

```bash
orbit app:new [<name>] [--node=<name>] [--repo=<git>] [--root=public]
              [--php-version=8.5] [--domain=<host>]
              [--runtime-proxy-transport=http|https] [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | App slug (<=40 chars, globally unique). |
| `--node` | local default | Target app-role node. Required when no `node:default`. |
| `--repo` |  -  | Git URL or `owner/repo` GitHub shorthand (expands to `git@github.com:owner/repo.git`). Cloning runs as the SSH user already configured on the node  -  Orbit doesn't proxy git credentials. Omitted = empty directory. |
| `--root` | `public` | Document root relative to app path. |
| `--php-version` | `8.5` | Initial PHP version (one of 8.3, 8.4, 8.5). |
| `--domain` |  -  | Production: triggers production setup. If DNS/TLS isn't ready yet, the app installs but the domain stays inactive  -  re-run `app:register --domain=...` to retry. |
| `--runtime-proxy-transport` | `http` | FrankenPHP inner proxy transport (`http` or `https`). |
| `--stream-json` | off | JSONL progress stream for agents; mutually exclusive with `--json`. |

Examples:

```bash
orbit app:new                                              # interactive
orbit app:new docs --repo=acme/docs                        # dev app, default node
orbit app:new docs --node=beast --repo=acme/docs --root=public
orbit app:new shop --node=prod-1 --repo=acme/shop --domain=shop.example.com
```

## `orbit app:register [name]`

Register or re-apply Orbit management for an existing app path. Idempotent:
adopts an existing path, reconciles the app runtime/route intent, and retries
production domain activation. No clone.

```bash
orbit app:register [<name>] [--node=<name>] [--path=<path>] [--root=public]
                   [--php-version=8.5] [--domain=<host>] [--json]
```

Use this:

- After moving an existing project under Orbit management.
- To re-converge app runtime and route artifacts after upgrading older apps.
- To retry production domain activation after DNS propagated.

## `orbit app:list`

List registered apps.

```bash
orbit app:list [--node=<name>] [--environment=development|production] [--json]
```

Reads gateway DB only  -  no SSH.

## `orbit app:show [app]`

Show app intent, owning node, URL, effective Agent IDE, app-owned route summary,
runtime kind, worker mode, and PHP version.

```bash
orbit app:show [<app>] [--json]
```

`<app>` accepts the slug or hostname.

## `orbit app:root [app] [root]`

Change the app document root.

```bash
orbit app:root [<app>] [<root>] [--json]
```

`<root>` is relative to the app path (e.g. `public`, `web`, `dist`). Re-renders Caddy config.

## `orbit app:setup [app]`

Run an app's recorded setup pipeline on the owning app node.

```bash
orbit app:setup [<app>] [--json|--stream-json]
orbit app-setup-step:add [<app>] [--command=<command>] [--before=<id>] [--after=<id>] [--timeout=600] [--json]
orbit app-setup-step:list [<app>] [--json]
orbit app-setup-step:remove [<app>] [--step=<id>] [--force] [--json]
```

Setup steps are finite bootstrap commands. PHP, Composer, and Artisan commands
use the app node host PHP toolchain selected by the app's PHP version. Use
processes for long-running services.

## `orbit app:remove [app]`

Remove an app and its owned artifacts: gateway registry row, app runtime
container/process intent, Caddy config, owned routes, deployment intent, and
workspace state. For production apps, also removes the dedicated app user and
owned app path.

```bash
orbit app:remove [<app>] [--force] [--json]
```

Orbit removes the app runtime artifact and route intent through the gateway;
do not manually delete runtime containers or Caddy config.

## `orbit app:prune [app]`

Remove stale workspaces for an app. Stale = workspaces present on disk but unknown to the configured/effective Agent IDE adapter.

```bash
orbit app:prune [<app>] [--dry-run] [--force] [--json]
```

`--dry-run` shows what would be removed. `--force` skips confirmation. Removal goes through workspace-removal semantics, not a raw `rm`.

## `orbit app:agent-ide [app] [adapter]`

Set or inherit the Agent IDE adapter for one app.

```bash
orbit app:agent-ide [<app>] [opencode|polyscope|inherit|none] [--force] [--json]
```

`inherit` means "use the node default" (set with `node:agent-ide`). Switching adapter may invalidate workspaces created by the old one  -  `--force` confirms the resulting workspace cleanup without prompting.

## `orbit app:worker [app]`

Inspect or change FrankenPHP worker mode for a PHP app.

```bash
orbit app:worker [show|enable|disable] [<app>] [--json]
```

Worker mode is opt-in. Keep it disabled unless the app has been validated for
long-lived Laravel workers.

## `orbit app:instance [action] [app]`

Manage concrete runtime/deploy targets for a logical app.

```bash
orbit app:instance list [<app>] [--app=<app>] [--json]
orbit app:instance show [<app>] [--app=<app>] --instance=<name> [--json]
orbit app:instance add [<app>] [--app=<app>] --instance=<name> [--driver=orbit|laravel-cloud] [--json]
orbit app:instance remove [<app>] [--app=<app>] --instance=<name> --force [--json]
```

Use `--app` for scripts/agents when positional parsing is awkward. If `[app]`
and `--app` are both supplied, they must match. `--php-extension` is repeatable
and records required PHP extensions for runtime/Cloud compatibility checks.

Examples:

```bash
orbit app:instance add billing --instance=development --driver=orbit --node=app-dev-1
orbit app:instance add billing --instance=production-cloud --driver=laravel-cloud --cloud-app=app_123 --cloud-environment=env_123 --php-extension=redis --php-extension=intl
```

## `orbit app:env [action] [app]`

Manage and render non-secret env values for one app instance.

```bash
orbit app:env list [<app>] [--app=<app>] --instance=<name> [--json]
orbit app:env set [<app>] [--app=<app>] --instance=<name> --key=<KEY> --value=<value> [--apply] [--json]
orbit app:env render [<app>] [--app=<app>] --instance=<name> [--json]
```

Use `--apply` on `set` to persist gateway intent and update the live app `.env`,
clear Laravel caches, and reapply the runtime container. Without `--apply`, `set`
remains gateway state only.

Secret env writes are intentionally rejected for now. Attach database
connections with `database:attach --app=<app> --instance=<name>` and use
`app:env render` to see the effective env with secret values redacted.

## `orbit app:websocket enable | disable | credentials`

Manage one app's binding to the fleet websocket service.

```bash
orbit app:websocket enable [<app>] [--host=<public-host>] [--json]
orbit app:websocket disable [<app>] [--json]
orbit app:websocket credentials [<app>] [--json]
```

The websocket runtime itself belongs to nodes carrying the `websocket` role.
App commands only manage the app-owned binding, credentials, allowed origins,
and public WebSocket route intent.
