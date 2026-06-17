---
name: orbit
description: Operate the Orbit CLI for sovereign Laravel environments  -  bootstrap gateways and clients, provision workload-role nodes, create development and production apps, manage workspaces/processes/schedules, configure database connections, publish S3/Cloudflare/metrics surfaces, deploy, profile, and diagnose drift via `orbit doctor`. Use when the user wants to set up Orbit, create or modify an app, create a workspace, manage database/S3/metrics/DNS/VPN/firewall, run a deployment, profile a request, change PHP runtime selection, repair a node, or inspect fleet state. Triggers include "set up orbit", "set up this app", "register an app", "create a workspace", "database connection", "publish S3", "metrics", "Cloudflare DNS", "what's running", "check orbit health", "fix drift", "deploy myapp", "switch PHP version", "create a node", "list nodes", "vpn client", or any Orbit fleet task.
allowed-tools: Bash(orbit *)
---

# Orbit CLI

Orbit is a sovereign Laravel environment. The **gateway** is the control plane
and owns all durable state. Every other machine is a gateway client when it runs
`orbit`: it presents a WireGuard identity, calls the gateway typed API, and lets
the gateway decide authorization from node grants.

Nodes carry role assignments such as `app-dev`, `app-prod`, `database`,
`agent`, `ingress`, `websocket`, `s3`, and `metrics`. Workload nodes run
role-specific artifacts: `orbit-caddy`, FrankenPHP app/workspace containers,
systemd host command units, Docker-backed process units, Laravel Reverb,
SeaweedFS, Prometheus/Grafana metrics services, node-exporter, and
similar backing services. PHP-FPM and Supervisor are not app/workspace runtime
fallbacks.

The CLI is the public product contract. Gateway Artisan is maintenance/internal
automation only. When work must happen on a node, the gateway uses SSH
execution lanes (`RemoteShell`, local executor, or gateway runtime executor)
over the Orbit/WireGuard network.

## Universal output rules

- Every command supports `--help` for signature, arguments, and options.
- Every command that returns structured data supports `--json` (`{"success":{"data":{...}}}` or `{"success":false,"error":...}`).
- Non-interactive mode (`-n`) auto-enables JSON. Always pass `--json` when parsing programmatically.
- Destructive commands take `--force` to skip confirmation.
- Nothing prints secrets to logs; use `tool:credentials` for those.

## Core concepts (load on demand)

See [`references/concepts.md`](references/concepts.md) for: gateway/client
terminology, node roles, state families and `doctor`, identity slug rules,
execution lanes, JSON envelope shape, and the `--node` / `--app` /
`--workspace` resolution order.

## Command index

Commands are grouped by family. Each reference file lists every command in that family with its signature, options, defaults, and a couple of examples.

### Setup and ops  -  [`references/operation.md`](references/operation.md)

| Command | What it does |
|---|---|
| `orbit doctor` | Diagnose state-family drift; `--fix --restore` reapplies intent, `--fix --adopt` records node reality |
| `orbit update` | Update this Orbit checkout (git pull + composer install + migrate) |
| `orbit update:all` | Update local checkout and every active registered node |
| `orbit profile [target]` | Profile one HTTP request against an Orbit-managed app (DNS/connect/TLS/TTFB + Toolbar enrichment) |

### Node fleet  -  [`references/node.md`](references/node.md)

| Command | What it does |
|---|---|
| `orbit node:new [name]` | Create a client identity or provision a workload-role node |
| `orbit node:list` | List nodes in the gateway registry |
| `orbit node:show [name]` | Show one node's registry record |
| `orbit node:update [name]` | Update node host, TLD, gateway endpoint, or public IP metadata |
| `orbit node:remove [name]` | Remove a node from the registry |
| `orbit node:default [name]` | Choose, show, or clear the local default development node |
| `orbit node:grant <consumer> <server>` | Grant one node access to another |
| `orbit node:revoke [c] [s]` | Revoke a node-to-node grant |
| `orbit node:permissions` | Manage explicit node access permissions |
| `orbit node role:add\|list\|remove` | Manage assignable workload roles on an existing node |
| `orbit node:agent-ide [name] [adapter]` | Set the default Agent IDE adapter for a node |

