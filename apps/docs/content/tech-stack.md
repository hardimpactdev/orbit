# Tech Stack

This document describes the implementation behind the [Architecture](architecture.md). The architecture covers the conceptual model — what Orbit is and how the parts relate. This document covers what's actually running. Command behavior is defined in [domains/](domains/README.md); concept ownership is indexed in [Concepts](concepts.md).

The pieces fit together like this:

```text
Control plane:

Client
  -> host orbit launcher
  -> CLI/local-executor artifact
  -> HTTPS over WireGuard
  -> gateway orbit-caddy
  -> gateway orbit-runtime
  -> RemoteShell over WireGuard
  -> node execution lane

Public production HTTP:

Internet
  -> public 80/443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router orbit-caddy for private HTTP/WebSocket/S3 routes, .orbit DNS, and backend pools
  -> private app-prod backend orbit-caddy
  -> FrankenPHP app container

Public WebSocket:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router orbit-caddy for `websocket.orbit` and websocket backend pools
  -> Laravel Reverb in a Docker runtime container managed by Orbit

Public S3:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router orbit-caddy for `s3.orbit`, public S3 host relay, and S3 backend pool
  -> RustFS in a Docker runtime container rendered by Orbit
```

The sections below walk through each layer of the stack in the same order as the table.

## Runtime

| Layer | Docker-first implementation |
|---|---|
| Application | Laravel 13 application mounted into `orbit-runtime` |
| Runtime language | PHP 8.5 inside Orbit-managed containers |
| Persistent state | SQLite at `~/orbit/apps/gateway/database/database.sqlite`, mounted into `orbit-runtime` on the gateway |
| Gateway API | `orbit-caddy` to `orbit-runtime` over the node Docker network; exposed only over WireGuard |
| Gateway to node | SSH through `RemoteShell`, classified by execution lane |
| Proxy | Dockerized Caddy in one `orbit-caddy` container per node |
| PHP runtime | FrankenPHP app/workspace containers |
| Host init | Docker daemon restart policy for Orbit runtime containers |
| Process manager | `process.runtime=docker` for PHP app processes; `supervisor` only where explicitly configured |
| Scheduler | Gateway scheduler loop inside `orbit-runtime` |
| Process logs | Docker stdout/stderr logs for Docker process runtime units; Supervisor logs only for explicit `supervisor` runtime units |
| Service containers | Docker for Orbit runtime containers and backing services |
| Host prerequisites | Git, Docker, host PHP 8.5 CLI for the CLI/local-executor artifact with `pdo_sqlite`, `openssl`, `curl`, `mbstring`, and `json`, Orbit launcher, WireGuard/SSH identity, and the GitHub CLI (`gh`) — required fleet-wide for authenticated public and private repository operations during cloning and deployment; VitePlus on app nodes |
| Production HTTP ingress | `orbit-caddy` on `ingress` nodes terminating public HTTPS and forwarding to `router` over WireGuard |
| Private production routing | `orbit-caddy` on the gateway-coupled `router` role selecting private HTTP/WebSocket/S3 routes, `.orbit` service names, and backend pools |
| Production app backend | `orbit-caddy` on `app-prod` nodes bound to the node's WireGuard address and forwarding to FrankenPHP app containers over the node Docker network |
| Realtime service backend | Laravel Reverb in a Docker runtime container managed by Orbit on `websocket` nodes, bound only to the node's WireGuard address and reached through router-owned WebSocket routes |
| S3 service backend | RustFS in a Docker runtime container rendered by Orbit on `s3` nodes, bound only to the node's WireGuard address and reached through router-owned S3 routes |
| Agent runtime | OpenClaw and Hermes as first-party agent tools, installed through `tool:install` on nodes with the `agent` role and run as the shared unprivileged `agent` user |
| Network | WireGuard, served by the gateway-coupled `vpn` role |
| Public DNS/CDN | Cloudflare integration for production domains |

