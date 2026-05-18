# App Commands

Manage Orbit apps. Apps live on app nodes; gateway and control nodes are never valid app targets. All app commands flow through the gateway. Spec: [`docs/domains/5_app/`](../../../docs/domains/5_app/).

Development apps are served at `{name}.{node-tld}` (e.g. `myapp.beast`). Production apps are served at the configured `--domain`, which must be globally unique across the fleet.

Each app gets a dedicated PHP-FPM pool at `/etc/php/{version}/fpm/pool.d/orbit-{slug}.conf`, owns its own OPcache segment, and (for production) runs as a dedicated non-login Unix user with files at `/home/{slug}/app`.

## `orbit app:new [name]`

Create or clone a new app on an app node.

```bash
orbit app:new [<name>] [--node=<name>] [--repo=<git>] [--root=public]
              [--php-version=8.5] [--domain=<host>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | App slug (≤40 chars, globally unique). |
| `--node` | local default | Target app node. Required when no `node:default`. |
| `--repo` | — | Git URL or `owner/repo` GitHub shorthand (expands to `git@github.com:owner/repo.git`). Cloning runs as the SSH user already configured on the node — Orbit doesn't proxy git credentials. Omitted = empty directory. |
| `--root` | `public` | Document root relative to app path. |
| `--php-version` | `8.5` | Initial PHP version (one of 8.3, 8.4, 8.5). |
| `--domain` | — | Production: triggers production setup. If DNS/TLS isn't ready yet, the app installs but the domain stays inactive — re-run `app:register --domain=…` to retry. |

Examples:

```bash
orbit app:new                                              # interactive
orbit app:new docs --repo=acme/docs                        # dev app, default node
orbit app:new docs --node=beast --repo=acme/docs --root=public
orbit app:new shop --node=prod-1 --repo=acme/shop --domain=shop.example.com
```

## `orbit app:register [name]`

Register or re-apply Orbit management for an existing app path. Idempotent — adopts an existing path, regenerates the FPM pool, re-applies proxy routes, and retries production domain activation. No clone.

```bash
orbit app:register [<name>] [--node=<name>] [--path=<path>] [--root=public]
                   [--php-version=8.5] [--domain=<host>] [--json]
```

Use this:

- After moving an existing project under Orbit management.
- To regenerate the per-app FPM pool after upgrading apps that pre-date dedicated pools.
- To retry production domain activation after DNS propagated.

## `orbit app:list`

List registered apps.

```bash
orbit app:list [--node=<name>] [--environment=development|production] [--json]
```

Reads gateway DB only — no SSH.

## `orbit app:show [app]`

Show app intent, owning node, URL, effective Agent IDE, app-owned route summary, FPM pool location, and PHP version.

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

## `orbit app:remove [app]`

Remove an app and its owned artifacts: gateway registry row, FPM pool, Caddy config, owned routes, deployment intent. For production apps, also removes the dedicated user and `/home/{slug}`.

```bash
orbit app:remove [<app>] [--force] [--json]
```

PHP-FPM is restarted so the socket is cleanly released.

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

`inherit` means "use the node default" (set with `node:agent-ide`). Switching adapter may invalidate workspaces created by the old one — `--force` confirms the resulting workspace cleanup without prompting.
