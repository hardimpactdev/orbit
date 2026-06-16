# Tool Commands

Generic surface for installable, role-baseline, and observational node
capabilities. A tool is not itself the lifecycle-managed runnable unit;
processes own lifecycle. The catalog is fixed and lives in
[`apps/docs/content/domains/3_tool/catalog/`](../../../apps/docs/content/domains/3_tool/catalog/).

## Catalog

**Required / role-baseline tools** (provisioned by node bootstrap or role
baseline; Orbit observes and keeps converged):

- `caddy`, `docker`, `viteplus`, `php-cli`, `gh`, `composer`,
  `laravel-installer`, `dns`, `php`, `seaweedfs`

**Installable tools** (provisioned by `tool:install`, removed by `tool:remove`):

- `mailpit`  -  local SMTP capture (Docker)
- `reverb`  -  compatibility WebSocket service; prefer the `websocket` role for fleet realtime
- `polyscope-server`  -  Polyscope headless coding-agent server
- `opencode-server`  -  OpenCode HTTP server for programmatic LLM interaction
- `openclaw`, `hermes`  -  first-party autonomous agent runtimes on `agent` nodes

HTTP/WS tool endpoints surface as tool-owned proxy routes. TCP service
endpoints are WireGuard-only host/port records. Database connection inventory,
env convergence, schema inspection, and audited SQL execution live under
`database:*`, not `postgres:*` or `mysql:*` command families.

For `php:*` workflow (selecting a runtime for an app/workspace/CLI), see [`php.md`](php.md). The `php` catalog tool installs the runtime; `php:use` selects it.

## `orbit tool:list`

List tracked tool state.

```bash
orbit tool:list [--app=<name>] [--node=<name>] [--json]
```

## `orbit tool:show <tool>`

Show one tool tracked by the gateway registry.

```bash
orbit tool:show <tool> [--app=<name>] [--node=<name>] [--live] [--json]
```

`--live` requests live gateway-side inspection of the actual node state (otherwise reads gateway intent only).

## `orbit tool:install <tool>`

Provision a managed tool on a node.

```bash
orbit tool:install <tool> [--app=<name>] [--node=<name>]
                   [--status=installed|running] [--tool-version=<v>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `--status` | `installed` | Desired capability state. Tool definitions that declare a related process configure that process idempotently unless `--no-process` is used. |
| `--tool-version` |  -  | Version or version family to install (catalog-dependent). |

Examples:

```bash
orbit tool:install opencode-server --node=beast --status=running
orbit tool:install php --tool-version=8.4 --node=beast
```

## `orbit tool:update [tool]`

Update a managed tool to the catalog target version.

```bash
orbit tool:update [<tool>] [--app=<name>] [--node=<name>] [--expected-version=<v>] [--json]
```

## `orbit tool:remove <tool>`

```bash
orbit tool:remove <tool> [--app=<name>] [--node=<name>] [--force] [--json]
```

## `orbit tool:reconfigure <tool>`

Re-provision or rotate tool-owned configuration. Tool-specific options.

```bash
orbit tool:reconfigure <tool> [--app=<name>] [--node=<name>]
                       [--password=<value>] [--json]
```

Examples:

- `orbit tool:reconfigure opencode-server --password='...'`  -  rotate basic-auth password.
- `orbit tool:reconfigure polyscope-server`  -  rotate Polyscope auth and restart.

## `orbit tool:credentials [tool]`

Read managed connection credentials. Default service username is `orbit` when the protocol has a username concept. Generated passwords are created by Orbit during install/reconfigure.

```bash
orbit tool:credentials [<tool>] [--app=<name>] [--node=<name>] [--json]
```

Without `<tool>`, returns credentials for every credential-bearing tool on the resolved target.

Examples:

```bash
orbit tool:credentials opencode-server --node=beast --json
orbit tool:credentials opencode-server --node=beast --json
```
