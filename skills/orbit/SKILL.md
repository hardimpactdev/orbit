---
name: orbit
description: Operate the Orbit CLI for sovereign Laravel environments — provision gateway/operator/app nodes, create dev and production apps, manage workspaces/processes/schedules, install services, deploy, and diagnose drift via `orbit doctor`. Use when the user wants to set up a Laravel environment, create or modify an app, enable a service (postgres/redis/mailpit), run a deployment, profile a request, manage VPN/firewall/DNS, change PHP versions, or repair a node. Triggers include "set up orbit", "set up this app", "register an app", "create a workspace", "install postgres", "what's running", "check orbit health", "fix drift", "deploy myapp", "switch PHP version", "create a node", "list nodes", "vpn client", or any Orbit fleet task.
allowed-tools: Bash(orbit *)
---

# Orbit CLI

Orbit is a sovereign Laravel environment. The **gateway** is the control plane and owns all durable state. **App nodes** (Ubuntu) run apps, PHP-FPM, Caddy, systemd process units, and Docker services. **Operator nodes** (macOS or Ubuntu) just run the CLI and call the gateway over WireGuard.

The CLI is the product contract. Every command works the same way regardless of which node it runs on — operator nodes and app nodes call the gateway typed API; the gateway uses `RemoteShell` (SSH) to enact changes on app nodes.

## Universal output rules

- Every command supports `--help` for signature, arguments, and options.
- Every command that returns structured data supports `--json` (`{"success":{"data":{…}}}` or `{"success":false,"error":…}`).
- Non-interactive mode (`-n`) auto-enables JSON. Always pass `--json` when parsing programmatically.
- Destructive commands take `--force` to skip confirmation.
- Nothing prints secrets to logs; use `tool:credentials` for those.

## Core concepts (load on demand)

See [`references/concepts.md`](references/concepts.md) for: node roles (gateway / operator / app), state families and `doctor`, identity slug rules, the `RemoteShell` enactment model, JSON envelope shape, and the `--node` / `--app` / `--workspace` resolution order.

## Command index

Commands are grouped by family. Each reference file lists every command in that family with its signature, options, defaults, and a couple of examples.

### Setup and ops — [`references/operation.md`](references/operation.md)

| Command | What it does |
|---|---|
| `orbit doctor` | Diagnose state-family drift; `--fix --restore` reapplies intent, `--fix --adopt` records node reality |
| `orbit update` | Update this Orbit checkout (git pull + composer install + migrate) |
| `orbit update:all` | Update local checkout and every active registered node |
| `orbit profile [target]` | Profile one HTTP request against an Orbit-managed app (DNS/connect/TLS/TTFB + Toolbar enrichment) |

### Node fleet — [`references/node.md`](references/node.md)

| Command | What it does |
|---|---|
| `orbit node:new [name]` | Create or provision a gateway / operator / app node |
| `orbit node:list` | List nodes in the gateway registry (`--doctor` for live readiness) |
| `orbit node:show [name]` | Show one node's registry record |
| `orbit node:update [name]` | Update node host, environment, or public IPv4/IPv6 metadata |
| `orbit node:remove [name]` | Remove a node from the registry |
| `orbit node:default [name]` | Choose, show, or clear the local operator node's default development app node |
| `orbit node:grant <consumer> <server>` | Grant one node access to another |
| `orbit node:revoke [c] [s]` | Revoke a node-to-node grant |
| `orbit node:agent-ide [name] [adapter]` | Set the default Agent IDE adapter for a node |

### Gateway onboarding — [`references/gateway.md`](references/gateway.md)

| Command | What it does |
|---|---|
| `orbit gateway:add [gateway_ip]` | Trust the gateway CA and configure the local operator-node connection |
| `orbit gateway:trust` | Trust the gateway root CA in the local OS trust store |

### Apps — [`references/app.md`](references/app.md)

| Command | What it does |
|---|---|
| `orbit app:new [name]` | Create or clone a new app on an app node |
| `orbit app:register [name]` | Register or re-apply Orbit management for an existing app path |
| `orbit app:list` | List registered apps |
| `orbit app:show [app]` | Show app intent, owning node, URL, agent IDE, owned routes |
| `orbit app:root [app] [root]` | Change the app document root (relative to the app path) |
| `orbit app:remove [app]` | Remove an app and its owned artifacts |
| `orbit app:prune [app]` | Remove stale workspaces (`--dry-run` to preview) |
| `orbit app:agent-ide [app] [adapter]` | Set or inherit the Agent IDE adapter for an app |

### Workspaces — [`references/workspace.md`](references/workspace.md)

