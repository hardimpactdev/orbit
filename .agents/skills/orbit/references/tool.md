# Tool Commands

Generic surface for installable, role-baseline, and observational node
capabilities. A tool is not itself the lifecycle-managed runnable unit;
processes own lifecycle for runnable services. Explicit lifecycle-capable tools
can declare `tool:start`, `tool:stop`, and `tool:restart`; the first supported
tool is macOS-only `orbstack`. `tool:logs` and `tool:reload` are not part of the
tool command surface. The catalog is fixed and lives in
[`apps/docs/content/domains/3_tool/catalog/`](../../../apps/docs/content/domains/3_tool/catalog/).

## Catalog

**Required / role-baseline tools** (provisioned by node bootstrap or role
baseline; Orbit observes and keeps converged):

- `caddy`, `docker`, `viteplus`, `php-cli`, `gh`, `composer`,
  `laravel-installer`, `dns`, `php`, `seaweedfs`, `node-exporter`

For the `metrics` role, `tool` owns the Docker substrate on the metrics node
and the node-exporter host binary on metrics/workload nodes. The
`node-exporter` systemd lifecycle, logs, and runtime drift stay in `process`.

**Installable tools** (provisioned by `tool:install`, removed by `tool:remove`):

- `mailpit`  -  local SMTP capture (Docker)
- `reverb`  -  compatibility WebSocket service; prefer the `websocket` role for fleet realtime
- `claude-code`  -  Anthropic Claude Code CLI runtime; no required node role; installs for the node default user and optional extra OS users via repeatable `--user`
- `polyscope-server`  -  Polyscope headless coding-agent server
- `opencode-server`  -  OpenCode HTTP server for programmatic LLM interaction
- `openclaw`, `hermes`  -  first-party autonomous agent runtimes on `agent` nodes
- `orbstack`  -  macOS-only Docker-compatible provider; supports explicit start/stop/restart through `orbctl`

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
                   [--status=installed|running] [--tool-version=<v>]
                   [--user=<name>] [--with-process] [--no-process] [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `--status` | `installed` | Desired capability state after install (`installed` or `running`). |
| `--tool-version` |  -  | Version or installer channel to install (catalog-dependent); Claude Code accepts `latest`, `stable`, or a specific version. |
| `--user` |  -  | `claude-code` only. Repeatable additional existing Linux OS user; fails for other tools. Additive install targeting, not account creation or a node-role gate. |
| `--with-process` | on for service tools | Also configure the related service process. Default for tools that declare one. |
| `--no-process` | off | Install the capability only; do not configure the related service process. |
| `--stream-json` | off | JSONL progress stream for agents; mutually exclusive with `--json`. |

Examples:

```bash
orbit tool:install opencode-server --node=beast --status=running
orbit tool:install php --tool-version=8.4 --node=beast
orbit tool:install claude-code --node=app-1 --user=agent
```

## `orbit tool:update [tool]`

Update a managed tool to the catalog target version.

```bash
orbit tool:update [<tool>] [--app=<name>] [--node=<name>] [--expected-version=<v>] [--json|--stream-json]
```

## `orbit tool:start|stop|restart <tool>`

Control lifecycle-capable tools. Initial support is macOS-only `orbstack`.
Unsupported tools and unsupported node platforms fail before host commands run.

```bash
orbit tool:start orbstack --node=<mac-node> [--json|--stream-json]
orbit tool:stop orbstack --node=<mac-node> [--json|--stream-json]
orbit tool:restart orbstack --node=<mac-node> [--json|--stream-json]
```

## `orbit tool:remove <tool>`

```bash
orbit tool:remove <tool> [--app=<name>] [--node=<name>] [--force] [--json]
```

`tool:remove` does not support `--stream-json`; it uses the blocking remove path.

## `orbit tool:reconfigure <tool>`

Re-provision or rotate tool-owned configuration. Tool-specific options.

```bash
orbit tool:reconfigure <tool> [--app=<name>] [--node=<name>]
                       [--password=<value>] [--json|--stream-json]
```

`tool:update` and `tool:reconfigure` support `--stream-json` for JSONL progress;
use `--json` when only the final result envelope is needed.

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
