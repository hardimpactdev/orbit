# Orbit Concepts

Authoritative source: [`apps/docs/content/architecture.md`](../../../apps/docs/content/architecture.md), [`apps/docs/content/concepts.md`](../../../apps/docs/content/concepts.md), [`apps/docs/content/tech-stack.md`](../../../apps/docs/content/tech-stack.md). This file is a quick reference for the parts that matter when calling the CLI.

## Node roles

| Role | Platform | What it owns |
|---|---|---|
| **Gateway** | Ubuntu | Canonical SQLite DB, typed HTTPS API, Orbit root CA, WireGuard, DNS, SSH to app nodes, the only writer of fleet intent |
| **Operator node** | macOS or Ubuntu | Runs the CLI; stores local gateway endpoint, WireGuard identity, trusted CA. No durable Orbit state |
| **App node** | Ubuntu | Runs PHP-FPM, Caddy, systemd process units, Docker services, app/workspace files. Stateless CLI client to the gateway |

The local caller role comes from the gateway-owned node identity. Operator callers have no hosted workload role; gateway and app bootstrap write their local readiness state after provisioning.

## Architecture in one diagram

```text
CLI caller (operator / app / gateway-local)
        │ HTTPS over WireGuard
        ▼
Gateway (Laravel app, SQLite intent, typed API, doctor)
        │ SSH via RemoteShell
        ▼
App nodes (PHP-FPM, Caddy, systemd, Docker)
```

App nodes never talk to each other. App-node CLI calls go to the gateway like any operator-node call.

## State families and `doctor`

Standing configuration is tracked as **state families**. Each family is a gateway DB table (intent) plus a node-side probe (reality). Drift is the difference.

| Family key | Tracks |
|---|---|
| `node` | Fleet membership, roles, gateway identity, node reachability |
| `app` | App registry and app-owned runtime intent |
| `workspace` | Per-workspace Caddy vhost + PHP-FPM pool + cert |
| `process` | Runtime units for app, workspace, and node processes |
| `proxy` | HTTP ingress for apps, workspaces, custom routes, tool routes, redirects, gateway API |
| `firewall_rule` | Expected UFW policy on each node |
| `tool` | Expected tool installs and lifecycle on each node |
| `schedule` | `schedule:add` recurring tasks (Orbit Scheduler daemon) |

Doctor modes:

- **verify** (default): probe and report drift, no writes.
- **`--fix --restore`**: re-enact gateway intent on the node.
- **`--fix --adopt`**: pull node reality into gateway intent (DR or fleet adoption). For `app`, filesystem presence counts as intent — clean an app's directory before adopting if you don't want it re-created.

Scope flags: `--node`, `--self`, `--app`, `--workspace`, `--family=<key>` (repeatable). Without scope flags, doctor checks every family on every reachable node.

## RemoteShell and where commands run

Every CLI call lands on the gateway (HTTPS over WireGuard). The gateway then enacts node changes via `RemoteShell` (`run` / `stream` / `upload` / `download`) over SSH using the steady-state user recorded on the node row (typically `orbit`).

`node:new --ssh-user` is a bootstrap credential only — the steady-state user is created during provisioning and stored on the node record.

App nodes do not orchestrate other nodes. They can run the CLI locally as gateway clients (e.g. inferring app/workspace context from cwd), but they don't own state.

## Identity slugs

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Length limits: app ≤40, node ≤63, workspace ≤63, process ≤64.

Hostnames:

- Development app: `{app}.{node-tld}` (e.g. `myapp.beast`).
- Workspace: `{workspace}.{app}.{tld}` (e.g. `feature.myapp.beast`).
- Production app: the value of `--domain` (globally unique across the fleet).

Process runtime unit name: `orbit_<app>_<workspace|main>_<process>`.

## Target resolution order

For commands that accept `--node`, `--app`, or `--workspace`:

1. Explicit flag (`--node=beast`).
2. App or workspace ownership (e.g. `--app=myapp` resolves the owning node).
3. Local `node:default` value (operator nodes only).
4. Interactive prompt — or non-interactive failure when stdin is unavailable / `-n` is set.

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

Commands like `workspace:setup`, `deploy:run`, `tool:install`, and `node:new` stream Server-Sent Events from the gateway. The CLI renders a step tree (`tree` → `step` events → `complete`/`error`). If the stream closes without `complete` or `error`, the command failed.

## Local node defaults (operator nodes)

```bash
orbit node:default <name>     # set default app node
orbit node:default            # show current
orbit node:default --clear    # clear
```

This avoids passing `--node` on every dev-flavored command. App nodes don't need this.

## What Orbit doesn't do

- It doesn't talk to app nodes from an operator node directly. Always via gateway.
- It doesn't infer or store public IPv4/IPv6 from `--host`. Use `node:update --public-ipv4=… --public-ipv6=…`.
- It doesn't keep a separate "sync" command per family — adoption is `doctor --fix --adopt --family=<key>`.
- It doesn't expose a separate web UI today. Future UI builds on the typed API.
- It doesn't proxy git credentials. `app:new --repo=…` clones non-interactively as the SSH user already configured on the app node.
