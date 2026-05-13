# Building Blocks

This document describes the implementation behind the [Architecture](ARCHITECTURE.md). The architecture covers the conceptual model — what Orbit is and how the parts relate. This document covers what's actually running. Command behavior is defined in [commands/](commands/README.md); concept ownership is indexed in [Concepts](CONCEPTS.md).

The pieces fit together like this:

```text
              ┌─────────────────────┐
              │    Control Node     │
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
              │      App Node       │
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

## Technology Stack

| Layer | Current implementation |
|---|---|
| Application | Laravel 13 application |
| Runtime language | PHP 8.5 default, PHP 8.4 supported |
| Persistent state | SQLite at `~/orbit/database/database.sqlite` |
| Gateway API | HTTPS over WireGuard |
| Gateway to app node | SSH through `RemoteShell` |
| Proxy | Caddy |
| PHP runtime | Native PHP-FPM pools |
| Host init | systemd on Ubuntu hosts; Docker daemon restart policy in Docker E2E containers (`supervisord` as PID 1, typically under `tini`) |
| Process manager | Supervisor (`supervisord`) on every gateway and app node |
| Scheduler | `orbit-scheduler` Artisan-command daemon supervised by Supervisor |
| Process logs | Supervisor-managed stdout/stderr log files |
| Service containers | Docker Compose for databases, caches, mail, and utilities |
| Network | WireGuard |
| Public DNS/CDN | Cloudflare integration for production domains |

Cloudflare is the current first-party DNS/CDN provider integration. Agent IDE adapters and workspace source adapters may be first-party or extension-provided, but the gateway always owns the stored configuration and command behavior.

## Application

Orbit is a single Laravel 13 codebase that runs in two roles. On the gateway, the full Laravel app serves the typed HTTPS API and runs the gateway-local Orbit Scheduler. On control nodes and app nodes, the same code is invoked as a CLI client that gathers local input, calls the gateway over the VPN, and renders the result.

Orbit runs from source. There is no PHAR or container image to install — the installer checks out the repo, installs dependencies through Composer, and links `orbit` into the local executable path.

## Persistent State

The gateway holds Orbit's only durable store: a single SQLite database at `~/orbit/database/database.sqlite`. Every state family writes here. App nodes do not have their own Orbit database.

See [Architecture: State Model](ARCHITECTURE.md#state-model) and [Architecture: State Families](ARCHITECTURE.md#state-families) for the conceptual model. A few implementation notes:

- A configuration row describes a desired physical fact on a node; the node-side artifact is the applied representation of that row.
- Process lifecycle events are stored as durable history, not as a separate process-state table.
- Agent IDE defaults are gateway configuration owned by nodes and apps — not a separate state family.
- Renderers turn gateway-tracked configuration into the artifacts a node should hold. They must take target-specific inputs from gateway data or explicit probe results, never from gateway-local host state, when rendering for another node.
- Implementation-specific names (Caddy sites, UFW rules, Supervisor programs, package installs) live in renderer, probe, and migration code. They are not product-level Orbit concepts.

## Gateway API

Caddy serves the gateway's HTTPS API only on the gateway's WireGuard address. It is not a public internet vhost.

The gateway API ingress is a gateway-owned internal `proxy` entry — managed by the same proxy family that handles every other route. Its proxy/TLS artifact is repaired by `doctor --fix --family=proxy --restore`, not by a backend-named provisioning command.

The gateway API listener must not trust client-supplied forwarding identity. Caddy strips `X-Forwarded-For`, `X-Real-IP`, and `Forwarded` before requests reach Laravel. Caller identity comes from the Orbit network identity model, not from forwarded headers.

Streaming and non-streaming gateway API traffic use separate PHP-FPM sockets. Stream and log endpoints route to the stream socket; ordinary command/API execution routes to the exec socket. This prevents long-lived streams from consuming the same execution lane as short command requests.

### Remote Command Progress

Long-running CLI-to-gateway commands stream structured progress over Server-Sent Events when they need live feedback. The gateway emits Orbit progress events, not arbitrary stdout:

- `tree` — declares the title and ordered step list before work starts
- `step` — updates one step's status and message
- `complete` — terminates the stream successfully and may carry command data
- `error` — terminates the stream as a command failure

The CLI consumes these events and renders the normal Orbit progress tree locally. If the stream closes without a `complete` or `error` event, the command is treated as failed. This progress stream is distinct from log streaming and from local process line streaming.

## Gateway To App Node

See [Architecture: Trust And Transport](ARCHITECTURE.md#trust-and-transport) for why this edge is SSH (not another HTTP API) and what that buys us.

VPN administration is the one gateway-local exception. Commands that administer VPN clients (`vpn-client:*`) or the VPN web UI (`vpn-web-ui:*`) execute on the gateway host. When initiated from a control node, Orbit reaches the gateway over SSH on the Orbit/WireGuard path and runs the gateway-local command there. This exception is for gateway infrastructure administration only.

The gateway-to-app-node primitive is the `RemoteShell` contract:

- `run` — execute a short script and return structured output
- `stream` — execute a long-running command and stream chunks
- `upload` — write a file atomically
- `download` — read a file

`RemoteShell` connects as the steady-state SSH user stored on the node record (`nodes.user`). The `node:new --ssh-user=<user>` argument is a one-time bootstrap credential; once Orbit creates or verifies the managed SSH user (normally `orbit`), it stores that user on the node record and uses it for all later work.

Scripts are composed on the gateway. Remote shell work is non-interactive — prompts happen on the CLI caller or the gateway API layer, before any side effects begin.

`upload` writes managed files atomically: temp file, chmod, then move into place. Writes under managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`, `/boot`, `/srv`) use the app-node SSH user's passwordless sudo contract. User-owned paths are written as the SSH user.