### Gateway onboarding  -  [`references/gateway.md`](references/gateway.md)

| Command | What it does |
|---|---|
| `orbit gateway:add [gateway_ip]` | Trust the gateway CA and configure the local client connection |
| `orbit gateway:trust` | Trust the gateway root CA in the local OS trust store |
| `orbit gateway:list\|use\|status` | Inspect or switch local gateway entries |

### Apps  -  [`references/app.md`](references/app.md)

| Command | What it does |
|---|---|
| `orbit app:new [name]` | Create or clone a new app on an app-role node |
| `orbit app:register [name]` | Register or re-apply Orbit management for an existing app path |
| `orbit app:list` | List registered apps |
| `orbit app:show [app]` | Show app intent, owning node, URL, agent IDE, owned routes |
| `orbit app:root [app] [root]` | Change the app document root (relative to the app path) |
| `orbit app:remove [app]` | Remove an app and its owned artifacts |
| `orbit app:prune [app]` | Remove stale workspaces (`--dry-run` to preview) |
| `orbit app:agent-ide [app] [adapter]` | Set or inherit the Agent IDE adapter for an app |
| `orbit app:worker [app]` | Inspect or change FrankenPHP worker mode |
| `orbit app:websocket enable\|disable\|credentials` | Manage app WebSocket binding and credentials |

### Workspaces  -  [`references/workspace.md`](references/workspace.md)

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

### Processes  -  [`references/process.md`](references/process.md)

| Command | What it does |
|---|---|
| `orbit process:add [name] [cmd]` | Add a process definition for an app (systemd-backed on Linux) |
| `orbit process:edit [name]` | Edit a process definition |
| `orbit process:remove [name]` | Remove a process definition |
| `orbit process:list` | List configured processes |
| `orbit process:start\|stop\|restart [name]` | Control runtime units |
| `orbit process:logs [name]` | Read runtime logs (`--follow`, `--lines`) |

### Schedules  -  [`references/schedule.md`](references/schedule.md)

| Command | What it does |
|---|---|
| `orbit schedule:add [name]` | Add a recurring schedule (`--command` / `--script`, `--interval`) |
| `orbit schedule:list` | List configured schedules |
| `orbit schedule:show <name>` | Show one schedule |
| `orbit schedule:remove <name>` | Remove a schedule |
| `orbit schedule:run <name>` | Run a schedule once, immediately |
| `orbit schedule:logs <name>` | Show captured run output |

### Tools and services  -  [`references/tool.md`](references/tool.md)

Generic surface for node capabilities. Tools do not own start/stop/restart/log
lifecycle directly; process rows own lifecycle for runnable services.

| Command | What it does |
|---|---|
| `orbit tool:list` | List tracked tools (filter by `--app` / `--node`) |
| `orbit tool:show <tool>` | Show one tool (`--live` for live probe) |
| `orbit tool:install <tool>` | Install a managed tool (`--status=running` to also start) |
| `orbit tool:update [tool]` | Update a managed tool |
| `orbit tool:remove <tool>` | Remove a managed tool |
| `orbit tool:reconfigure <tool>` | Rotate auth or re-provision (e.g. `--password=`) |
| `orbit tool:credentials [tool]` | Read connection credentials |

### Databases  -  [`references/database.md`](references/database.md)

| Command | What it does |
|---|---|
| `orbit database:list\|show` | Read reusable database connection intent |
| `orbit database:add\|update\|remove` | Manage reusable connection records |
| `orbit database:attach\|detach` | Map a connection to an app/workspace `.env` prefix |
| `orbit database:query` | Run audited SQL through a registered connection |
| `orbit database:tables\|schema\|describe` | Inspect database schema metadata |

### PHP runtime  -  [`references/php.md`](references/php.md)

| Command | What it does |
|---|---|
| `orbit php:list` | List PHP runtime support, installed facts, and selected intent (`--live` for live probe) |
| `orbit php:use [version]` | Select PHP for an app, workspace, node CLI default, or `--inherit` |

### Deployments  -  [`references/deploy.md`](references/deploy.md)