Cloudflare is the current first-party DNS/CDN provider integration. Agent IDE adapters and workspace source adapters may be first-party or extension-provided, but the gateway always owns the stored configuration and command behavior.

## Frameworks

Laravel is Orbit's application framework, while command behavior remains documented separately from framework mechanics.

### Application

Orbit's gateway application is Laravel 13 and runs inside `orbit-runtime`. On
the gateway, `orbit-runtime` serves the typed HTTPS API behind `orbit-caddy`
and runs the gateway-local Orbit Scheduler. The host `orbit` executable
always launches the CLI/local-executor artifact at `apps/cli/orbit` on every
node role — clients, workload nodes, and gateway hosts. Gateway maintenance
(migrate, tinker, scheduler, queue, `orbit:internal:*` bake/build/install
commands) uses `bin/orbit-gateway-artisan` or direct
`php apps/gateway/artisan` from a controlled gateway shell; the public
`orbit` command never dispatches to gateway Artisan. Public commands gather
local input, call the gateway over the VPN, and render the result. Internal
executor commands are dispatched by the gateway and require an operation
token before side effects.

Orbit runs from source. There is no PHAR. The installer checks out the repo,
prepares the Docker runtime, and links the host `orbit` launcher into the local
executable path.

Host PHP CLI is required for the Orbit CLI/local-executor artifact in the
source-checkout distribution. Host PHP is not an app/workspace runtime fallback
and must not replace FrankenPHP app/workspace containers.

## Storage

### Persistent state

The gateway holds Orbit's durable source of truth: a single SQLite database at `~/orbit/apps/gateway/database/database.sqlite`. Every state family writes here. Non-gateway nodes have a local SQLite present because Orbit ships a single codebase that boots the same migrations on every machine, but they hold only minimal local state — none of the durable state families live there. The gateway is authoritative for every configuration row, registry record, and history entry.

