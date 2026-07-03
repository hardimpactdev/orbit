# Orbit Concepts

Authoritative source: [`apps/docs/content/architecture.md`](../../../apps/docs/content/architecture.md), [`apps/docs/content/concepts.md`](../../../apps/docs/content/concepts.md), [`apps/docs/content/tech-stack.md`](../../../apps/docs/content/tech-stack.md). This file is a quick reference for the parts that matter when calling the CLI.

## Node roles

| Role | Platform | What it owns |
|---|---|---|
| `gateway` | Ubuntu | Canonical SQLite DB, typed HTTPS API, Orbit root CA, node access policy, and doctor convergence |
| `vpn` | Ubuntu | Gateway-coupled WireGuard server runtime, public endpoint settings, peer defaults, and VPN-facing DNS runtime |
| `router` | Ubuntu | Gateway-coupled private `.orbit` service names, route artifacts, backend pools, and private routing |
| `app-dev` | Ubuntu/macOS | Development app/workspace files, local TLD routes, FrankenPHP runtimes, and dev host toolchain |
| `app-prod` | Ubuntu | Production app files, private backend routes, FrankenPHP runtimes, and deploy/runtime policy |
| `database` | Ubuntu/macOS | Private database-role node capability and managed database process dependencies |
| `agent` | Ubuntu | Exclusive autonomous agent workload role and internal agent tool routes |
| `ingress` | Ubuntu | Public production HTTP/HTTPS edge and forwarding to `router` over WireGuard |
| `websocket` | Ubuntu | Private Laravel Reverb backend, reached through router-owned routes |
| `s3` | Ubuntu | Private SeaweedFS backend, reached through router-owned S3 routes |
| `metrics` | Ubuntu/Debian | Private Prometheus/Grafana host-resource metrics backend, reached through `metrics.orbit` |

An **operator** is a node identity with the operator permission preset and
grants. It is not a stored role. Any gateway-known node can be a client when it
runs the CLI.

## Orbit Agent lane

Orbit Agent capability is explicit gateway registry state for supported nodes,
starting with macOS `app-dev` and self-managed workload nodes. The local runtime
bootstrap lives under `apps/agent` as a Tauri/Rust macOS menu-bar app and
headless worker that polls for typed Orbit jobs and reports lifecycle events
back to the gateway.

The `agent` workload role is separate from Orbit Agent capability. The role owns
autonomous agent tools and internal agent-tool routes on Ubuntu nodes. It does
not imply that a node can receive Orbit Agent jobs, and Orbit Agent capability
does not assign the `agent` role.

`orbit node:update --orbit-agent-capable` toggles only the capability flag. It
does not install, start, update, restart, uninstall, or prove reachability of
the macOS app. For source changes under `apps/agent`, use the
`tauri-agent-development` skill and verify native tray/menu behavior on the
implementing Mac host.

## Architecture in one diagram

```text
CLI caller (client / gateway-local / workload node)
        | HTTPS over WireGuard
        v
Gateway (Laravel app, SQLite intent, typed API, doctor)
        | SSH / execution lane over WireGuard
        v
Role-bearing nodes (orbit-caddy, FrankenPHP, systemd, Docker, SeaweedFS, Reverb)
```

Nodes do not mutate Orbit state directly. CLI calls from workload nodes still
go to the gateway like any other client call.

## State families and `doctor`

Standing configuration is tracked as **state families**. Each family is a gateway DB table (intent) plus a node-side probe (reality). Drift is the difference.

| Family key | Tracks |
|---|---|
| `node` | Fleet membership, roles, gateway identity, node reachability |
| `app` | App registry and app-owned runtime intent |
| `workspace` | Per-workspace route, setup policy, PHP override, and runtime intent |
| `process` | Runtime units for app, workspace, and node processes |
| `proxy` | HTTP ingress for apps, workspaces, custom routes, tool routes, redirects, gateway API |
| `firewall_rule` | Expected UFW policy on each node |
| `tool` | Expected node capabilities, versions, credentials, and tool-owned endpoints |
| `schedule` | `schedule:add` recurring tasks (Orbit Scheduler daemon) |
| `database_connection` | Reusable database connections and app/workspace target mappings |

Doctor modes:

- **verify** (default): probe and report drift, no writes.
- **`--fix`**: enter interactive resolution mode.
- **`--restore`**: re-enact gateway intent on the node.
- **`--adopt`**: pull node reality into gateway intent (DR or fleet adoption). For `app`, filesystem presence counts as intent  -  clean an app's directory before adopting if you don't want it re-created.

Scope flags: `--node`, `--self`, `--all`, `--app`, `--workspace`, `--family=<key>` (repeatable). Without scope flags, doctor targets the configured local default node, then falls back to the caller identity. Use `--all` for fleet verification.

## RemoteShell and where commands run

Every CLI call lands on the gateway (HTTPS over WireGuard). The gateway then enacts node changes via `RemoteShell` (`run` / `stream` / `upload` / `download`) over SSH using the steady-state user recorded on the node row (typically `orbit`).

`node:new --user` is a bootstrap credential only; the steady-state user is
created during provisioning and stored on the node record.

App nodes do not orchestrate other nodes. They can run the CLI locally as gateway clients (e.g. inferring app/workspace context from cwd), but they don't own state.

## Identity slugs

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Length limits: app <=40, node <=63, workspace <=63, process <=64.

Hostnames:

- Development app: `{app}.{node-tld}` (e.g. `myapp.beast`).
- Workspace: `{workspace}.{app}.{tld}` (e.g. `feature.myapp.beast`).
- Production app: the value of `--domain` (globally unique across the fleet).

Process runtime unit name: `orbit_<app>_<workspace|main>_<process>`.

## Target resolution order

For commands that accept `--node`, `--app`, or `--workspace`:

1. Explicit flag (`--node=beast`).
2. App or workspace ownership (e.g. `--app=myapp` resolves the owning node).
3. Local `node:default` value.
4. Interactive prompt  -  or non-interactive failure when stdin is unavailable / `-n` is set.

## JSON output envelope

```json
{ "success": { "data": { /* command-specific */ }, "meta": {} } }
```

Failure:

```json
{ "success": false, "error": "human-readable message" }
```

Pass `--json` to force JSON. Non-interactive mode (`-n`) auto-enables JSON. Same data shape regardless of who renders it.

## Streaming (long-running) commands

Commands like `workspace:setup`, `deploy:run`, `tool:install`, and `node:new` stream Server-Sent Events from the gateway. The CLI renders a step tree (`tree` -> `step` events -> `complete`/`error`). If the stream closes without `complete` or `error`, the command failed.

For LLM agents, prefer `--stream-json` when the command offers it so progress
arrives as newline-delimited JSON frames during slow gateway work. Current
agent-facing stream JSON commands include `doctor`, `app:new`, `app:setup`,
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

This avoids passing `--node` on every dev-flavored command. App nodes don't need this.

## What Orbit doesn't do

- It doesn't talk to workload nodes from a client directly. Always via gateway.
- It doesn't infer or store public IPv4/IPv6 from `--host`. Use `node:update --public-ipv4=... --public-ipv6=...`.
- It doesn't keep a separate "sync" command per family  -  adoption is `doctor --adopt --family=<key>`.
- It doesn't expose a separate web UI today. Future UI builds on the typed API.
- It doesn't use PHP-FPM or Supervisor for app/workspace web runtimes.
- It doesn't proxy git credentials. `app:new --repo=...` clones non-interactively as the SSH user already configured on the owning node.