| Command | What it does |
|---|---|
| `orbit deploy:run [app]` | Run the deployment pipeline (`--detach` for fire-and-return) |
| `orbit deploy:history [app]` | List deployment runs |
| `orbit deploy:log [app] [run]` | Show stored deploy output |
| `orbit deploy:step-add [app] [cmd]` | Add a pipeline step |
| `orbit deploy:step-list [app]` | List pipeline steps |
| `orbit deploy:step-remove [app] [step]` | Remove a step |

### S3 object storage  -  [`references/s3.md`](references/s3.md)

| Command | What it does |
|---|---|
| `orbit s3:credentials` | Show SeaweedFS service credentials and endpoint metadata |
| `orbit s3:publish [host]` | Publish a public HTTPS hostname for the fleet S3 service |
| `orbit s3:unpublish [host]` | Remove a public HTTPS hostname from the fleet S3 service |

### Metrics  -  [`references/metrics.md`](references/metrics.md)

| Command | What it does |
|---|---|
| `orbit metrics:enable --node=<node>` | Assign the optional metrics role and record Prometheus/Grafana/node-exporter intent |
| `orbit metrics:disable --node=<node> --force` | Remove the metrics role and owned metrics intent |
| `orbit metrics:status` | Read metrics role and process intent from gateway configuration |
| `orbit metrics:credentials` | Show or rotate Grafana admin credentials |

### Cloudflare  -  [`references/cf.md`](references/cf.md)

| Command | What it does |
|---|---|
| `orbit cf-zone:list` | List Cloudflare zones visible to the gateway token |
| `orbit cf-dns:list\|add\|remove` | Manage Cloudflare A/AAAA DNS records |
| `orbit cf-cache:flush` | Flush Cloudflare cache for a zone |
| `orbit cf-cache-rule:add\|remove` | Manage Orbit's standard app cache rule |
| `orbit cf-ssl:enable\|disable` | Manage Cloudflare SSL mode for a zone |

### Proxy routes  -  [`references/proxy.md`](references/proxy.md)

| Command | What it does |
|---|---|
| `orbit proxy:add [domain]` | Create or update a custom proxy route or redirect |
| `orbit proxy:list` | List proxy routes (`--filter=app\|workspace\|gateway\|tool\|custom\|redirect`) |
| `orbit proxy:remove [domain]` | Remove a custom proxy route |

### Firewall  -  [`references/firewall.md`](references/firewall.md)

| Command | What it does |
|---|---|
| `orbit firewall:allow [name]` | Create or update an allow rule |
| `orbit firewall:deny [name]` | Create or update a deny rule |
| `orbit firewall:list` | List rule intent |
| `orbit firewall:remove [name]` | Remove a rule |

### Local DNS  -  [`references/dns.md`](references/dns.md)

| Command | What it does |
|---|---|
| `orbit dns:list` | List caller-local DNS resolver overrides |
| `orbit dns:resolve-tld [tld] [target]` | Configure or remove a development TLD resolver override |

### Activity log  -  [`references/activity.md`](references/activity.md)

| Command | What it does |
|---|---|
| `orbit activity:list` | List gateway activity history (filter by `--app` / `--node` / `--effect` / `--correlation`) |
| `orbit activity:show [id]` | Show one activity entry |

### Agent IDE  -  [`references/agent-ide.md`](references/agent-ide.md)

| Command | What it does |
|---|---|
| `orbit agent-ide:message [message]` | Send a message to an active Agent IDE session for an app/workspace |
| `orbit node:agent-ide` / `orbit app:agent-ide` | Set the adapter (covered in node.md / app.md) |

### VPN  -  [`references/vpn.md`](references/vpn.md)

| Command | What it does |
|---|---|
| `orbit vpn-client:list\|new\|enable\|disable\|remove` | Manage non-node gateway VPN clients (TOTP-gated) |
| `orbit vpn-web-ui:change-password` | Change the gateway VPN web UI password |

## Common workflows

**Bootstrap a client onto an existing gateway**

```bash
# On the gateway:
orbit node:new my-mac --operator
# Install the returned WireGuard config on the Mac, then on the Mac:
orbit gateway:add 10.6.0.1
```

**Bootstrap the first gateway from a fresh Mac**

