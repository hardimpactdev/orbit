# Orbit Concepts

Authoritative source: [`apps/docs/content/architecture.md`](../../../../apps/docs/content/architecture.md), [`apps/docs/content/concepts.md`](../../../../apps/docs/content/concepts.md), [`apps/docs/content/tech-stack.md`](../../../../apps/docs/content/tech-stack.md). This file is a quick reference for the parts that matter when calling the CLI.

## Node roles

| Role | Platform | What it owns |
|---|---|---|
| `gateway` | Ubuntu | Canonical SQLite DB, typed HTTPS API, Orbit root CA, node access policy, doctor convergence, and gateway-owned operations Reverb service |
| `vpn` | Ubuntu | Gateway-coupled WireGuard server runtime, public endpoint settings, peer defaults, and VPN-facing DNS runtime |
| `router` | Ubuntu | Gateway-coupled private `.orbit` service names, route artifacts, backend pools, and private routing |
| `app-dev` | Ubuntu/macOS | Development app/workspace files, local TLD routes, FrankenPHP runtimes, and dev host toolchain |
| `app-prod` | Ubuntu | Production app files, private backend routes, FrankenPHP runtimes, and deploy/runtime policy |
| `database` | Ubuntu/macOS | Private database-role node capability and managed database process dependencies |
| `agent` | Ubuntu | Exclusive autonomous agent workload role and internal agent tool routes |
| `ingress` | Ubuntu | Public production HTTP/HTTPS edge and forwarding to `router` over WireGuard |
| `websocket` | Ubuntu | Private Laravel Reverb backend, reached through router-owned routes |
| `s3` | Ubuntu | Private SeaweedFS backend, reached through router-owned S3 routes |
| `metrics` | Ubuntu | Private Prometheus/Grafana host-resource metrics backend, reached through `metrics.orbit` |

The gateway-owned operations Reverb service is not the app-facing `websocket`
role. It is a single gateway Swarm service for durable operation progress, uses
its own operations app config path, and does not require Redis or a
database-role node in v1.

An **operator** is a node identity with the operator permission preset and
grants. It is not a stored role. Any gateway-known node can be a client when it
runs the CLI.

## Orbit Agent lane

Orbit Agent intent derives from an active workload role or the explicit
`managed` opt-in on a roleless node. Eligibility additionally requires a
supported platform, a valid WireGuard identity, and a non-gateway target. The
local runtime is split between `apps/agent`,
the headless Rust/Axum service that listens for gateway-pushed typed command
envelopes and reports lifecycle events back to the gateway, and `apps/macos`,
the Tauri tray UI that runs only on macOS.

The `agent` workload role owns autonomous agent tools and internal agent-tool
routes on Ubuntu nodes. Like any workload role it supplies managed intent, but
it does not by itself guarantee platform support or listener reachability.
Managed Agent intent never assigns the `agent` role.

`orbit node:update --managed` records explicit managed intent for a roleless
node; `--no-managed` clears it. Active workload roles provide the same intent.
Neither option installs, starts, updates, restarts, uninstalls, or proves
reachability of the macOS app or headless service. For source changes under `apps/agent` or
`apps/macos`, use the `tauri-agent-development` skill and verify native
tray/menu behavior on the implementing Mac host when `apps/macos` changes.

## Architecture in one diagram

```text
CLI caller (client / gateway-local / workload node)
        | HTTPS over WireGuard
        v
Gateway (Laravel app, SQLite intent, typed API, doctor)
        | agent-push typed envelopes over WireGuard
        v
Role-bearing nodes (orbit-caddy, FrankenPHP, systemd/launchd, Docker, SeaweedFS, Reverb)
```

Nodes do not mutate Orbit state directly. CLI calls from workload nodes still
go to the gateway like any other client call.

## State families and `doctor`

Standing configuration is tracked as **state families**. Each family is a gateway DB table (intent) plus a node-side probe (reality). Drift is the difference.

| Family key | Tracks |
|---|---|
| `node` | Fleet membership, roles, gateway identity, node reachability |
| `instance` | Concrete app-instance placement and runtime intent |
| `workspace` | Per-workspace route, setup policy, PHP override, and runtime intent |
| `process` | Runtime units for instances, workspaces, and node processes |
| `proxy` | HTTP ingress for instances, workspaces, custom routes, tool routes, redirects, gateway API |
| `firewall_rule` | Expected UFW policy on each node |
| `tool` | Expected node capabilities, versions, credentials, and tool-owned endpoints |
| `schedule` | `schedule:add` recurring tasks (Orbit Scheduler daemon) |
| `database_connection` | Reusable database connections and instance/workspace target mappings |