## Proxy

Caddy is the proxy on every node. It terminates TLS, serves the gateway API on the gateway, and serves app and workspace routes on app nodes. App-route certificates are issued by the Orbit root CA, so app nodes serve HTTPS without ever holding the root CA private key or any general signing authority.

### Caddy Include Boundaries

Caddy configuration is split by exposure boundary, not by who happens to write the file. The global `/etc/caddy/Caddyfile` imports both managed include trees:

- `/etc/caddy/orbit/*.caddy` for Orbit platform surfaces that are internal to the Orbit network
- `/etc/caddy/sites/*.caddy` for app, workspace, and custom proxy site routes

Files under `/etc/caddy/orbit/*.caddy` must be reachable only through the Orbit/WireGuard network or another explicitly internal gateway interface. The gateway API belongs here. Its site block must match the gateway WireGuard address, for example `https://10.6.0.2:443`, and must not create a broad public virtual host.

Files under `/etc/caddy/sites/*.caddy` are user-facing site routes. App routes, workspace routes, and custom proxy routes write here because they may be served on public or project domains. These files may import shared snippets from the global Caddyfile, but they must not define Orbit control-plane endpoints.

Installer and doctor repair code must be additive: ensure required imports and managed include files exist, but never replace unrelated site blocks or remove existing imports.

## PHP Runtime

PHP-FPM runs natively on the gateway and on every app node — not in a container. Native execution keeps request latency predictable and avoids container overhead in the request path.

Each workspace gets its own PHP-FPM pool so workspaces are isolated from one another. Production apps get a dedicated pool as well. The PHP version for an app or workspace is gateway-tracked configuration; changing it re-renders the affected PHP-FPM pool on the owning node through `RemoteShell`.

## Process Manager

Supervisor (`supervisord`) supervises Orbit-managed long-running processes on every gateway and app node. Each process Orbit tracks becomes one Supervisor program. Supervisor restarts crashed processes and captures stdout/stderr into log files surfaced by `process:logs`.

Host init keeps Supervisor itself alive: the distro `supervisor.service` unit on Ubuntu, or the Docker daemon's container restart policy in Docker E2E topologies (`supervisord` runs as PID 1 inside the container, typically under `tini`).

Other host services — Caddy, PHP-FPM, Docker, and Supervisor itself — run directly under host init, not under Supervisor. Supervisor manages Orbit-defined processes only, not the host service stack.

