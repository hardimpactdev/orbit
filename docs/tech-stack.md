# Tech Stack

This document describes the implementation behind the [Architecture](architecture.md). The architecture covers the conceptual model — what Orbit is and how the parts relate. This document covers what's actually running. Command behavior is defined in [domains/](domains/README.md); concept ownership is indexed in [Concepts](concepts.md).

The pieces fit together like this:

```text
              ┌─────────────────────┐
              │    Client    │
              │  Orbit CLI client   │
              └──────────┬──────────┘
                         │
                HTTPS over WireGuard
                         │
                         ▼
              ┌─────────────────────┐
              │    Gateway Node     │
              │  Laravel · SQLite   │
              │  Caddy · WireGuard  │
              └──────────┬──────────┘
                         │
                 SSH over WireGuard
                         │
                         ▼
              ┌─────────────────────┐
              │    Node      │
              │   Caddy · PHP-FPM   │
              │ Supervisor · Docker │
              └──────────┬──────────┘
                         │
                  public 80 / 443
                         │
                         ▼
              ┌─────────────────────┐
              │      Internet       │
              └─────────────────────┘
```

The sections below walk through each layer of the stack in the same order as the table.

## Runtime

| Layer | Current implementation |
|---|---|
| Application | Laravel 13 application |
| Runtime language | PHP 8.5 default, PHP 8.4 supported |
| Persistent state | SQLite at `~/orbit/database/database.sqlite` |
| Gateway API | HTTPS over WireGuard |
| Gateway to node | SSH through `RemoteShell` |
| Proxy | Caddy |
| PHP runtime | Native PHP-FPM pools |
| Host init | systemd on Ubuntu hosts; Docker daemon restart policy in Docker E2E containers (`supervisord` as PID 1, typically under `tini`) |
| Process manager | Supervisor (`supervisord`) on every node that runs processes |
| Scheduler | `orbit-scheduler` Artisan-command daemon on the gateway only; dispatches to other nodes through `RemoteShell` |
| Process logs | Supervisor-managed stdout/stderr log files |
| Service containers | Docker Compose for databases, caches, mail, and utilities on nodes that need them |
| Agent runtime | OpenClaw and Hermes as first-party agent tools, installed through `tool:install` on nodes with the `agent` role and run as the shared unprivileged `agent` user |
| Network | WireGuard, served by the gateway-coupled `vpn` role |
| Public DNS/CDN | Cloudflare integration for production domains |

Cloudflare is the current first-party DNS/CDN provider integration. Agent IDE adapters and workspace source adapters may be first-party or extension-provided, but the gateway always owns the stored configuration and command behavior.

## Frameworks

Laravel is Orbit's application framework, while command behavior remains documented separately from framework mechanics.

### Application

Orbit is a single Laravel 13 codebase that runs in two execution contexts. On the gateway, the full Laravel app serves the typed HTTPS API and runs the gateway-local Orbit Scheduler. On clients and on every other node, the same code is invoked as a CLI client that gathers local input, calls the gateway over the VPN, and renders the result.

Orbit runs from source. There is no PHAR or container image to install — the installer checks out the repo, installs dependencies through Composer, and links `orbit` into the local executable path.

## Storage

### Persistent state

The gateway holds Orbit's durable source of truth: a single SQLite database at `~/orbit/database/database.sqlite`. Every state family writes here. Non-gateway nodes have a local SQLite present because Orbit ships a single codebase that boots the same migrations on every machine, but they hold only minimal local state — none of the durable state families live there. The gateway is authoritative for every configuration row, registry record, and history entry.