```bash
orbit node:new gateway-1 --template=gateway --host=203.0.113.2 --operator-name=my-mac
```

**Create a development app + database**

```bash
orbit node:default beast              # set local default development node (one-time)
orbit app:new myapp --repo=acme/myapp # served at myapp.<beast-tld>
orbit database:add myapp --driver=pgsql --host=10.6.0.50 --database=myapp --username=orbit
orbit database:attach myapp --app=myapp --env-prefix=DB
orbit doctor --app=myapp --family=database_connection --fix --restore
```

**Deploy a production app**

```bash
orbit app:new myapp --node=prod-1 --repo=acme/myapp --domain=myapp.com
orbit deploy:step-add myapp 'composer install --no-dev' --title='install deps'
orbit deploy:step-add myapp 'php artisan migrate --force' --title='migrate'
orbit deploy:run myapp
orbit deploy:history myapp
```

**Publish the fleet S3 endpoint**

```bash
orbit node:new storage-1 --template=s3 --host=10.0.0.20 --s3-data-path=/srv/orbit/s3/data
orbit s3:credentials --node=storage-1
orbit s3:publish s3.example.com --node=storage-1
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
orbit php:use 8.4 --app=myapp        # recreates app FrankenPHP runtime artifact
orbit php:use 8.5 --cli --node=beast # default CLI PHP for that node
```

**Switch a workspace's Agent IDE**

```bash
orbit node:agent-ide beast opencode  # node default
orbit app:agent-ide myapp inherit    # use node default
orbit app:agent-ide myapp polyscope  # per-app override
```

## Conventions when calling Orbit

- Resolve target order for `--node`-aware commands: explicit `--node` -> app/workspace ownership -> local `node:default` -> interactive prompt or non-interactive failure.
- Slugs are `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`. App <=40, node <=63, workspace <=63, process <=64 chars.
- Development apps are served at `{name}.{node-tld}`; workspaces at `{workspace}.{app}.{tld}`. Production apps at the configured `--domain`.
- App, workspace, process, schedule, proxy, firewall, tool, and database
  connection state are gateway-owned **state families**. Use
  `doctor --family=<key>` to scope drift checks; family keys are `node`, `app`,
  `workspace`, `process`, `proxy`, `schedule`, `tool`, `firewall_rule`, and
  `database_connection`.
- Don't SSH to nodes manually to "fix" Orbit state  -  use `doctor --fix` so intent and reality stay aligned.

## When to read which reference

- Setting up a node, configuring grants, choosing a default -> [`node.md`](references/node.md), [`gateway.md`](references/gateway.md)
- Creating, removing, registering, or pruning apps -> [`app.md`](references/app.md)
- Workspace lifecycle, setup/teardown step pipelines -> [`workspace.md`](references/workspace.md)
- Long-running app processes (queues, websockets, vite) -> [`process.md`](references/process.md)
- Recurring jobs and Laravel scheduler integration -> [`schedule.md`](references/schedule.md)
- Node capabilities, mail, agent runtimes, service tools -> [`tool.md`](references/tool.md)
- Database connection intent, target `.env` convergence, SQL/schema inspection -> [`database.md`](references/database.md)
- PHP version selection at app/workspace/CLI scope -> [`php.md`](references/php.md)
- Production deployments and pipelines -> [`deploy.md`](references/deploy.md)
- S3 service credentials and public S3 hosts -> [`s3.md`](references/s3.md)
- Cloudflare DNS/cache/SSL commands -> [`cf.md`](references/cf.md)
- Custom domains, redirects, ingress drift -> [`proxy.md`](references/proxy.md)
- UFW intent, opening or closing ports -> [`firewall.md`](references/firewall.md)
- Local TLD resolution on a caller machine -> [`dns.md`](references/dns.md)
- Audit trail / who did what -> [`activity.md`](references/activity.md)
- Sending messages into a workspace's coding agent -> [`agent-ide.md`](references/agent-ide.md)
- WireGuard client provisioning, web UI password -> [`vpn.md`](references/vpn.md)
- Node roles, doctor model, slugs, JSON shape -> [`concepts.md`](references/concepts.md)