## Scheduler

The Orbit Scheduler is a long-running PHP process invoked as `php artisan orbit:scheduler:run`. It is supervised by Supervisor as the `orbit_scheduler` program. Both the gateway and every app node run their own scheduler instance for the schedules local to that node.

The daemon runs an internal loop that aligns to wall-clock minute boundaries, performs one evaluation tick, and sleeps until the next boundary:

```text
loop:
  sleep until the next wall-clock minute boundary
  perform one tick   // shared logic with `orbit schedule:run`
  goto loop
```

The tick interval is an implementation detail. It may be tightened (for example to evaluate every ten seconds) without changing the schedule expression contract, which remains minute-resolution. Sub-minute work is not a schedule — it belongs in a Supervisor program.

Periodic execution comes from the daemon's internal sleep loop, not from Supervisor — Supervisor itself does not provide cron-style scheduling. Its contribution is to keep the PHP process alive, restart it on crash, and capture stdout/stderr.

The daemon's per-tick logic is shared with the `orbit schedule:run` command. The daemon is the steady-state path; `schedule:run` is the on-demand path used for testing, troubleshooting, and recovery.

## Service Containers

Docker Compose runs supporting services on app nodes — databases, caches, mail servers, websocket utilities, and similar backing infrastructure. Compose files are rendered from gateway-tracked tool configuration and applied through `RemoteShell`. Docker is reserved for services that aren't part of the PHP request path; PHP-FPM and Caddy run on the host directly.

## Network

WireGuard is the VPN. The gateway is the WireGuard hub: every other node joins as a peer with its own identity, and that identity is what the gateway uses to authenticate API calls. There is no separate auth token; the WireGuard handshake is the credential.

The gateway also acts as the Orbit root certificate authority. It issues TLS certificates for the gateway API and for app/workspace proxy routes, so HTTPS works across the fleet without an external CA.

## Public DNS/CDN

Production domains are managed through the first-party Cloudflare provider integration. The gateway calls the Cloudflare API to set up DNS records and to coordinate origin and edge certificates for proxied domains.

Other DNS/CDN providers can be added as extension points without changing core Orbit. The gateway always owns the stored domain configuration and command behavior, even when a provider is plugged in.

## Installation

Orbit runs from source.

Control node setup is local:

```bash
curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit/main/bin/install-orbit | bash
```

The installer prepares the host before Orbit can run: it installs PHP, Composer, Git, and required PHP extensions, then installs the Orbit source checkout, creates the local SQLite database, runs migrations, and links `orbit` into the local executable path. Human output is a quiet step tree by default; pass `--verbose` only when the underlying package or shell command output is needed for debugging. The installer does not create a gateway-owned control-node identity — that identity is minted by `node:new --role=gateway` for first-gateway bootstrap, or by `node:new --role=control` on an existing gateway before the control machine runs `gateway:add`.

Gateway and app nodes are created through `orbit node:new [name]`. `node:new --role=gateway --host=<host> --control-name=<control-name>` is the first-gateway bootstrap path when no gateway is configured. It bootstraps the gateway runtime, creates the initiating control-node identity, installs that identity locally, stores local gateway trust and endpoint configuration, and verifies gateway API access.

`gateway:add [gateway_ip]` is the existing-gateway join path for a control node that already has a gateway-issued WireGuard identity. It stores the local gateway API endpoint, gateway WireGuard IP, and trust material, installs local gateway CA trust when missing, then makes that gateway the default endpoint for subsequent Orbit commands.

## Platform And Roles

Gateways and app nodes are Ubuntu. Control nodes are macOS or Ubuntu. macOS is not an app-hosting platform.

Each node knows its own role through the local `general.local_node_role` setting (`control`, `gateway`, or `app`; unset or `null` resolves to `control`). Bootstrap writes this setting only after the node identity and minimum readiness are established. Doctor verifies it against gateway configuration when the expected role is known.

Platform-specific behavior — installing packages, writing config files, controlling services — lives behind handlers and services, so the rest of Orbit doesn't branch on OS.