| Command | What it does |
|---|---|
| `orbit workspace:new [name]` | Create a new workspace intent for an app |
| `orbit workspace:list` | List workspaces (filter by `--app` / `--node`) |
| `orbit workspace:show [name]` | Show workspace registry record |
| `orbit workspace:setup [name]` | Run setup steps to converge the workspace to ready-to-develop |
| `orbit workspace:remove [name]` | Remove a workspace and its artifacts (`--keep-files` to retain disk) |
| `orbit workspace:history [name]` | Show workspace lifecycle history |
| `orbit workspace:log [run]` | Show captured stdout/stderr for a lifecycle run |
| `orbit workspace-setup-step:add\|list\|remove` | Manage app-scoped workspace setup pipeline |
| `orbit workspace-teardown-step:add\|list\|remove` | Manage app-scoped workspace teardown pipeline |

### Processes — [`references/process.md`](references/process.md)

| Command | What it does |
|---|---|
| `orbit process:add [name] [cmd]` | Add a process definition for an app (systemd-backed on Linux) |
| `orbit process:edit [name]` | Edit a process definition |
| `orbit process:remove [name]` | Remove a process definition |
| `orbit process:list` | List configured processes |
| `orbit process:start\|stop\|restart [name]` | Control runtime units |
| `orbit process:logs [name]` | Read runtime logs (`--follow`, `--lines`) |

### Schedules — [`references/schedule.md`](references/schedule.md)

| Command | What it does |
|---|---|
| `orbit schedule:add [name]` | Add a recurring schedule (`--command` / `--script`, `--interval`) |
| `orbit schedule:list` | List configured schedules |
| `orbit schedule:show <name>` | Show one schedule |
| `orbit schedule:remove <name>` | Remove a schedule |
| `orbit schedule:run <name>` | Run a schedule once, immediately |
| `orbit schedule:logs <name>` | Show captured run output |

### Tools and services — [`references/tool.md`](references/tool.md)

Generic surface for installable tools (postgres, mysql, redis, mailpit, reverb, php, opencode-server, polyscope-server) and observational baseline tools (caddy, docker, viteplus, php-cli, gh, composer, dns).

| Command | What it does |
|---|---|
| `orbit tool:list` | List tracked tools (filter by `--app` / `--node`) |
| `orbit tool:show <tool>` | Show one tool (`--live` for live probe) |
| `orbit tool:install <tool>` | Install a managed tool (`--status=running` to also start) |
| `orbit tool:update [tool]` | Update a managed tool |
| `orbit tool:remove <tool>` | Remove a managed tool |
| `orbit tool:start\|stop\|restart\|reload <tool>` | Lifecycle control |
| `orbit tool:reconfigure <tool>` | Rotate auth or re-provision (e.g. `--password=`) |
| `orbit tool:logs <tool>` | Read managed tool logs |
| `orbit tool:credentials [tool]` | Read connection credentials |

### PHP runtime — [`references/php.md`](references/php.md)

| Command | What it does |
|---|---|
| `orbit php:list` | List PHP runtime support, installed facts, and selected intent (`--live` for live probe) |
| `orbit php:use [version]` | Select PHP for an app, workspace, node CLI default, or `--inherit` |

### Deployments — [`references/deploy.md`](references/deploy.md)

| Command | What it does |
|---|---|
| `orbit deploy:run [app]` | Run the deployment pipeline (`--detach` for fire-and-return) |
| `orbit deploy:history [app]` | List deployment runs |
| `orbit deploy:log [app] [run]` | Show stored deploy output |
| `orbit deploy:step-add [app] [cmd]` | Add a pipeline step |
| `orbit deploy:step-list [app]` | List pipeline steps |
| `orbit deploy:step-remove [app] [step]` | Remove a step |

### Proxy routes — [`references/proxy.md`](references/proxy.md)

| Command | What it does |
|---|---|
| `orbit proxy:add [domain]` | Create or update a custom proxy route or redirect |
| `orbit proxy:list` | List proxy routes (`--filter=app\|workspace\|gateway\|tool\|custom\|redirect`) |
| `orbit proxy:remove [domain]` | Remove a custom proxy route |

### Firewall — [`references/firewall.md`](references/firewall.md)

| Command | What it does |
|---|---|
| `orbit firewall:allow [name]` | Create or update an allow rule |
| `orbit firewall:deny [name]` | Create or update a deny rule |
| `orbit firewall:list` | List rule intent |
| `orbit firewall:remove [name]` | Remove a rule |

### Local DNS — [`references/dns.md`](references/dns.md)

| Command | What it does |
|---|---|
| `orbit dns:list` | List caller-local DNS resolver overrides |
| `orbit dns:resolve-tld [tld] [target]` | Configure or remove a development TLD resolver override |

### Activity log — [`references/activity.md`](references/activity.md)

