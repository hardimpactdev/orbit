# Proxy Commands

Manage **custom** proxy routes (Caddy site intent)  -  for routes that aren't owned by an app, workspace, or tool. App and workspace ingress are managed automatically by `app:*` and `workspace:*`; `proxy:*` adds redirects and side-channel domains. Spec: [`apps/docs/content/domains/8_proxy/`](../../../../apps/docs/content/domains/8_proxy/).

The `proxy` state family also covers app, workspace, gateway, and tool-owned
routes. `proxy:list` shows all of them. `proxy:add` mutates only custom routes.
`proxy:remove` normally does the same, but `--force` also permits two proven
orphan repairs: a direct-instance route whose Instance is gone, or a
structurally complete tool-owned route whose installed tool is gone. Other
route-kind drift is repaired through `doctor --fix --family=proxy --restore`.

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
| `--upstream` | HTTP or HTTPS upstream URL  -  proxied. |
| `--redirect` | HTTP or HTTPS redirect target  -  issues a redirect. |
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
orbit proxy:list [--node=<name>] [--filter=all|instance|workspace|gateway|analytics|websocket|s3|tool|custom|redirect] [--json]
```

`--filter=custom` is the most useful one for "what did I add via `proxy:add`".

## `orbit proxy:remove [domain]`

Remove a custom proxy route or one of the two proven orphan-owner forms.

```bash
orbit proxy:remove [<domain>] [--force] [--json]
```

Without `--force`, removal requires interactive destructive confirmation.
With destructive consent, Orbit can also remove:

- a direct-instance route (`app`, `app-analytics`, or `app-websocket`) whose
  owning Instance is genuinely gone; or
- a structurally complete tool-owned route when no matching installed tool
  remains on its serving node.

A route whose owner still exists is denied. A conflicting tuple whose Instance
still exists remains repairable drift and is not an orphan. Missing or invalid
workspace, gateway, S3, or other ownership is ambiguous and fails closed. To
remove a living app, workspace, binding, or tool route, use that owner's
lifecycle command.