See [Architecture: State Model](architecture.md#state-model) and [Architecture: State Families](architecture.md#state-families) for the conceptual model. A few implementation notes:

- A configuration row describes a desired physical fact on a node; the node-side artifact is the applied representation of that row.
- Process lifecycle events are stored as durable history, not as a separate process-state table.
- Agent IDE defaults are gateway configuration owned by nodes and apps — not a separate state family.
- Renderers turn gateway-tracked configuration into the artifacts a node should hold. They must take target-specific inputs from gateway data or explicit probe results, never from gateway-local host state, when rendering for another node.
- Implementation-specific names (Caddy sites, UFW rules, Supervisor programs, package installs) live in renderer, probe, and migration code. They are not product-level Orbit concepts.

## Infrastructure

### Gateway API

Caddy serves the gateway's HTTPS API only on the gateway's WireGuard address. It is not a public internet vhost.

The gateway API ingress is an internal `proxy` entry the gateway owns — managed by the same proxy family that handles every other route. Its proxy and TLS artifact is repaired by `doctor --fix --family=proxy --restore`, not by a backend-named provisioning command.

The gateway API listener must not trust client-supplied forwarding identity. Caddy strips `X-Forwarded-For`, `X-Real-IP`, and `Forwarded` before requests reach Laravel. Caller identity comes from the Orbit network identity model, not from forwarded headers.

Streaming and non-streaming gateway API traffic use separate PHP-FPM sockets. Stream and log endpoints route to the stream socket; ordinary command/API execution routes to the exec socket. This prevents long-lived streams from consuming the same execution lane as short command requests.

#### Remote command progress

Long-running CLI-to-gateway commands stream structured progress over Server-Sent Events when they need live feedback. The gateway emits Orbit progress events, not arbitrary stdout:

- `tree` — declares the title and ordered step list before work starts
- `step` — updates one step's status and message
- `complete` — terminates the stream successfully and may carry command data
- `error` — terminates the stream as a command failure

The CLI consumes these events and renders the normal Orbit progress tree locally. If the stream closes without a `complete` or `error` event, the command is treated as failed. This progress stream is distinct from log streaming and from local process line streaming.

### Gateway to node

See [Architecture: Trust And Transport](architecture.md#trust-and-transport) for why this edge is SSH (not another HTTP API) and what that buys us.

VPN-role runtime administration is the one runtime exception to the normal
gateway-to-node flow. Commands that administer VPN clients (`vpn-client:*`) or
the VPN web UI (`vpn-web-ui:*`) execute against the active `vpn` role runtime.
In this version the active `vpn` role is gateway-coupled, so those commands
still run on the gateway host. When initiated from a client, Orbit resolves the
active `vpn` role host, reaches it over SSH on the Orbit/WireGuard path, and
runs the VPN-role runtime command there. This exception is for VPN-role
infrastructure administration only.

The gateway-to-node primitive is the `RemoteShell` contract:

- `run` — execute a short script and return structured output
- `stream` — execute a long-running command and stream chunks
- `upload` — write a file atomically
- `download` — read a file

`RemoteShell` connects as the steady-state SSH user stored on the node record (`nodes.user`). The `node:new --user=<user>` argument is a one-time bootstrap credential; once Orbit creates or verifies the managed SSH user (normally `orbit`), it stores that user on the node record and uses it for all later work.

Scripts are composed on the gateway. Remote shell work is non-interactive — prompts happen on the CLI caller or the gateway API layer, before any side effects begin.

`upload` writes managed files atomically: temp file, chmod, then move into place. Writes under managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`, `/boot`, `/srv`) use the target node's SSH user's passwordless sudo contract. User-owned paths are written as the SSH user.

### Proxy

Caddy is the proxy on every node. It terminates TLS, serves the gateway API on the gateway, and serves app and workspace routes on nodes with application roles. App-route certificates are issued by the Orbit root CA, so nodes serve HTTPS without ever holding the root CA private key or any general signing authority.

#### Caddy include boundaries

Caddy configuration is split by exposure boundary, not by who happens to write the file. The global `/etc/caddy/Caddyfile` imports both managed include trees:

- `/etc/caddy/orbit/*.caddy` for Orbit platform surfaces that are internal to the Orbit network
- `/etc/caddy/sites/*.caddy` for app, workspace, and custom proxy site routes

Files under `/etc/caddy/orbit/*.caddy` must be reachable only through the Orbit/WireGuard network or another explicitly internal gateway interface. The gateway API belongs here. Its site block must match the gateway WireGuard address, for example `https://10.6.0.2:443`, and must not create a broad public virtual host.

Files under `/etc/caddy/sites/*.caddy` are user-facing site routes. App routes, workspace routes, and custom proxy routes write here because they may be served on public or project domains. These files may import shared snippets from the global Caddyfile, but they must not define Orbit control-plane endpoints.

Installer and doctor repair code must be additive: ensure required imports and managed include files exist, but never replace unrelated site blocks or remove existing imports.

### PHP runtime

PHP-FPM runs natively on the gateway and on nodes with application roles — not in a container. Native execution keeps request latency predictable and avoids container overhead in the request path.

Each workspace gets its own PHP-FPM pool so workspaces are isolated from one another. Production apps get a dedicated pool as well. The PHP version for an app or workspace is gateway-tracked configuration; changing it re-renders the affected PHP-FPM pool on the owning node through `RemoteShell`.

### Process manager

Supervisor (`supervisord`) supervises Orbit-managed long-running processes on every node that runs processes. Each process Orbit tracks becomes one Supervisor program. Supervisor restarts crashed processes and captures stdout/stderr into log files surfaced by `process:logs`.

Host init keeps Supervisor itself alive. On Ubuntu, the distro `supervisor.service` unit does that. In Docker E2E topologies, the Docker daemon's container restart policy does — `supervisord` runs as PID 1 inside the container, typically under `tini`.

Other host services — Caddy, PHP-FPM, Docker, and Supervisor itself — run directly under host init, not under Supervisor. Supervisor manages Orbit-defined processes only, not the host service stack.

### Scheduler

The Orbit Scheduler is a long-running PHP process invoked as `php artisan orbit:scheduler:run`. It is supervised by Supervisor as the `orbit_scheduler` program **on the gateway only**. There is no scheduler daemon on non-gateway nodes; the gateway dispatches schedule execution to other nodes over `RemoteShell` when a schedule's target is not the gateway itself.

The daemon runs an internal loop that aligns to wall-clock minute boundaries, performs one evaluation tick, and sleeps until the next boundary:

```text
loop:
  sleep until the next wall-clock minute boundary
  perform one tick   // shared logic with `orbit schedule:run`
  goto loop
```

Each tick:

1. Queries the gateway database for every enabled schedule and selects the ones that are due in the current minute.
2. Claims a per-schedule lock in the gateway database (`schedule_locks`). Locks are gateway-owned; there is no node-local lock state.
3. Dispatches the due schedules in parallel. Schedules whose target resolves to the gateway run locally; schedules targeting any other node run on that node through `RemoteShell` (SSH). The scheduled command physically executes on the target, but the gateway is orchestrating it.
4. Records the run result — success, failure, exit code, captured output, dispatch failure — in `schedule_runs` immediately as durable gateway history.
5. Releases the lock.

The tick interval is an implementation detail. It may be tightened (for example to evaluate every ten seconds) without changing the schedule expression contract, which remains minute-resolution. Sub-minute work is not a schedule — it belongs in a Supervisor program.

Periodic execution comes from the daemon's internal sleep loop, not from Supervisor — Supervisor itself does not provide cron-style scheduling. Its contribution is to keep the PHP process alive, restart it on crash, and capture stdout/stderr.

The daemon's per-tick logic is shared with the `orbit schedule:run` command. The daemon is the steady-state path; `schedule:run` is the on-demand path used for testing, troubleshooting, and recovery.

This centralizes observability: every scheduled run's result lands in the gateway database, including dispatch failures (SSH unreachable, target down, command non-zero exit). The trade-off is that the gateway is a single point of failure for scheduling — but in v1 the active gateway-coupled `vpn` role is co-located on the same node, so the network is unusable when that node is down regardless.

### Service containers

Docker Compose runs supporting services on nodes that need them — databases, caches, mail servers, websocket utilities, and similar backing infrastructure. Compose files are rendered from gateway-tracked tool configuration and applied through `RemoteShell`. Docker is reserved for services that aren't part of the PHP request path; PHP-FPM and Caddy run on the host directly.

### Agent runtime

Nodes with the `agent` role run first-party autonomous agent tools — OpenClaw and Hermes — that operate Orbit through the gateway API. The agent role baseline converges Caddy, Supervisor, the WireGuard/node identity and trust material every other Orbit node uses, and a single unprivileged shared `agent` runtime user. Agent tools never run as the privileged `orbit` maintenance user. The agent role has typed settings with a single field `tld` (default `agent`); the gateway maps `*.{tld}` to the node's WireGuard address through the same gateway-owned development DNS mapping pattern that `app-development` uses.

Each agent tool is an ordinary entry in the `tool` catalog with category `agent`; there is no separate `agent_tool` state family. Tools are installed through `orbit tool:install`, supervised by Supervisor like any other long-running process, and configured through gateway-tracked tool state. Tool web UIs are exposed by default through tool-owned internal HTTPS proxy routes under the agent TLD (for example `https://openclaw.agent` and `https://hermes.agent`). Tool credentials and web UI tokens are returned only by `tool:credentials` and only when the caller has the explicit `tool:credentials` permission; the agent self-grant does not include that permission. Multiple agent tools may be installed and run on the same node, but Orbit warns at install or start time because node-level activity attribution is weaker when more than one is active. See [Architecture: Node roles](architecture.md#node-roles).

### Network

WireGuard is the VPN. The active `vpn` role owns the WireGuard server runtime:
every other node joins it as a peer with its own identity, and that identity is
what the gateway uses to authenticate API calls. In v1 the active `vpn` role is
gateway-coupled, so the WireGuard server still runs on the same node as the
gateway. There is no separate auth token; the WireGuard handshake is the
credential.

The gateway also acts as the Orbit root certificate authority. It issues TLS certificates for the gateway API and for app/workspace proxy routes, so HTTPS works across the fleet without an external CA.

### Public DNS/CDN

Production domains are managed through Orbit's first-party Cloudflare integration. The gateway calls the Cloudflare API to set up DNS records and to coordinate origin and edge certificates for proxied domains.

Other DNS/CDN providers can be added as extension points without changing core Orbit. The gateway always owns the stored domain configuration and command behavior, even when a provider is plugged in.

### Installation

Orbit runs from source.

Client setup is local:

```bash
curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit/main/bin/install-orbit | bash
```

The installer prepares the host before Orbit can run. It installs PHP, Composer, Git, and the required PHP extensions, checks out the Orbit source, creates the local SQLite database, runs migrations, and links `orbit` into the local executable path. Human output is a quiet step tree by default; pass `--verbose` only when the underlying package or shell command output is needed for debugging.

The installer does not create a client identity for the gateway to trust. That identity is minted later — by `node:new --role=gateway` when bootstrapping the first gateway, or by a later node enrollment flow before the client machine runs `gateway:add`.

Nodes are created through `orbit node:new [name]`.

When no gateway is configured yet, use `node:new --role=gateway --host=<host> --control-name=<control-name>` to bootstrap one. This command bootstraps the gateway runtime, creates the client identity that initiated it, installs that identity locally, stores local gateway trust and endpoint configuration, and verifies gateway API access.

When the client already has a WireGuard identity issued by an existing gateway, use `gateway:add [gateway_ip]` to join it. This command stores the local gateway API endpoint, the gateway WireGuard IP, and the trust material, installs local gateway CA trust when missing, and makes that gateway the default endpoint for subsequent Orbit commands.

### Platform and roles

The gateway role is Ubuntu-only. Roles run on Ubuntu. Clients are macOS or Ubuntu. macOS is not a hosted-role platform.

The CLI is always a thin gateway client. It has no client-side role awareness. On any machine, the CLI gathers local context (current app, workspace, paths), calls the gateway over the VPN, and renders the result. The gateway authenticates the WireGuard peer, derives grants from its own node records, and decides what to do. When work needs to run on a node (file writes, service control, log access), the gateway opens an SSH connection back to that node via `RemoteShell` — even if the CLI that initiated the work is on that same node.

One machine in the network is the gateway. That machine sets `ORBIT_IS_GATEWAY=true` in its `.env`, exposing `config('orbit.is_gateway') === true`. Every other machine leaves the flag unset (defaults to `false`). The gateway uses this flag to short-circuit its own HTTP self-calls and hit the local DB and services directly. It finds its own node row through the singleton active `gateway` role assignment in its local registry.

Non-gateway machines hold only gateway rows in their local `nodes` table — the gateways they know how to reach. Initially empty (fresh install), populated by `gateway:add`. There is no self-row on non-gateway machines.

Platform-specific behavior — installing packages, writing config files, controlling services — lives behind handlers and services, so the rest of Orbit doesn't branch on OS.