| Command | What it does |
|---|---|
| `orbit activity:list` | List gateway activity history (filter by `--app` / `--node` / `--effect` / `--correlation`) |
| `orbit activity:show [id]` | Show one activity entry |

### Agent IDE — [`references/agent-ide.md`](references/agent-ide.md)

| Command | What it does |
|---|---|
| `orbit agent-ide:message [message]` | Send a message to an active Agent IDE session for an app/workspace |
| `orbit node:agent-ide` / `orbit app:agent-ide` | Set the adapter (covered in node.md / app.md) |

### VPN — [`references/vpn.md`](references/vpn.md)

| Command | What it does |
|---|---|
| `orbit vpn-client:list\|new\|enable\|disable\|remove` | Manage non-node gateway VPN clients (TOTP-gated) |
| `orbit vpn-web-ui:change-password` | Change the gateway VPN web UI password |

## Common workflows

**Bootstrap an operator node onto an existing gateway**

```bash
# On the gateway:
orbit node:new my-mac --role=operator
# Install the returned WireGuard config on the Mac, then on the Mac:
orbit gateway:add 10.6.0.1
```

**Bootstrap the first gateway from a fresh Mac**

```bash
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --operator-name=my-mac
```

**Create a development app + database**

```bash
orbit node:default beast              # set local default app node (one-time)
orbit app:new myapp --repo=acme/myapp # served at myapp.<beast-tld>
orbit tool:install postgres
orbit tool:credentials postgres       # connection details
```

**Deploy a production app**

```bash
orbit app:new myapp --node=prod-1 --repo=acme/myapp --domain=myapp.com
orbit deploy:step-add myapp 'composer install --no-dev' --title='install deps'
orbit deploy:step-add myapp 'php artisan migrate --force' --title='migrate'
orbit deploy:run myapp
orbit deploy:history myapp
```

**Diagnose and repair drift**

```bash
orbit doctor --node=beast                          # report drift across all families
orbit doctor --node=beast --family=proxy --family=process
orbit doctor --node=beast --fix --restore          # reapply gateway intent
orbit doctor --node=beast --fix --adopt --family=app  # adopt node reality (DR / fleet adoption)
```

**Move an app to a different PHP version**

```bash
orbit php:use 8.4 --app=myapp        # changes app FPM pool live, ~1-2s blip
orbit php:use 8.5 --cli --node=beast # default CLI PHP for that node
```

**Switch a workspace's Agent IDE**

```bash
orbit node:agent-ide beast opencode  # node default
orbit app:agent-ide myapp inherit    # use node default
orbit app:agent-ide myapp polyscope  # per-app override
```

## Conventions when calling Orbit

- Resolve target order for `--node`-aware commands: explicit `--node` → app/workspace ownership → local `node:default` → interactive prompt or non-interactive failure.
- Slugs are `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`. App ≤40, node ≤63, workspace ≤63, process ≤64 chars.
- Development apps are served at `{name}.{node-tld}`; workspaces at `{workspace}.{app}.{tld}`. Production apps at the configured `--domain`.
- App, workspace, process, schedule, proxy, firewall, and tool state are gateway-owned **state families**. Use `doctor --family=<key>` to scope drift checks; family keys are `node`, `app`, `workspace`, `process`, `proxy`, `schedule`, `tool`, `firewall_rule`.
- Don't SSH to nodes manually to "fix" Orbit state — use `doctor --fix` so intent and reality stay aligned.

## When to read which reference

- Setting up a node, configuring grants, choosing a default → [`node.md`](references/node.md), [`gateway.md`](references/gateway.md)
- Creating, removing, registering, or pruning apps → [`app.md`](references/app.md)
- Workspace lifecycle, setup/teardown step pipelines → [`workspace.md`](references/workspace.md)
- Long-running app processes (queues, websockets, vite) → [`process.md`](references/process.md)
- Recurring jobs and Laravel scheduler integration → [`schedule.md`](references/schedule.md)
- Databases, caches, mail, agent-ide servers → [`tool.md`](references/tool.md)
- PHP version selection at app/workspace/CLI scope → [`php.md`](references/php.md)
- Production deployments and pipelines → [`deploy.md`](references/deploy.md)
- Custom domains, redirects, ingress drift → [`proxy.md`](references/proxy.md)
- UFW intent, opening or closing ports → [`firewall.md`](references/firewall.md)
- Local TLD resolution on an operator node → [`dns.md`](references/dns.md)
- Audit trail / who did what → [`activity.md`](references/activity.md)
- Sending messages into a workspace's coding agent → [`agent-ide.md`](references/agent-ide.md)
- WireGuard client provisioning, web UI password → [`vpn.md`](references/vpn.md)
- Node roles, doctor model, slugs, JSON shape → [`concepts.md`](references/concepts.md)