Doctor modes:

- **verify** (default): probe and report drift, no writes.
- **`--fix`**: enter interactive resolution mode.
- **`--restore`**: re-enact gateway intent on the node.
- **`--adopt`**: pull node reality into gateway intent (DR or fleet adoption).
  For `instance`, filesystem presence counts as intent; clean an instance's
  directory before adopting if you do not want it re-created.

Scope flags: `--node`, `--self`, `--all`, `--instance`, `--workspace`, `--family=<key>` (repeatable). Without scope flags, doctor targets the configured local default node, then falls back to the caller identity. Use `--all` for fleet verification.

## Node execution and where commands run

Every CLI call lands on the gateway (HTTPS over WireGuard). For supported
node-local command execution, the gateway pushes an allowlisted typed command
envelope to the node's Orbit Agent listener. SSH is permanent only for
provisioning/bootstrap. Every non-provisioning command executes through Agent
push or gateway-local execution and fails clearly when that lane is unavailable.

`node:new --user` is a bootstrap credential only; the steady-state user is
created during provisioning and stored on the node record.

App-role nodes do not orchestrate other nodes. They can run the CLI locally as
gateway clients, including inferring instance/workspace context from cwd, but
they do not own gateway state.

## Identity slugs

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Length limits: app <=40, instance <=63, node <=63, workspace <=63,
process <=64.

Hostnames:

- Development instance: `{app}.{node-tld}` (e.g. `myapp.beast`).
- Workspace: `{workspace}.{app}.{tld}` (e.g. `feature.myapp.beast`).
- Production instance: the value of `--domain` (globally unique across the fleet).

Process runtime unit name:
`orbit_<app>_<instance>_<workspace|main>_<process>`.
Launchd-backed units use label `dev.hardimpact.orbit.<runtimeUnit>`.

## Target resolution order

For commands that accept `--node`, `--instance`, or `--workspace`:

1. Explicit flag (`--node=beast`).
2. Instance or workspace ownership (for example,
   `--instance=myapp.development` resolves the serving node).
3. Local `node:default` value.
4. Interactive prompt  -  or non-interactive failure when stdin is unavailable / `-n` is set.

## JSON output envelope

```json
{ "success": { "data": { /* command-specific */ }, "meta": {} } }
```

Failure:

```json
{ "error": { "code": "validation_failed", "message": "Invalid input.", "meta": { "field": "name" } } }
```

Pass `--json` to force JSON. Non-interactive mode (`-n`) auto-enables JSON. Same data shape regardless of who renders it.

## Streaming (long-running) commands

Long-running operations persist journal events before publishing progress over
the gateway operations WebSocket/Reverb plane. The CLI replays journal gaps by
cursor and renders the resulting progress frames. Direct SSE remains a clearly
transitional transport only for commands that have not yet migrated.

For LLM agents, prefer `--stream-json` when the command offers it so progress
arrives as newline-delimited JSON frames during slow gateway work. Current
agent-facing stream JSON commands include `doctor`, `app:new`, `instance:setup`,
`workspace:new`, `workspace:setup`, gateway-streamed `node:new`, `deploy:run`,
`tool:install`, `tool:update`, `tool:reconfigure`, `s3:publish`, `s3:unpublish`,
and `update:all`. `--stream-json` and `--json` are mutually exclusive; use
`--json` when only the final machine-readable result is needed. Local `update`
still uses its existing `--json` final-result contract until its progress
contract is designed separately.

## Local node defaults

```bash
orbit node:default <name>     # set default development node
orbit node:default            # show current
orbit node:default --clear    # clear
```

This avoids passing `--node` on every dev-flavored command. App-role nodes do
not need this.

## What Orbit doesn't do

- It doesn't talk to workload nodes from a client directly. Always via gateway.
- It doesn't infer or store public IPv4/IPv6 from `--host`. Use `node:update --public-ipv4=... --public-ipv6=...`.
- It doesn't keep a separate "sync" command per family  -  adoption is `doctor --adopt --family=<key>`.
- It doesn't expose a separate web UI today. Future UI builds on the typed API.
- It doesn't use PHP-FPM or Supervisor for instance/workspace web runtimes.
- It doesn't proxy git credentials. `app:new --repo=...` clones through Agent
  push as the target node's Orbit runtime user, using credentials already
  available on that node.
