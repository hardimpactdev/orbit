# Proxy Commands

Manage **custom** proxy routes (Caddy site intent) — for routes that aren't owned by an app, workspace, or tool. App and workspace ingress are managed automatically by `app:*` and `workspace:*`; `proxy:*` adds redirects and side-channel domains. Spec: [`docs/commands/8_proxy/`](../../../docs/commands/8_proxy/).

The `proxy` state family also covers app, workspace, gateway, and tool-owned routes — `proxy:list` shows all of them, but you can only mutate **custom** ones with `proxy:add` / `proxy:remove`. Drift in the other route kinds is repaired through `doctor --fix --family=proxy --restore`.

## `orbit proxy:add [domain]`

Create or update a custom proxy route or redirect.

```bash
orbit proxy:add [<domain>] [--node=<name>]
                [--upstream=<url> | --redirect=<url>] [--code=<status>]
                [--force] [--json]
```

| Option | Notes |
|---|---|
| `domain` | Hostname to serve. |
| `--node` | Serving node. |
| `--upstream` | HTTP or HTTPS upstream URL — proxied. |
| `--redirect` | HTTP or HTTPS redirect target — issues a redirect. |
| `--code` | Redirect status code (e.g. `301`, `302`, `308`). Only valid with `--redirect`. |
| `--force` | Replace an existing custom route with different intent. |

Exactly one of `--upstream` or `--redirect` must be set.

Examples:

```bash
orbit proxy:add api.acme.com --node=prod-1 --upstream=http://10.6.0.20:8000
orbit proxy:add www.acme.com --node=prod-1 --redirect=https://acme.com --code=308
```

## `orbit proxy:list`

List proxy routes tracked by gateway intent.

```bash
orbit proxy:list [--node=<name>] [--filter=all|app|workspace|gateway|tool|custom|redirect] [--json]
```

`--filter=custom` is the most useful one for "what did I add via `proxy:add`".

## `orbit proxy:remove [domain]`

Remove a custom proxy route.

```bash
orbit proxy:remove [<domain>] [--force] [--json]
```

Only removes custom-kind routes. To remove an app's route, remove the app; to remove a workspace's, remove the workspace.