See [Architecture: State Model](architecture.md#state-model) and [Architecture: State Families](architecture.md#state-families) for the conceptual model. A few implementation notes:

- A configuration row describes a desired physical fact on a node; the node-side artifact is the applied representation of that row.
- Process lifecycle events are stored as durable history, not as a separate process-state table.
- Agent IDE defaults are gateway configuration owned by nodes and apps — not a separate state family.
- Renderers turn gateway-tracked configuration into the artifacts a node should hold. They must take target-specific inputs from gateway data or explicit probe results, never from gateway-local host state, when rendering for another node.
- Implementation-specific names (`orbit-caddy` sites, UFW rules, Docker container names, explicit Supervisor programs, package installs) live in renderer, probe, and migration code. They are not product-level Orbit concepts.

## Infrastructure

### Gateway API

`orbit-caddy` serves the gateway's HTTPS API only on the gateway's WireGuard address. It is not a public internet vhost.

The gateway API ingress is an internal `proxy` entry the gateway owns — managed by the same proxy family that handles every other route. Its proxy and TLS artifact is repaired by `doctor --family=proxy --restore`, not by a backend-named provisioning command.

The gateway API listener must not trust client-supplied forwarding identity. `orbit-caddy` strips `X-Forwarded-For`, `X-Real-IP`, `Forwarded`, and any incoming `X-Orbit-WireGuard-Ip` before proxying to Laravel. It then injects `X-Orbit-WireGuard-Ip` from the observed WireGuard peer address for the private `orbit-runtime` hop, and gateway-mode `orbit-runtime` trusts that proxy-owned header. Caller identity still comes from the Orbit network identity model, not from caller-supplied headers.

Long-lived stream and log endpoints must not starve short command/API requests. The Docker-first API runtime owns this concurrency contract inside `orbit-runtime`; it does not use host PHP-FPM sockets and must be validated through gateway API tests.

#### Remote command progress

Long-running CLI-to-gateway commands stream structured progress over Server-Sent Events when they need live feedback. The gateway emits Orbit progress events, not arbitrary stdout:

- `tree` — declares the title and ordered step list before work starts
- `step` — updates one step's status and message
- `complete` — terminates the stream successfully and may carry command data
- `error` — terminates the stream as a command failure

The CLI consumes these events and renders the normal Orbit progress tree locally. If the stream closes without a `complete` or `error` event, the command is treated as failed. This progress stream is distinct from log streaming and from local process line streaming.

### Gateway to node

See [Architecture: Trust And Transport](architecture.md#trust-and-transport) for why this edge is SSH (not another HTTP API) and what that buys us.

Gateway-to-node work is split into `RemoteHostExecutor` for host substrate
work, `RemoteOrbitRuntimeExecutor` for gateway Laravel/artisan/PDO work inside
`orbit-runtime`, and `RemoteLocalExecutor` for token-gated packaged node-local
helper logic that needs host file access plus PHP/PDO. Host PHP is allowed for
the CLI/local-executor artifact; it is not an app/workspace runtime fallback
and must not replace FrankenPHP containers. See
[Runtime Execution Lanes](execution-lanes.md).

VPN-role runtime administration is the one runtime exception to the normal
gateway-to-node flow. Commands that administer VPN clients (`vpn-client:*`) or
the VPN web UI (`vpn-web-ui:*`) execute against the active `vpn` role runtime.
In this version the active `vpn` role is gateway-coupled, so those commands
still run on the gateway host. When initiated from a client, Orbit resolves the
active `vpn` role host, reaches it over SSH on the Orbit/WireGuard path, and
runs the VPN-role runtime command there through the execution lane contract.
Forwarded Artisan commands use `orbit-runtime`; they are not host PHP. This
exception is for VPN-role infrastructure administration only.

The gateway-to-node primitive is the `RemoteShell` contract:

- `run` — execute a short script and return structured output
- `stream` — execute a long-running command and stream chunks
- `upload` — write a file atomically
- `download` — read a file

`RemoteShell` connects as the steady-state SSH user stored on the node record (`nodes.user`). The canonical steady-state user for provisioned Linux nodes is `orbit`. The `node:new --user=<user>` argument is a one-time bootstrap credential for the first SSH connection, such as `root` or a cloud image default user; after bootstrap, Orbit stores and uses `orbit`.

SSH command construction is centralized in `SshCommandBuilder`. During the Phase 0 security baseline it preserves the existing `StrictHostKeyChecking=accept-new` behavior; later host-key pinning work switches managed node calls to pinned known-host enforcement through the same builder.

Scripts are composed on the gateway. Remote shell work is non-interactive — prompts happen on the CLI caller or the gateway API layer, before any side effects begin.

`upload` writes managed files atomically: temp file, chmod, then move into place. Writes under managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`, `/boot`, `/srv`) use the target node's SSH user's passwordless sudo contract. User-owned paths are written as the SSH user.

The current sudo model intentionally grants the `orbit` maintenance user broad passwordless sudo on managed nodes. Least-privilege sudo wrappers are not part of the current security baseline.

### Proxy

`orbit-caddy` is the standalone fleet proxy container on every node that needs HTTP routing. It terminates TLS, serves the gateway API on the gateway, serves public ingress routes on `ingress` nodes, serves private router/backend routes, and serves app and workspace routes on nodes with application roles. App-route certificates are issued by the Orbit root CA, so nodes serve HTTPS without ever holding the root CA private key or any general signing authority.

#### Caddy include boundaries

Caddy configuration is split by exposure boundary, not by who happens to write the file. The managed config mounted into `orbit-caddy` imports both managed include trees:

- `/etc/caddy/orbit/*.caddy` for Orbit platform surfaces that are internal to the Orbit network
- `/etc/caddy/sites/*.caddy` for app, workspace, and custom proxy site routes

Files under `/etc/caddy/orbit/*.caddy` must be reachable only through the Orbit/WireGuard network or another explicitly internal gateway interface. The gateway API belongs here. Its `orbit-caddy` container publishes ports only on the gateway WireGuard address, while the Caddy site uses default `:80` and `:443` listeners inside the container so IP-address clients that do not send SNI can still complete TLS. It must not create a broad public virtual host.

Files under `/etc/caddy/sites/*.caddy` are user-facing site routes. App routes, workspace routes, and custom proxy routes write here because they may be served on public or project domains. These files may import shared snippets from the managed `orbit-caddy` Caddyfile, but they must not define Orbit control-plane endpoints.

Installer and doctor repair code must be additive: ensure required imports and managed include files exist in the `orbit-caddy` mount or managed volume, but never replace unrelated site blocks or remove existing imports.

### PHP runtime

PHP app execution runs in FrankenPHP containers. The gateway runtime runs in
`orbit-runtime`; the CLI/local-executor artifact runs through host PHP 8.5 CLI
with `pdo_sqlite`, `openssl`, `curl`, `mbstring`, and `json` support in the
source-checkout distribution. Apps and workspaces run in dedicated long-lived
app/workspace containers when their runtime kind is PHP. Static or non-PHP apps
do not get a FrankenPHP container.

Each PHP workspace gets its own FrankenPHP container so workspaces are isolated
from one another. Production PHP apps get a dedicated container as well. The
PHP version for an app or workspace is gateway-tracked configuration; changing
it recreates the affected runtime container from the selected PHP image on the
owning node through `RemoteShell`. Host PHP CLI is reserved for the
CLI/local-executor artifact; host PHP and PHP-FPM are not app/workspace runtime
fallbacks.

Production public HTTP traffic enters the fleet through an active
`ingress` role. `app-prod` nodes are production runtime backends:
they own app files, FrankenPHP runtime containers, Docker process containers,
and a private `orbit-caddy` listener, but they do not own public route exposure
unless the same node also carries `ingress`.

### WebSocket runtime

The `websocket` role runs Laravel Reverb for fleet realtime traffic. Reverb
runs in a Docker runtime container managed by Orbit on the websocket node; it is
not a host Supervisor program and does not use host PHP as a fallback. The
container binds only to the node's WireGuard address with a stable backend name
such as `ws-1.websocket.orbit`.

The `router` role owns `websocket.orbit`, the websocket backend pool, and
private router-to-websocket TLS verification. Apps publish to
`https://websocket.orbit`. Public clients subscribe through app-owned public
hosts such as `wss://ws.example.com`; `ingress` terminates public TLS and
forwards those WebSocket routes to `router`, never directly to websocket nodes.

The websocket role requires Redis-backed scaling configuration from day one.
Its `redis_node_id` setting points at a node with the `database` role and a
managed Redis service. The websocket role consumes Redis; it does not install
or own Redis.

Focused WebSocket E2E coverage is pending the websocket role runtime. When that
role lands, coverage must use the prepared Docker/Incus topology lane and keep
Reverb and Redis on the Docker substrate. It must not add host PHP, host Caddy,
PHP-FPM, or host Supervisor to make realtime assertions pass.

### S3 runtime

The `s3` role runs RustFS for fleet object storage. RustFS runs in a Docker
runtime container rendered by Orbit's Docker-first runtime container services;
it is not rendered from role-local Docker Compose and does not use host package
installation as a fallback. The container uses the `rustfs/rustfs` image,
mounts the role-owned `data_path` as `/data`, and binds the S3 API only to the
node's WireGuard address on port `9000`. The RustFS console is not publicly
exposed in v1.

The `router` role owns `s3.orbit`, the S3 backend pool, upload-compatible proxy
settings, and private router-to-RustFS routing. Apps and VPN clients use
`https://s3.orbit`. Public S3 clients use operator-published hosts such as
`https://s3.example.com`; `ingress` terminates public TLS and forwards those
hosts to `router`, never directly to S3 nodes.

Router and ingress proxy rendering for S3 must preserve the original `Host`
header and forwarded protocol headers so S3 signatures are validated against
the requested endpoint. S3 routes must allow large uploads and avoid request
buffering that would make object uploads fail before RustFS receives them.

S3 credentials are service-level RustFS credentials stored on the `rustfs` tool
row. V1 does not create per-app bucket credentials, bucket lifecycle commands,
virtual-hosted bucket routes, wildcard DNS/TLS for bucket hostnames, distributed
RustFS, or HA guarantees.

Focused S3 E2E coverage is pending the S3 role runtime. When that role lands,
coverage must use the prepared Docker/Incus topology lane, keep RustFS on the
Docker-first runtime substrate, and must not add role-local Docker Compose,
host Caddy, host PHP, PHP-FPM, or host Supervisor to make object-storage
assertions pass.

### Process manager

PHP app and workspace processes use Docker process runtime units by default. Each Docker-backed process becomes an Orbit-managed sidecar container that uses the same app/workspace runtime boundary, PHP version, mounted source, and environment as the web runtime. Docker restart policy and Orbit lifecycle hooks restart crashed processes and capture stdout/stderr surfaced by `process:logs`.

`process.runtime=supervisor` remains an explicit residual runtime for supported non-PHP host-side cases. Those units are documented per process definition; they are not the default PHP app process path and must not be used as a host PHP fallback.

Host init is reduced to keeping Docker alive. Orbit runtime containers are kept alive by the Docker daemon restart policy. `orbit-caddy`, `orbit-runtime`, app/workspace runtime containers, and Docker process runtime units are Docker-managed containers, not host services.

### Scheduler

The Orbit Scheduler is a long-running PHP loop inside the gateway's `orbit-runtime` container. There is no scheduler daemon on non-gateway nodes; the gateway dispatches schedule execution to other nodes over `RemoteShell` when a schedule's target is not the gateway itself.

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

The tick interval is an implementation detail. It may be tightened (for example to evaluate every ten seconds) without changing the schedule expression contract, which remains minute-resolution. Sub-minute work is not a schedule — it belongs in a process runtime unit.

Periodic execution comes from the daemon's internal sleep loop. Docker keeps the gateway `orbit-runtime` container alive; Supervisor is not the scheduler's steady-state runtime.

The daemon's per-tick logic is shared with the `orbit schedule:run` command. The daemon is the steady-state path; `schedule:run` is the on-demand path used for testing, troubleshooting, and recovery.

This centralizes observability: every scheduled run's result lands in the gateway database, including dispatch failures (SSH unreachable, target down, command non-zero exit). The trade-off is that the gateway is a single point of failure for scheduling — but in v1 the active gateway-coupled `vpn` role is co-located on the same node, so the network is unusable when that node is down regardless.

### Service containers

Docker is the baseline substrate for Orbit runtime containers and backing services. Orbit uses Docker for `orbit-runtime`, `orbit-caddy`, FrankenPHP app/workspace containers, Docker process runtime units, databases, caches, mail servers, Laravel Reverb containers for the `websocket` role, RustFS containers for the `s3` role, and similar backing infrastructure. Docker E2E topologies use sibling containers through the host Docker socket, not Docker-in-Docker.

### Agent runtime

Nodes with the `agent` role run first-party autonomous agent tools — OpenClaw and Hermes — that operate Orbit through the gateway API. The agent role baseline converges `orbit-runtime`, `orbit-caddy`, the WireGuard/node identity and trust material every other Orbit node uses, and a single unprivileged shared `agent` runtime user. Agent tools never run as the privileged `orbit` maintenance user. The `agent` role requires a node-level `tld` setting (default `agent` when chosen during interactive `node:new`); `tld` is a shared node-level field that the `app-dev` role also requires, so a node holds at most one `tld` value at a time. The gateway maps `*.{tld}` to the node's WireGuard address through the same gateway-owned development DNS mapping pattern that `app-dev` uses. Stable private `.orbit` service names are router-owned and distinct from these development/agent TLD mappings.

Each agent tool is an ordinary entry in the `tool` catalog with category `agent`; there is no separate `agent_tool` state family. Tools are installed through `orbit tool:install`, run through the runtime backend declared by the tool definition, and configured through gateway-tracked tool state. Tool web UIs are exposed by default through tool-owned internal HTTPS proxy routes under the agent TLD (for example `https://openclaw.agent` and `https://hermes.agent`). Tool credentials and web UI tokens are returned only by `tool:credentials` and only when the caller has the explicit `tool:credentials` permission; the agent self-grant does not include that permission. Multiple agent tools may be installed and run on the same node, but Orbit warns at install or start time because node-level activity attribution is weaker when more than one is active. See [Architecture: Node roles](architecture.md#node-roles).

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

The installer prepares the host before Orbit can run. It installs or verifies Git, Docker Engine and CLI, host PHP 8.5 CLI for the CLI/local-executor artifact with `pdo_sqlite`, `openssl`, `curl`, `mbstring`, and `json`, the Orbit checkout, root and `apps/cli` Composer dependencies inside a disposable `orbit-runtime` container, operation-token executor configuration, the long-running `orbit-runtime` container, the default FrankenPHP app/workspace runtime image, the `orbit-caddy` container where the node role needs HTTP routing, WireGuard/SSH identity material, and the host `orbit` launcher. It creates the local SQLite database where appropriate, runs migrations inside a disposable `orbit-runtime` container before starting the long-running runtime, and links `orbit` into the local executable path. Human output is a quiet step tree by default; pass `--verbose` only when the underlying package or shell command output is needed for debugging.

The installer does not create a client identity for the gateway to trust. That identity is minted later — by `node:new --template=gateway` when bootstrapping the first gateway, or by a later node enrollment flow before the client machine runs `gateway:add`.

Nodes are created through `orbit node:new [name]`.

When no gateway is configured yet, use `node:new --template=gateway --host=<host> --operator-name=<operator-name>` to bootstrap one. This command bootstraps the gateway runtime, creates the client identity that initiated it, installs that identity locally, stores local gateway trust and endpoint configuration, and verifies gateway API access.

When the client already has a WireGuard identity issued by an existing gateway, use `gateway:add [gateway_ip]` to join it. This command stores the local gateway API endpoint, the gateway WireGuard IP, and the trust material, installs local gateway CA trust when missing, and makes that gateway the default endpoint for subsequent Orbit commands.

### Platform and roles

The Orbit CLI runs on macOS and Ubuntu. The `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, and `s3` role drivers currently support Ubuntu only. macOS is therefore a supported client OS but cannot host a role assignment until a driver gains macOS support. See [Architecture: Node roles](architecture.md#node-roles) for the driver concept.

The CLI is always a thin gateway client. It has no client-side role awareness. On any machine, the CLI gathers local context (current app, workspace, paths), calls the gateway over the VPN, and renders the result. The gateway authenticates the WireGuard peer, derives grants from its own node records, and decides what to do. When work needs to run on a node (file writes, service control, log access), the gateway opens an SSH connection back to that node via `RemoteShell` — even if the CLI that initiated the work is on that same node.

One machine in the network carries the gateway runtime. The gateway runtime may
set `ORBIT_IS_GATEWAY=true` in `apps/gateway/.env` so gateway-maintenance
commands and runtime services can select gateway-only behavior. That flag is not
used by the installed public `orbit` launcher and it is not an API
authorization bypass. Public CLI calls, including calls made on the gateway host
itself, enter `apps/cli/orbit` and call the gateway API over the configured
WireGuard/orbit-caddy HTTPS endpoint. The gateway finds its own node row through
the singleton active `gateway` role assignment in its local registry.

Non-gateway machines hold only gateway rows in their local `nodes` table — the gateways they know how to reach. Initially empty (fresh install), populated by `gateway:add`. There is no self-row on non-gateway machines.

Platform-specific behavior — installing packages, writing config files, controlling services — lives behind handlers and services, so the rest of Orbit doesn't branch on OS.
