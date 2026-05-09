# Tool Commands

Generic surface for installable and observational node tools. The catalog is fixed and lives in [`docs/commands/3_tool/catalog/`](../../../docs/commands/3_tool/catalog/).

## Catalog

**Required baseline tools** (provisioned by node bootstrap; Orbit observes and keeps converged):

- `caddy`, `supervisor`, `docker`, `viteplus`, `php-cli`, `gh`, `composer`, `dns`

**Installable tools** (provisioned by `tool:install`, removed by `tool:remove`):

- `php` — additional PHP runtime versions
- `postgres`, `mysql`, `redis` — databases and caches (Docker)
- `mailpit` — local SMTP capture (Docker)
- `reverb` — Laravel WebSocket server
- `polyscope-server` — Polyscope headless coding-agent server
- `opencode-server` — OpenCode HTTP server for programmatic LLM interaction

Service hostnames on development nodes use the node TLD: `mailpit.<tld>`, `orbit.<tld>:5432` for postgres, etc. HTTP/WS tools surface as proxy routes; TCP tools are WireGuard-only host/port records.

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
                   [--status=installed|running] [--expected-version=<v>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `--status` | `installed` | `running` also starts the service after install. |
| `--expected-version` | — | Version constraint (catalog-dependent). |

Examples:

```bash
orbit tool:install postgres --node=beast --status=running
orbit tool:install opencode-server --node=beast --status=running
orbit tool:install php --expected-version=8.4 --node=beast
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

## `orbit tool:start | stop | restart | reload <tool>`

Lifecycle control. `reload` is for tools with reload semantics (e.g. Caddy `reload` vs `restart`).

```bash
orbit tool:start   <tool> [--app=<name>] [--node=<name>] [--json]
orbit tool:stop    <tool> [--app=<name>] [--node=<name>] [--json]
orbit tool:restart <tool> [--app=<name>] [--node=<name>] [--json]
orbit tool:reload  [<tool>] [--app=<name>] [--node=<name>] [--json]
```

## `orbit tool:reconfigure <tool>`

Re-provision or rotate tool-owned configuration. Tool-specific options.

```bash
orbit tool:reconfigure <tool> [--app=<name>] [--node=<name>]
                       [--password=<value>] [--json]
```

Examples:

- `orbit tool:reconfigure opencode-server --password='…'` — rotate basic-auth password.
- `orbit tool:reconfigure polyscope-server` — rotate Polyscope auth and restart.

## `orbit tool:logs <tool>`

```bash
orbit tool:logs <tool> [--app=<name>] [--node=<name>] [--lines=100] [--follow] [--json]
```

## `orbit tool:credentials [tool]`

Read managed connection credentials. Default service username is `orbit` when the protocol has a username concept. Generated passwords are created by Orbit during install/reconfigure.

```bash
orbit tool:credentials [<tool>] [--app=<name>] [--node=<name>] [--json]
```

Without `<tool>`, returns credentials for every credential-bearing tool on the resolved target.

Examples:

```bash
orbit tool:credentials postgres --node=beast
orbit tool:credentials opencode-server --node=beast --json
```
