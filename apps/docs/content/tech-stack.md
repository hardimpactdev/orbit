# Tech Stack

This document describes the implementation behind the [Architecture](architecture.md). The architecture covers the conceptual model — what Orbit is and how the parts relate. This document covers what's actually running. Command behavior is defined in [domains/](domains/README.md); concept ownership is indexed in [Concepts](concepts.md).

The pieces fit together like this:

```text
Control plane:

Client
  -> host orbit launcher
  -> CLI/local-executor artifact
  -> HTTPS over WireGuard
  -> gateway HTTPS exposure
  -> orbit-gateway
  -> node execution lane
     (gateway-only for gateway work, Agent push for node work)

Bootstrap edge:

Configured client
  -> client-local SSH
  -> new target WireGuard + CLI + Agent substrate

Public production HTTP:

Internet
  -> public 80/443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router orbit-caddy for private HTTP/WebSocket/S3 routes, .orbit DNS, and backend pools
  -> private app-prod backend orbit-caddy
  -> per-app FrankenPHP runtime container

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
  -> canonical `seaweedfs` Docker process rendered by Orbit

Private metrics:

VPN client browser
  -> router orbit-caddy for `metrics.orbit`
  -> Grafana Docker Swarm service on the metrics role node
  -> Prometheus Docker Swarm service on the metrics role node
  -> node-exporter host binary tool and systemd process on metrics and Ubuntu workload nodes

Private analytics:

VPN client browser
  -> router orbit-caddy for `analytics.orbit`
  -> Plausible CE in a WireGuard-bound Docker process managed by Orbit

Public app analytics tracking:

Browser
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router orbit-caddy for public analytics host relay and analytics backend pool
  -> Plausible CE in a WireGuard-bound Docker process managed by Orbit
```

The sections below walk through each layer of the stack in the same order as the table.

## Runtime

| Layer | Production substrate implementation |
|---|---|
| Application | Laravel 13 gateway application bundled into `ghcr.io/hardimpactdev/orbit-gateway:<version>` |
| Runtime language | PHP 8.5 inside Orbit-managed containers |
| Persistent state | Gateway SQLite at `ORBIT_CONFIG_ROOT/gateway.sqlite`, mounted into `orbit-gateway`, `orbit-scheduler`, and `orbit-runtime-hibernator` |
| Gateway API | `router-colocated`: router-owned `orbit-caddy` to `orbit-gateway` over `orbit-network`; `gateway-direct`: `orbit-gateway` publishes HTTPS directly; both are restricted to Orbit/WireGuard access |
| Gateway to node | Managed execution converges on `gateway-only` for gateway-owned reads/writes and `agent-push` for node-local execution. `agent-push` is gateway-authenticated HTTP to the node's Agent listener over Orbit/WireGuard for Agent-eligible nodes. Intent is derived from active workload roles or explicit `managed` opt-in on a roleless non-gateway operator; eligibility also requires an active supported platform and valid WireGuard identity. Gateway nodes are never Agent targets. Agent execution is a gateway-built `binary + argv` request with gateway-issued operation tokens and a node-local binary allowlist; no arbitrary shell-over-HTTP. Reachable Agent nodes use gateway push only; the gateway is the sole initiator and the Agent runs no background retrieval loop. Break-glass SSH is operator-owned super-admin recovery outside normal Orbit command execution. |
| Proxy | Dockerized Caddy in one `orbit-caddy` container per node; HTTPS listener intent publishes TCP/443 and UDP/443 where Orbit exposes HTTP ingress |
| PHP runtime | FrankenPHP app/workspace containers |
| Host init | Docker daemon plus Docker Swarm for gateway services and Docker-backed runtime units; systemd for Linux host command process units; launchd for macOS host command process units |
| Process manager | Process runtime backends: systemd for Linux host command process units, launchd for macOS host command process units, Docker for containerized process units, Docker Swarm for selected node-owned service processes |
| Scheduler | One-replica `orbit-scheduler` Swarm service using the Orbit gateway image |
| Runtime hibernator | One-replica `orbit-runtime-hibernator` Swarm service using the Orbit gateway image and checking development runtime idleness every ten minutes |
| Process logs | Runtime-backend log capture for process units; journald for systemd-backed host command processes, Orbit-owned stdout/stderr files for launchd-backed host command processes, and Docker logs for containerized processes |
| Service containers | Docker for Orbit runtime containers and backing services |
| Node provisioning and host prerequisites | A configured client first observes Ubuntu version and CPU architecture over client-local SSH, then streams a gateway-authored bootstrap bundle through that SSH edge to establish the managed user, WireGuard identity, node-local Orbit config, and architecture-matched CLI/Agent entry points on a new managed Ubuntu node. After Agent readiness, the gateway converges service-address routing, host prerequisites, and the full node security baseline through Agent push. Those are topology infrastructure, not app, process, tool, or database runtime prerequisites. Production gateway-only nodes additionally require Docker Engine/CLI, Docker Swarm, gateway config root, and the native Orbit CLI binary. `app-dev` and `app-prod` nodes additionally require host PHP and Composer for app-source workflows; the Laravel installer is required on `app-dev` only. Git and `gh` are required where cloning/deployment needs repository access, not for no-source gateway-only production. Source-dev topologies may bind-mount or copy the worktree and point `/usr/local/bin/orbit` at `<source>/apps/cli/orbit`; artifact-prod topologies use built CLI binaries and production images. |
| Production HTTP ingress | `orbit-caddy` on `ingress` nodes terminating public HTTPS and forwarding to `router` over WireGuard |
| Private production routing | `orbit-caddy` on the gateway-coupled `router` role selecting private HTTP/WebSocket/S3 routes, `.orbit` service names, and backend pools |
| Production app backend | App-role-owned `orbit-caddy` on `app-prod` nodes bound to the node's WireGuard address and forwarding to per-app FrankenPHP Docker runtime containers on internal port `8080` over the node Docker network |
| App-dev PHP backend | Unmodified official `caddy:2-alpine` in `orbit-caddy` on `app-dev` nodes, terminating the public site route, recording per-scope HTTP activity, and invoking a marker-gated private gateway wake pre-check before reverse-proxying to per-app or per-workspace FrankenPHP containers over plain HTTP by default, or over opt-in inner HTTPS on port `8443` when the app's PHP runtime config sets `proxy_transport=https` |
| Realtime service backend | Laravel Reverb in a Docker runtime container managed by Orbit on `websocket` nodes, bound only to the node's WireGuard address and reached through router-owned WebSocket routes |
| S3 service backend | SeaweedFS in a canonical node-owned Docker process on `s3` nodes, bound only to the node's WireGuard address and reached through router-owned S3 routes |
| Metrics backend | Prometheus and Grafana as Docker Swarm process definitions on metrics role nodes; node-exporter as a host binary tool plus systemd process on metrics and active Ubuntu workload nodes; Grafana private route `metrics.orbit` |
| Analytics service backend | Plausible CE 3.2.1 in a node-owned Docker process on `analytics` nodes, published only on the node's WireGuard address and reached through router-owned analytics routes. PostgreSQL 16 Alpine and ClickHouse 24.12 Alpine run as authenticated, node-owned Docker service processes published only on active `database` nodes' WireGuard addresses. |
| Agent runtime | Hermes as first-party agent tools, installed through `tool:install` on nodes with the `agent` role and run as the shared unprivileged `agent` user |
| Network | WireGuard, served by the gateway-coupled `vpn` role |
| Public DNS/CDN | Cloudflare integration for production domains |

## Frameworks

Laravel is Orbit's application framework, while command behavior remains documented separately from framework mechanics.

### Application

Orbit's gateway application is Laravel 13 and runs inside the
`orbit-gateway` image. On the gateway, Swarm keeps three gateway-image
services: `orbit-gateway` serves the typed HTTPS API, `orbit-scheduler` runs the
gateway-local Orbit Scheduler, and `orbit-runtime-hibernator` runs development
runtime idle sweeps independently. All three services mount the gateway config
root (`ORBIT_CONFIG_ROOT`) for `.env`, `gateway.sqlite`, and Orbit
CA/certificate material. Workload nodes run the public Orbit CLI as a gateway
client and run workloads in role-specific runtime containers.

The gateway config root and its credential-bearing files are owner-only. Its
directories use mode `0700`; `.env`, `gateway.sqlite`, operations WebSocket app
credentials, and TLS private keys use mode `0600`. Public certificate files may
remain readable at mode `0644`. Gateway startup and convergence repair these
modes on existing paths instead of applying them only when a path is first
created.

When the config root is bind-mounted from the host, container startup must keep
that tree owned by the host Orbit installation user's numeric `uid:gid`. It must
never assign the image-internal `orbit` account, because that uid does not match
the host install user. Ownership comes from the host home view under
`ORBIT_HOST_PATH_PREFIX` (host `/home` mounted at `/mnt/orbit-host/home`), so
the host Orbit CLI can still read config after gateway restarts. When no host
path prefix is present, image-local or development roots may fall back to the
image `orbit` user.

Gateway maintenance in production is containerized: migrations and update work
run through the gateway container entrypoint or durable one-shot runner. Source
development can still use `bin/orbit-gateway-artisan` or direct
`php apps/gateway/artisan` from a controlled checkout for local ergonomics.
The public `orbit` command never dispatches to gateway Artisan. Every public
gateway-backed or remote command uses the typed gateway HTTPS API over
WireGuard. Local-only, pre-grants-bootstrap, and identity-gated self-management
commands follow their documented lanes. Internal executor commands are
dispatched by the gateway and require an operation token before side effects.

The Orbit CLI is a self-contained native binary with PHP 8.5 embedded, built
per OS/arch and downloaded by the installer. The binary embeds PHP 8.5 and the
extensions the CLI requires (`pdo_sqlite`, `openssl`, `curl`, `mbstring`,
`tokenizer`, `ctype`, `filter`, `fileinfo`, `json`, `phar`, `zlib`); no host
PHP is required to run it. Release binaries are wrapped from a compressed PHAR
built from a no-dev CLI dependency install. Orbit releases are versioned from
the monorepo root `VERSION` file, but GitHub publication is a promotion step,
not the build step. Release candidates are built once, exposed through a
topology-reachable `topology-candidate` manifest, activated on a stable artifact
channel such as `channels/live-test/orbit-release-manifest.json`, and proven
with `orbit update:all` before a `v<VERSION>` GitHub release exists. Candidate
assets remain immutable under `candidates/<BUILD_ID>/`; activation only updates
the channel manifest so a live-test gateway can keep one custom manifest URL
selected through `orbit manifest:update`. `orbit manifest:remove` clears that
override after candidate acceptance or rejection. After live acceptance, the
exact tested CLI binaries, digest-pinned
`ghcr.io/hardimpactdev/orbit-gateway:<version>` image, and final
`github-release` manifest are attached to the `hardimpactdev/orbit` release; the
GitHub release workflow verifies those promoted assets and publishes the
`hardimpactdev/orbit-core`, `hardimpactdev/orbit-cli`, and
`hardimpactdev/orbit-gateway` split package repositories without rebuilding the
tested binaries or image. Source-dev Docker and Incus topologies may point
`/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit` and bind-mount or
copy the worktree for fast iteration. Artifact-prod topologies use the native
CLI binary plus production images and validate the actual release artifacts.
Production installs record local CLI install metadata at
`$HOME/.config/orbit/install.json` by default, or `ORBIT_INSTALL_METADATA_PATH`
when set, after the linked binary verifies. The production host launcher is
`$HOME/.local/bin/orbit` by default, or `ORBIT_BIN_PATH` when set. The same
owner-user local model installs Orbit Agent at `$HOME/.local/bin/orbit-agent`
and records update metadata in `$HOME/.config/orbit/install.json`. Extra Unix
users such as the `agent` role user are consumer users, not second Orbit owners;
they use managed shims that execute the owner user's CLI with
`ORBIT_CONFIG_PATH` and `ORBIT_INSTALL_METADATA_PATH` bound to the owner config
read-only. Stale protected launchers such as `/usr/local/bin/orbit` can be
reported or explicitly adopted by deployment/doctor flows, but normal `update`
and `update:all` flows do not mutate them implicitly.
Host PHP is not an app/workspace runtime fallback and must not replace
FrankenPHP app/workspace containers.

## Storage

### Persistent state

The gateway holds Orbit's durable source of truth: a single SQLite database at `ORBIT_CONFIG_ROOT/gateway.sqlite` (default `~/.config/orbit/gateway.sqlite`). Every state family writes here. Non-gateway nodes do not use the gateway SQLite store. The gateway is authoritative for every configuration row, registry record, and history entry.

See [Architecture: State Model](architecture.md#state-model) and [Architecture: State Families](architecture.md#state-families) for the conceptual model. A few implementation notes:

- A configuration row describes a desired physical fact on a node; the node-side artifact is the applied representation of that row.
- Process lifecycle events are stored as durable history, not as a separate process-state table.
- Renderers turn gateway-tracked configuration into the artifacts a node should hold. They must take target-specific inputs from gateway data or explicit probe results, never from gateway-local host state, when rendering for another node.
- Implementation-specific names (`orbit-caddy` sites, UFW rules, Docker container names, systemd unit names, package installs) live in renderer, probe, and migration code. They are not product-level Orbit concepts.

## Infrastructure

### Gateway API

Gateway HTTPS is never a public application vhost. In `router-colocated` mode,
router-owned `orbit-caddy` owns host `tcp/80`, `tcp/443`, and `udp/443`, routes
gateway API traffic to the `orbit-gateway` service alias over the attachable
overlay `orbit-network`, and `orbit-gateway` publishes no host ports. In
`gateway-direct` mode, the `orbit-gateway` service publishes gateway HTTPS
directly. In both modes the gateway leaf certificate chains to the Orbit root
CA, and WireGuard/firewall policy restricts TCP/443 and UDP/443 access to the
Orbit control plane.

In router-colocated mode, the gateway API route is an internal `proxy` entry.
Its proxy and TLS artifact is repaired by `doctor --family=proxy --restore`,
not by a backend-named provisioning command.

The gateway API listener must not trust client-supplied forwarding identity.
CLI, TypeScript SDK, and browser toolbar callers send **no** bearer token and
**no** peer-IP identity header. Caller identity is the authenticated WireGuard
peer: the gateway maps the actual peer source IP (or, only on the private
proxy hop when configured, a proxy-injected header derived from that observed
peer address) to a node, then enforces the grant, the required permission for
the API call, and existing target-node authorization.

Router-owned `orbit-caddy` strips `X-Forwarded-For`, `X-Real-IP`, `Forwarded`,
and any incoming `X-Orbit-WireGuard-Ip` before proxying to Laravel. It may then
inject `X-Orbit-WireGuard-Ip` from the **observed** WireGuard peer address for
the private `orbit-gateway` hop only. Clients never supply that header; browser
CORS admission never establishes identity.

Long-lived stream and log endpoints must not starve short command/API requests.
The Swarm-managed API runtime owns this concurrency contract inside
`orbit-gateway`; it does not use host PHP-FPM sockets and must be validated
through gateway API tests. Server-sent event emitters flush under the
FrankenPHP request SAPI (`frankenphp`) as well as development SAPIs so durable
operation progress remains live during gateway replacement.

The gateway-owned OpenAPI export is the schema source for generated clients.
The durable public-SDK boundary lives beside the gateway application in
`apps/gateway/openapi-sdk-surface.json`: operations are classified as public SDK
surface, internal-only gateway/runtime surface, or deferred optional/admin
surface. Public SDK operations are candidates for PHP SDK request classes and
generated into the TypeScript gateway SDK package at `packages/sdk-typescript`
from the filtered public OpenAPI input. The generated TypeScript package is a
thin `openapi-typescript` plus `openapi-fetch` client surface for macOS/Tauri,
TanStack Query, and browser toolbar callers; it must consume the classified
OpenAPI contract instead of hand-maintaining route definitions. Browser toolbar
callers use `createOrbitGatewayClient` against the existing process list and
lifecycle routes with the same auth model as the CLI (WireGuard peer source IP
plus grants/permissions; no bearer and no client peer-IP header), the `app`
hostname selector, and CORS Origin admission that only matches a registered
origin to the requested `app` and never authenticates the caller.
Internal-only operations, including local executor token
verification, process event ingest, Solo proxy routes, and update artifact
plumbing, must not be emitted as public SDK methods. Deferred optional groups
such as Cloudflare, S3, metrics credentials, extension administration, and
sensitive app env routes require an explicit promotion slice before they enter
a generated public SDK.

The TypeScript package remains consumable outside the monorepo under the exact
npm name `@hardimpactdev/orbit-sdk-typescript`. Canonical source lives at
`packages/sdk-typescript` in `hardimpactdev/orbit` and stays `private=true` in
the monorepo to block accidental npm publish from the monorepo tree. Package
versioning is **independent of monorepo root `VERSION`**: the package’s own
`package.json` version is authoritative (initial public release `0.1.0`).

Local release preparation uses
`bin/orbit-prepare-release-package --package=sdk-typescript` and stamps the
**package.json version** (not root `VERSION`) into prepared `package.json` and
`package-lock.json` identity fields, sets `private=false`, rewrites repository
metadata to the durable generated package repository
`hardimpactdev/orbit-sdk-typescript`, and includes that repository’s
`.github/workflows/publish.yml`. Install trees (`node_modules`) are never
committed. `composer quality-check` still runs package `typecheck` and `build`.

npm publication is **not** performed by monorepo `.github/workflows/orbit-release.yml`
and the monorepo never holds a durable npm publish secret for this package.

Operators push the prepared split tree to `hardimpactdev/orbit-sdk-typescript`.
That repository’s `.github/workflows/publish.yml` has two paths:

1. **One-time bootstrap** (`workflow_dispatch`, exact version input `0.1.0`):
   fail-closed unless `scripts/npm-bootstrap-registry-absent.sh` confirms npm
   `E404` absence for the package and `0.1.0`, a successful lookup refuses as
   already existing, any non-E404 registry/DNS/TLS/rate/auth error stops, and
   the input matches `package.json` `0.1.0`. May use a short-lived
   least-privilege split-repo secret `NPM_BOOTSTRAP_TOKEN` only for that
   create, then the secret/token is deleted/revoked.
2. **Steady-state** (`release: published`): Craft-style OIDC Trusted Publisher
   (Node 24, `id-token: write`, `npm publish --provenance --access public`)
   with **no** token. Configure Trusted Publisher for
   `hardimpactdev/orbit-sdk-typescript` / `publish.yml` after the first package
   exists (npm cannot attach Trusted Publisher before the package name exists).

#### Remote command progress

Long-running CLI-to-gateway commands create durable gateway operations and
follow structured progress over the private operations WebSocket. The gateway
persists each frame before publication and emits Orbit progress events, not
arbitrary stdout:

- `tree` — declares the title and ordered step list before work starts
- `step` — updates one step's status and message
- `complete` — terminates the stream successfully and may carry command data
- `error` — terminates the stream as a command failure

The CLI replays journal gaps by cursor, follows live frames, and renders the
normal Orbit progress tree locally. If the stream closes without a `complete`
or `error` event, the command is treated as failed. This progress stream is
distinct from log streaming and local process-line streaming. Direct SSE
remains exact-marked transitional transport only for commands awaiting their
operations WebSocket port.

### Gateway to node

See [Architecture: Trust And Transport](architecture.md#trust-and-transport)
for the managed execution boundary and the typed Orbit Agent lane.

The gateway-to-node model has two normal managed paths: `gateway-only` for
gateway-owned reads and writes, and `agent-push` for node-local execution on
Agent-eligible Ubuntu, macOS, or Darwin nodes. Active workload roles derive
Agent intent. A roleless non-gateway operator can opt in with `managed`.
Gateway nodes are never Agent targets.

SSH exists only as an initiating-client bootstrap edge. First-gateway bootstrap
uses its separate pre-gateway path; workload `node:new` uses client-local SSH to
install the minimal WireGuard, CLI, and Agent substrate. Production gateway
execution uses Agent push or gateway-local execution, and the gateway never
opens target SSH. A failed Agent dispatch does not select SSH. Break-glass SSH
is a super-admin action outside Orbit commands.

Managed node work uses three boundaries:

- The initiating CLI establishes a workload host's bootstrap substrate by
  observing its OS/architecture and streaming a gateway-authored,
  node-specific bundle through client-local SSH.
- Gateway Laravel, Artisan, and PDO work runs locally inside the
  `orbit-gateway` service or a controlled one-shot gateway image.
- `RemoteLocalExecutor` sends token-gated internal commands to the node Agent.

`RemoteLocalExecutor` invokes the node-local Orbit CLI entry point. On
source-mounted nodes, `/usr/local/bin/orbit` points at
`<source>/apps/cli/orbit`; production installs use the native CLI artifact.
Internal commands verify dedicated-key, argument-bound, single-use operation
tokens through the gateway API. Nodes never store token-signing material. The
verify endpoint normally inherits WireGuard peer identity. A valid token may
establish gateway identity only for gateway self-execution when service-network
NAT hides the peer address. Host PHP is not an app or workspace runtime;
FrankenPHP containers own web execution. See
[Runtime Execution Lanes](execution-lanes.md).

Agent push sends a structured request with `operation_id`, `binary`, `argv`,
`operation_token`, `timeout_seconds`, and `stream`. The gateway authorizes
the caller, resolves grants and target, and constructs argv. The Agent validates
both tokens, enforces its node-local binary allowlist, and starts the binary
without a shell. Completion requests return stdout, stderr, status, and exit
frames. Scoped streams forward raw stdout and stderr chunks.

Gateway operations WebSocket/Reverb is the gateway-role progress plane. The
gateway Swarm stack runs one `orbit-operations-reverb` service from the
`orbit-reverb` image. It has its own operations application config, needs no
Valkey or a database-role node, and is separate from app WebSocket bindings.
Operation producers persist each frame in the durable journal before
publishing it. Subscribers replay gaps by cursor and then follow live frames.
Direct SSE remains exact-marked transitional transport for operation commands
that have not moved.

VPN commands enter the typed gateway HTTPS API. The gateway authorizes the
caller, resolves the active gateway-coupled VPN role, and executes its runtime
work locally inside the gateway boundary. VPN commands never SSH from the
caller or from the gateway to another node.

Workload bootstrap SSH is constructed and executed by the initiating CLI. The
client first performs a gateway resume lookup; Agent-ready pending and completed
bootstraps continue without SSH. Only when target substrate is still required
does the client observe and validate the target platform/architecture before prepare.
The gateway atomically reserves the pending node, WireGuard peer, and bootstrap state and renders the secret
bootstrap bundle, but it does not receive SSH credentials or connect to the
target. WireGuard address allocation is serialized at the gateway and protected
by a database uniqueness constraint. Bootstrap establishes the managed user, WireGuard, and the initial CLI
and Agent artifacts. After the Agent is reachable on its reserved WireGuard
address, host prerequisites and runtime/security baselines converge through
Agent push, including home permissions, hardened WireGuard-only SSH, root-key
removal, sysctl, unattended upgrades, and public SSH denial. Node activation
and bootstrap completion commit atomically. Bootstrap scripts are
non-interactive; prompts finish before side effects. Managed system-path writes
use the bootstrap user's passwordless sudo contract. Target SSH host keys are
client-owned bootstrap evidence, not gateway steady-state doctor state.

The generated [SSH inventory](generated/transitional-ssh-inventory.json)
classifies every concrete SSH consumer. Only `provisioning-ssh` entries are
allowed, and the transitional list must remain empty.

On an Agent-eligible node, a typed command may run user-level work locally and
may invoke the operating-system sudo prompt for a protected step. V1 has no
separate Orbit approval UI or pending/approve flow.

#### Orbit Agent lane and gateway protocol skeleton

Orbit Agent is the node-local executor lane for gateway-owned typed command
envelopes on supported nodes. The gateway remains authoritative for intent,
authorization, release manifests, immutable update plans, operation history,
and activity logs.
The gateway owns a minimal protocol skeleton: structured `binary + argv`
envelopes, authenticated Agent listener delivery over Orbit/WireGuard, scoped
operation tokens, lifecycle reporting, and operation/activity recording. The
local runtime is split across two Rust surfaces: `apps/agent` is the headless
Rust/Axum service binary that loads local agent config, exposes an
authenticated Agent listener plus minimal loopback `/health` and `/status`
endpoints, receives binary argv envelopes, executes only node-local
allowlisted binaries through no-shell process APIs, and reports collected
stdout/stderr/status/exit frames; `apps/macos` is the macOS-only Tauri tray UI
that reads service status and performs one-shot gateway status refreshes. When
no local headless service is already reachable, the macOS UI starts an embedded
`apps/agent` service in the app process; quitting the UI stops that embedded
instance. If a separately installed service is already reachable, the UI uses
that service and does not manage its lifetime.

V1 is scoped narrowly:

- gateway-built `binary + argv` requests only, not arbitrary shell transport;
- gateway-pushed HTTP delivery over Orbit/WireGuard, with no Agent-initiated
  retrieval loop and no WebSocket requirement;
- one-shot gateway/status refresh when the macOS menu opens, showing Connected
  or Disconnected plus node name and gateway name/host;
- menu icon state belongs to the UI process, with UI Restart and Quit actions
  managing only the UI process and any embedded service it started itself;
- no menu job history;
- app-dev convergence uses direct gateway-pushed command envelopes;
- owner-user local Agent installation and updates replace the configured
  artifact and restart an existing managed service; first service creation
  remains bootstrap-owned and updates do not create a missing service;
- native platform installer packaging, signing, and notarization remain
  deferred.

Orbit Agent is distinct from the existing `agent` workload role and from Agent
sessions. Orbit Agent is a node-local execution lane for Orbit operations.
Gateway-pushed commands are limited to Agent-eligible nodes. The `agent` workload role
supplies derived intent like every other workload role; it is not a duplicated
capability flag.

On Linux nodes, the Agent systemd unit is ordered after `network-online` and
restarts until its configured address is bindable. It does not hard-require a
specific `wg-quick` unit name because fleet nodes may expose the Orbit network
through different WireGuard interface names. Bootstrap and fleet updates also
install a retrying boot convergence service ordered after Docker and
`network-online`. It restarts the current Orbit-managed Caddy container and
starts current managed app, workspace, and WebSocket runtime containers only
when their Docker restart policy is `always`. It never revives containers
configured as `never` or `unless-stopped`, so stale and intentionally stopped
runtimes remain stopped.

### Proxy

`orbit-caddy` is the standalone fleet proxy container on every node that needs HTTP routing. It terminates TLS for managed routes, fronts the gateway API only in `router-colocated` mode, serves public ingress routes on `ingress` nodes, serves private router/backend routes, and serves app and workspace routes on nodes with application roles. Orbit-managed route leaf certificates are issued by the Orbit root CA for 397 days, so nodes serve HTTPS without ever holding the root CA private key or any general signing authority.

`orbit-caddy` persists Caddy-generated storage, including public ACME account
and certificate state, through Orbit-owned host paths under
`/var/lib/orbit/caddy`. It must not bind host Caddy service directories under
`/var/lib/caddy` into the managed container.

The canonical `orbit-caddy`, app, workspace, and WebSocket runtime container
specs use Docker's `always` restart policy. The node boot convergence service
provides a second recovery edge after Docker and keeps retrying until the node's
configured addresses are ready, including the case where an early Docker
restart attempt raced a temporary physical-link loss. When Docker leaves
`orbit-caddy` detached after that failure, the service reconnects the container
to its configured Docker network before restarting it. Docker then restores the
published ports retained in the container host config.

#### Caddy include boundaries

Caddy configuration is split by exposure boundary, not by who happens to write the file. The managed config mounted into `orbit-caddy` imports both managed include trees:

- `/etc/caddy/orbit/*.caddy` for Orbit platform surfaces that are internal to the Orbit network
- `/etc/caddy/sites/*.caddy` for app, workspace, and custom proxy site routes

Files under `/etc/caddy/orbit/*.caddy` must be reachable only through the Orbit/WireGuard network or another explicitly internal gateway interface. Gateway API proxy routes belong here only when the gateway is in `router-colocated` mode, where router-owned `orbit-caddy` forwards to `orbit-gateway` over `orbit-network`. In `gateway-direct` mode, `orbit-gateway` publishes gateway HTTPS directly and no gateway API Caddy route is required. Gateway API exposure must not create a broad public virtual host.

Files under `/etc/caddy/sites/*.caddy` are user-facing site routes. Project, instance, workspace, and custom proxy routes write here because they may be served on public or project domains. These files may import shared snippets from the managed `orbit-caddy` Caddyfile, but they must not define Orbit control-plane endpoints.

Installer and doctor repair code must be additive: ensure required imports and managed include files exist in the `orbit-caddy` mount or managed volume, but never replace unrelated site blocks or remove existing imports.

On an `app-dev` instance or workspace route, stock Caddy's file matcher bypasses
the gateway while the scope's node-local awake marker exists. When the marker is
absent, `forward_auth` performs a bounded TLS request to the gateway activation
endpoint using the installed Orbit root CA; the gateway accepts only the exact
serving node's WireGuard identity and does not require a user grant. When the
scope is already awake, the pre-check returns success so the original request
continues. When soft or cold wake work is required, the pre-check starts or
follows one detached activation operation and returns the minimal auto-refreshing
boot page immediately (one indeterminate animated Orbit mark only; no soft/cold
UI distinction or progress bar) until a later request succeeds; soft runners only
fence process activation, while dependency inspection, restoration, readiness,
and cold-marker clearing remain cold-only.
A dedicated JSON access log in `/data/caddy/orbit/hibernation` supplies the
scope's last HTTP activity time. Awake and hibernated markers live under Caddy's
ephemeral `/dev/shm`, so a host or Caddy restart cannot preserve a stale awake
decision. Caddy itself remains persistent, and this contract requires neither a
custom module nor an Orbit-specific Caddy image.

### PHP runtime

PHP app execution runs in FrankenPHP containers. The gateway API runtime runs
in the `orbit-gateway` FrankenPHP image; production installs run the
CLI/local-executor artifact in the native CLI binary's embedded PHP 8.5
(`pdo_sqlite`, `openssl`, `curl`, `mbstring`, `tokenizer`, `ctype`,
`filter`, `fileinfo`, `json`, `phar`, `zlib`).
Source-mounted Docker/Incus development and E2E nodes invoke
`<source>/apps/cli/orbit`. Apps and workspaces run in dedicated long-lived
app/workspace containers when their runtime is PHP. Static or non-PHP
apps do not get a FrankenPHP container.

Orbit's PHP 8.5 host toolchain and app/workspace FrankenPHP image report the
same WAL-reset-safe SQLite release from both `SQLite3::version()` and
`select sqlite_version()` before publication or installation. The PHP 8.5
runtime artifacts pin the official SQLite 3.44.6 safety backport. Other
accepted fixed releases are the 3.50.7 backport and mainline 3.51.3 or newer.

Each PHP workspace gets its own FrankenPHP container so workspaces are isolated
from one another. Production PHP apps get a dedicated FrankenPHP runtime container
as well. The PHP version for an app or workspace is gateway-tracked
configuration; changing it recreates the affected runtime artifact from the
selected PHP image on the owning node through authenticated Agent push over
WireGuard. In production installs the CLI/local-
executor artifact runs in the native CLI binary's embedded PHP; source-mounted
Docker/Incus development and E2E nodes invoke `<source>/apps/cli/orbit`. Host
PHP and PHP-FPM are not app/workspace runtime fallbacks.

Instance and workspace FrankenPHP containers are private backends behind
`orbit-caddy`, not durable Caddy storage owners. Their Caddy/FrankenPHP XDG
homes are container-local and ephemeral: `XDG_CONFIG_HOME` is
`/tmp/orbit-frankenphp/config`, and `XDG_DATA_HOME` is
`/tmp/orbit-frankenphp/data`. Orbit does not mount these paths from the app or
workspace checkout, `~/.config/orbit`, or `/var/lib/orbit`; the only durable
Caddy state in Orbit belongs to `orbit-caddy` under `/var/lib/orbit/caddy`.

On `app-dev` nodes, PHP app and workspace FrankenPHP containers speak plain HTTP
by default (`:8080` for app containers and the FrankenPHP default HTTP listener
for workspace containers). An app may opt into inner HTTPS for its FrankenPHP
runtime by setting `runtime_config.proxy_transport=https` through the app
creation or registration surface. When enabled, Orbit mounts the same
Orbit-issued site certificate and key the public route already uses into the
runtime at `/etc/orbit/runtime-tls/tls.crt` and
`/etc/orbit/runtime-tls/tls.key`, sets `SERVER_NAME` to
`https://<route-domain>:8443`, and configures FrankenPHP with
`CADDY_SERVER_EXTRA_DIRECTIVES=tls <cert> <key>`. Outer `orbit-caddy`
reverse-proxies to `https://<runtime-alias>:8443` with
`transport http { tls_trust_pool file /etc/orbit/ca/root.crt ; tls_server_name <route-domain> }`
so Laravel sees secure requests without trusting proxies. `app-prod` PHP
runtimes stay plain HTTP backends on port `8080`; static apps and workspaces are
unchanged.

On `app-dev` nodes, PHP app and workspace FrankenPHP containers also mount the
owning node user's conventional packages directory from
`/home/<node-user>/packages` to `/packages`. This dev-only mount lets Composer
path repository symlinks under `/app/vendor` resolve without mounting the host
home directory or changing the FrankenPHP runtime model. Static runtimes and
`app-prod` runtimes do not receive this mount.

On `app-dev` nodes, PHP app and workspace FrankenPHP containers also install
and bind-mount Orbit's runtime trust pool from the node at
`/etc/orbit/ca/root.crt`, set `SSL_CERT_FILE` and `CURL_CA_BUNDLE`, and render
managed `php.ini` directives `openssl.cafile` and `curl.cainfo` to that path.
This gives outbound HTTPS clients inside the runtime container default trust
for Orbit-issued development certificates, such as Vite dev-server and Inertia
SSR endpoints. Orbit also maps the exact app or workspace development hostname
to Docker's host gateway inside these containers, so the trusted HTTPS endpoint
remains reachable when Vite runs as a host process. Neither mapping nor
client-trust configuration is rendered for `app-prod` runtimes.

`app-dev` PHP app and workspace containers render a small native
`FRANKENPHP_CONFIG` snippet in classic mode: `max_threads auto` and
`max_idle_time 1h`. These are FrankenPHP thread-pool settings, not Laravel
Octane worker mode; worker mode stays opt-in per concrete instance through
`instance:worker` after readiness validation on that instance.

PHP apps on `app-dev` nodes may also store instance-scoped additional runtime
mount intent through `instance:mount` with dotted selectors such as `hauser.nmbp`.
These mounts are rendered into the app runtime container for the selected
instance and inherited by workspace runtime containers that use that instance.
Different instances may use different host source paths for the same container
target. Sources must be explicit safe paths under the resolved instance node's home directory,
sensitive home paths are rejected, reserved runtime targets such as `/app`,
`/packages`, `/data`, and `/config` are blocked, the internal ephemeral XDG root
`/tmp/orbit-frankenphp` is blocked, and mounts default to read-only. This keeps
package symlink support configurable without reintroducing PHP-FPM or mounting
the entire host home directory by default.

Production public HTTP traffic enters the fleet through an active
`ingress` role. `app-prod` nodes are production runtime backends:
they own app files, FrankenPHP runtime policy, process-backed runtime units,
and a private `orbit-caddy` listener, but they do not own public route exposure
unless the same node also carries `ingress`.

Each production PHP app runtime is rendered as an isolated per-app Docker
container running FrankenPHP. The container listens on internal HTTP port `8080`,
publishes no public host ports, runs as a path-derived app user, and is reached
only by the app-role backend `orbit-caddy` route. That app user must not be in
the Docker group and must not have the Docker socket mounted into its runtime.
Release-aware deployments keep the app source boundary mounted at `/app`, then
resolve the active `live` symlink inside that boundary and run the container
with `/app/live` as its working directory and Laravel base path. Its server root
is the configured document root below that active release. The container is represented as the
process-owned long-running HTTP runtime unit with Docker runtime; configured
host command processes are systemd-backed on Linux and launchd-backed on macOS
under the process family. A fully baked app-runtime Docker Swarm service
remains a deferred phase.

### WebSocket runtime

The `websocket` role runs Laravel Reverb for fleet realtime traffic. Reverb
runs in the dedicated `orbit-reverb` Docker runtime container managed by Orbit
on the node that carries the websocket role; it is not a host systemd command process
and does not use the gateway or FrankenPHP runtime images. The Reverb runtime
application lives at `apps/reverb/` and is packaged as the
`hardimpact/orbit-reverb` image, where Composer dependencies are installed at
image build time. During role convergence, Orbit resolves the selected release
manifest's `orbit-websocket` image. The current immutable manifest must provide
either an HTTPS image archive with its SHA-256 or a digest-pinned image. The
target verifies and loads the archive without registry credentials or pulls the
digest-pinned image, verifies the self-contained label, and only then updates
the local `orbit-reverb:current` runtime alias. Mutable websocket image
references are rejected. If the manifest endpoint is unreachable, convergence
continues with existing local alias inspection and the development
source-checkout fallback. Source sync to `/opt/orbit/websocket/current` and host
Composer install remain a development source-checkout fallback for
non-self-contained local runtime images; packaged production gateways do not
carry the Reverb source tree.
The long-running service is `php artisan reverb:start` inside the Reverb runtime
container. Reverb listens on `0.0.0.0:8080` inside that isolated container, and
Docker publishes the port only on the node's WireGuard address. The router
targets the backend as `https://<wireguard-ip>:8080`. Backend
certificates and runtime identity use the backend WireGuard IP, not per-node
websocket DNS.

Reverb reads app registrations from `/etc/orbit/websocket/apps.php` on the
host. That file, `/etc/orbit/websocket/app.key`, and the shared `.env` used by
the source fallback contain credentials and use mode `0600`. Convergence
repairs those modes even when the files already exist. The Reverb container
runs as root and receives the files through read-only bind mounts.

The `router` role owns `websocket.orbit`, the websocket backend pool, and
private router-to-websocket TLS verification. Apps publish to
`https://websocket.orbit`. Public clients subscribe through instance-owned public
hosts such as `wss://ws.example.com`; `ingress` terminates public TLS and
forwards those WebSocket routes to `router`, never directly to websocket nodes.

The websocket role requires Valkey-backed scaling configuration from day one.
Its `valkey_node_id` setting points at a node with the `database` role and a
managed Valkey service. The websocket role consumes Valkey; it does not install
or own Valkey. Reverb's upstream configuration still names its compatible
broker connection `REDIS_*`; Orbit supplies the selected Valkey endpoint through
those keys. The default prepared E2E topology colocates `websocket`,
`app-dev`, and `database` on `app-dev-1`, so the websocket Valkey dependency
points back to that same node. Dedicated websocket nodes remain valid when they
are reachable over WireGuard and point their Valkey dependency at a database-role
node.

Current product support is one active websocket backend. Route internals keep a
backend-pool-shaped configuration for future scaling, but multiple active
websocket backends fail clearly instead of silently fanning out.

The gateway-owned operations Reverb service is intentionally outside this
app-facing websocket role. It is colocated with the gateway Swarm services and
does not use `websocket.orbit`, app WebSocket bindings, or the websocket role's
Valkey scaling dependency in v1.

### S3 runtime

The `s3` role runs SeaweedFS for fleet object storage. Role convergence
persists one canonical node-owned Docker process row with `tool=seaweedfs`;
the process renderer owns the runtime container. SeaweedFS is not rendered
from role-local Docker Compose and does not use host package installation as a
fallback. The container uses the `chrislusf/seaweedfs:4.33` image, mounts the
role-owned `data_path` as `/data`, and binds the S3 API only to the node's
WireGuard address on port `8333`. The SeaweedFS console is not publicly exposed
in v1. Orbit accepts only canonical S3 data paths under approved persistent-data
roots, writes the credential-bearing `s3.json` as a mode-`0600` managed file,
and materializes that file before Docker prepares the remaining bind mounts.

The `router` role owns `s3.orbit`, the S3 backend pool, upload-compatible proxy
settings, and private router-to-SeaweedFS routing. Apps and VPN clients use
`https://s3.orbit`. Public S3 clients use operator-published hosts such as
`https://s3.example.com`; `ingress` terminates public TLS and forwards those
hosts to `router`, never directly to S3 nodes.

Router and ingress proxy rendering for S3 must preserve the original `Host`
header and forwarded protocol headers so S3 signatures are validated against
the requested endpoint. S3 routes must allow large uploads and avoid request
buffering that would make object uploads fail before SeaweedFS receives them.

S3 credentials are service-level SeaweedFS credentials stored on the `seaweedfs` tool
row. V1 does not create per-app bucket credentials, bucket lifecycle commands,
virtual-hosted bucket routes, wildcard DNS/TLS for bucket hostnames, distributed
SeaweedFS, or HA guarantees.

Focused S3 E2E coverage lives in the dedicated `apps/e2e` runner. It covers the
private `s3.orbit` route, credentials output, public ingress publication, and
SeaweedFS WireGuard-only bind posture through prepared topologies. New S3 E2E
coverage must keep SeaweedFS on the canonical process runtime substrate and must
not add role-local Docker Compose, host Caddy, host PHP, PHP-FPM, or Supervisor
to make object-storage assertions pass. See [Testing](testing/README.md).

### Analytics runtime

The `analytics` role uses the official Plausible CE 3.2.1 service pairing:
`postgres:16-alpine`, `clickhouse/clickhouse-server:24.12-alpine`, and
`ghcr.io/plausible/community-edition:v3.2.1`. Each is a node-owned Docker
process rather than a Swarm service. Published PostgreSQL, ClickHouse, and
Plausible ports bind directly to the owning node's WireGuard address.

Orbit permits one analytics role assignment fleet-wide. After the Plausible
container is verified, role convergence persists and enacts the router-owned
`analytics.orbit` Caddy route to the node's WireGuard-bound port `8000`, issues
the Orbit-managed leaf certificate, and reloads router Caddy. Removing the role
removes the Caddy site, leaf certificate and key, and gateway route row.

Orbit generates separate PostgreSQL and ClickHouse passwords when their managed
service process rows are created. Process credentials are encrypted at rest and
are injected into the remote Docker specification only during convergence;
they are not stored in `runtime_config`. Plausible receives authenticated
PostgreSQL and ClickHouse URLs plus its generated `SECRET_KEY_BASE` through the
same encrypted process-credential boundary.

### Metrics runtime

The `metrics` role runs Orbit's host-resource observability backend. Prometheus
and Grafana are node-owned service process definitions using the Docker Swarm
runtime on the selected metrics node. node-exporter is a node-owned host binary
tool with a node-owned host command process using systemd on the metrics node
and every active Ubuntu non-gateway role-bearing workload node. The role baseline
creates the expected process rows, Docker
substrate intent, and node-exporter host binary tool intent; start, stop,
restart, logs, and runtime drift remain process-family behavior.

Grafana is exposed only on the private Orbit network through the router-owned
`metrics.orbit` route. The role stores generated Grafana admin credentials in
gateway-owned process runtime configuration and exposes them through
`metrics:credentials`. Grafana is file-provisioned with the Orbit Prometheus
datasource and the built-in `Orbit Node Resources` dashboard. That dashboard
uses the node-exporter `node` label as a selector so operators can view the
metrics node or any active Ubuntu workload node scraped by Prometheus. The first slice
tracks host resources only and does not claim app, container-specific,
database-specific, or dynamic scrape discovery coverage.

The metrics command family coordinates existing state families rather than
creating a `metrics` state family. Node role assignment and readiness belong to
`node`, Docker substrate and node-exporter host binary capabilities belong to
`tool`, Prometheus/Grafana and node-exporter lifecycle belongs to `process`,
and `metrics.orbit` route drift belongs to `proxy`.

### Process manager

Processes are the Orbit lifecycle-managed long-running units. Each process
runtime unit uses its owning node/instance/workspace context, selected
runtime backend, restart policy, and Orbit-managed environment or container
configuration. The supported runtime backends are systemd for Linux host command
process units, launchd for macOS host command process units, Docker for
containerized process units, and Docker Swarm for selected node-owned service
processes.

Systemd-backed units render as host systemd services and surface journald
streams through the process family. Launchd-backed units render as Orbit-owned
user LaunchAgent plists and surface Orbit-owned stdout/stderr log files through
the process family. Docker-backed units render as Orbit-managed containers and
surface Docker log streams through the process family. Tools may supply the
node-level capability a process depends on. The process row remains the runtime
owner; a catalog-declared `tool:start`, `tool:stop`, `tool:restart`, or
`tool:logs` verb may address exactly one process row whose canonical `tool`
value matches the selected tool.

Swarm is a per-artifact production backend, not a node-wide execution mode.
Gateway API, scheduler, and runtime hibernation lifecycles are Swarm services
(`orbit-gateway`, `orbit-scheduler`, and `orbit-runtime-hibernator`). Other
artifacts choose their own backend by product contract: `orbit-caddy`,
app/workspace web runtimes, role services, and Docker-backed process units are
Docker-managed containers or services; configured Linux host command processes
are systemd services, and configured macOS host command processes are launchd
user LaunchAgents.

### Scheduler

The Orbit Scheduler is a long-running PHP loop in the one-replica
`orbit-scheduler` Swarm service using the Orbit gateway image. There is no
scheduler daemon on non-gateway nodes; the gateway dispatches schedule
execution to other nodes through the signed `internal:schedule:run`
local-executor command over agent-push when a schedule's target is not the
gateway itself. Catalog tool scripts use the same agent-push local-executor
pattern through the signed `internal:tool:run-script` command.

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
3. Dispatches the due schedules. Instance schedules resolve their persisted concrete
   instance and execute on that instance's serving node with its path as the
   working directory. Schedules whose target resolves to the gateway run
   locally; schedules targeting any other node run on that node through
   `internal:schedule:run` over agent-push. The scheduled command physically
   executes on the target, but the gateway is orchestrating it.
4. Records the run result — success, failure, exit code, captured output, dispatch failure — in `schedule_runs` immediately as durable gateway history.
5. Releases the lock.

The tick interval is an implementation detail. It may be tightened (for example to evaluate every ten seconds) without changing the schedule expression contract, which remains minute-resolution. Sub-minute work is not a schedule — it belongs in a process runtime unit.

Periodic execution comes from the daemon's internal sleep loop. Docker Swarm
keeps the `orbit-scheduler` service alive; systemd host command processes are
not the scheduler's steady-state runtime.

The daemon's per-tick logic is shared with the `orbit schedule:run` command. The daemon is the steady-state path; `schedule:run` is the on-demand path used for testing, troubleshooting, and recovery.

This centralizes observability: every scheduled run's result lands in the gateway database, including dispatch failures (agent-push unavailable, target down, command non-zero exit). The trade-off is that the gateway is a single point of failure for scheduling — but in v1 the active gateway-coupled `vpn` role is co-located on the same node, so the network is unusable when that node is down regardless.

### Runtime hibernator

The one-replica `orbit-runtime-hibernator` Swarm service uses the gateway image
and shared gateway state, but runs independently from `orbit-scheduler`. It
performs one development-runtime idle sweep, waits ten minutes after that sweep
finishes, and repeats. This elapsed-time loop avoids skipped wall-clock
boundaries and prevents slow node scans from delaying minute-level schedule
evaluation. `ORBIT_RUNTIME_HIBERNATION_SWEEP_INTERVAL_MINUTES` overrides the
default ten-minute interval; the one-hour idle threshold remains controlled by
`ORBIT_RUNTIME_HIBERNATION_IDLE_SECONDS`. The same sweep evaluates the
seven-day cold-dependency threshold, controlled by
`ORBIT_RUNTIME_DEPENDENCY_IDLE_SECONDS`, only for already-hibernated
app-development instance and workspace scopes. Node-local dependency inspection,
pruning, and restoration run through the authenticated local executor so the
gateway never requires a workload source-tree mount. Cold activation uses a
detached gateway runner and the existing operation journal; the stock Caddy
pre-check renders the journal as a small server-generated HTML response until
the original request may proceed. The runner heartbeats that journal around
dependency and process work. Queued runners that do not launch and running
runners that stop heartbeating are replaced after bounded timeouts. An atomic
claim prevents duplicate runners for one operation. A node-and-source-path
cache lock single-flights dependency work across sibling scopes, while a
scope-keyed lock fences process and marker effects; stale takeover requires
both. Dependency waiters use the full bounded fence duration because restore
commands may run for up to 900 seconds; scope effects keep the short wait.
Uncertain prunes and activation failures retain the scope's cold marker for
recovery.

### Service containers

Docker is the baseline substrate for Orbit runtime containers and backing
services. Orbit uses Docker for `orbit-gateway`, `orbit-scheduler`,
`orbit-runtime-hibernator`, `orbit-caddy`, FrankenPHP app/workspace containers,
databases, caches, mail servers, Laravel Reverb containers for the
`websocket` role, SeaweedFS containers for the `s3` role, Prometheus and
Grafana Swarm services for the `metrics` role, and similar backing
infrastructure. Docker E2E topologies use sibling containers through the host
Docker socket, not Docker-in-Docker.

### Agent runtime

Nodes with the `agent` role run the first-party autonomous agent tool Hermes —
that operates Orbit through the gateway API. The agent role
baseline converges `orbit-caddy`, the WireGuard/node identity and trust
material every other Orbit node uses, a single unprivileged shared `agent`
runtime user, and whatever role-specific runtime containers the agent workloads
need. Agent tools never run as the privileged `orbit` maintenance user. The
node identity requires an explicit unique `tld`, as every active node does;
`orbit` is reserved for the proxy-owned `.orbit` namespace.
The `agent` and `app-dev` roles consume that node-owned field for wildcard DNS mappings;
neither role owns it or supplies a default.
The node family always maps `orbit.{tld}` to an active node's WireGuard address
and adds `*.{tld}` plus a local-zone directive only while that node has an
active `app-dev` or `agent` role. Stable private `.orbit` service names are
proxy-family state and distinct from these node records.

Each agent tool is an ordinary entry in the `tool` catalog with category `agent`; there is no separate `agent_tool` state family. Tools are installed through `orbit tool:install`, run through the runtime backend declared by the tool definition, and configured through gateway-tracked tool state. Tool web UIs are exposed by default through tool-owned internal HTTPS proxy routes under the agent TLD (for example `https://hermes.agent`). Tool credentials and web UI tokens are returned only by `tool:credentials` and only when the caller has the explicit `tool:credentials` permission; the agent self-grant does not include that permission. Multiple agent tools may be installed and run on the same node, but Orbit warns at install or start time because node-level activity attribution is weaker when more than one is active. See [Architecture: Node roles](architecture.md#node-roles).

### Network

WireGuard is the VPN. The active `vpn` role owns the WireGuard server runtime:
every other node joins it as a peer with its own identity, and that identity is
what the gateway uses to authenticate API calls. In v1 the active `vpn` role is
gateway-coupled, so the WireGuard server still runs on the same node as the
gateway. There is no separate auth token; the WireGuard handshake is the
credential.

The gateway also acts as the Orbit root certificate authority. It issues TLS certificates for the gateway API and for app/workspace proxy routes, so HTTPS works across the fleet without an external CA.

### Private DNS

Orbit serves private fleet DNS through a `dnsmasq` runtime with three explicit,
non-overlapping configuration artifacts:

- the `dns` tool owns base `dnsmasq.conf`, including upstream resolvers and
  explicit includes for `/etc/dnsmasq.d/10-node-records.conf` and
  `/etc/dnsmasq.d/20-proxy-records.conf`;
- the node family owns `10-node-records.conf`, with a concrete
  `orbit.{node-tld}` record for every active node and wildcard plus local-zone
  directives only for active `app-dev` and `agent` nodes; and
- the proxy family owns `20-proxy-records.conf`, with router/private `.orbit`
  directives and exact backend records, currently including S3 backends.

The node TLD `orbit` is reserved for the proxy-owned `.orbit` namespace.

The shared reconciler atomically replaces each requested artifact under one
lock and reloads or restarts the DNS runtime once; it does not provide an
all-three-file transaction or transfer record ownership between families. The
DNS tool also owns container/service presence, listener readiness, VPN-side
forwarding, and client-DNS settings. The active `vpn` role requires this tool
capability; Orbit has no `dns` role and no DNS state family. Compose places the
DNS runtime in the WireGuard namespace. Swarm keeps DNS and WireGuard as
separate services on a private network and converges forwarding from the
WireGuard namespace to the DNS service.

Node identity create, update, remove, and activation paths use the combined
node-plus-proxy record reconciler. Role add/remove that changes only wildcard
eligibility uses the node-only reconciler. Neither path rewrites base
configuration. Only installation and the monolithic-to-projection layout
migration materialize all three artifacts together.

### Public DNS/CDN

Production domains are managed through Orbit's first-party Cloudflare integration. The gateway calls the Cloudflare API to set up DNS records and to coordinate origin and edge certificates for proxied domains.

Other DNS/CDN providers can be added as extension points without changing core Orbit. The gateway always owns the stored domain configuration and command behavior, even when a provider is plugged in.

### Installation

Production gateway installs run the `orbit-gateway` image pulled or loaded by
digest from the resolved release manifest. That manifest may be a
topology-reachable release candidate during live acceptance or the public GitHub
release manifest after promotion. The Orbit CLI is a prebuilt native binary
downloaded by the installer for production installs. Source-mounted Docker and
Incus topologies are the source-dev development and E2E lanes; artifact-prod
topologies use built CLI binaries and production images.

Client setup is local:

```bash
curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit/main/bin/install-orbit | bash
```

The installer prepares the host before Orbit can run. In production installs it
installs or verifies Docker Engine and CLI, Docker Swarm for gateway-role
nodes, the prebuilt Orbit CLI binary (embedded PHP 8.5 +
`pdo_sqlite`/`openssl`/`curl`/`mbstring`/`tokenizer`/`ctype`/`filter`/`fileinfo`/`json`/`phar`/`zlib`) downloaded and linked as the host `orbit`
launcher target, the digest-pinned `orbit-gateway` image or
`ORBIT_GATEWAY_IMAGE_ARCHIVE` when the node carries the gateway role, the
default FrankenPHP app/workspace runtime image for app-role nodes, the
`orbit-caddy` container where the node role needs HTTP routing, and
WireGuard/SSH identity material. Production gateway-only nodes do not need host
PHP, Composer, Git, or a source checkout. `app-dev` and `app-prod` production
nodes install Orbit-owned static host PHP builds (`php-cli`) and Composer for
app-source workflows. The single `php-cli` slug persists a `coverage` or
`standard` variant: `app-dev` desires coverage PHP with statically linked PCOV
for Pest TIA, while `app-prod` installs standard PHP without PCOV. Until the
fleet-scoped 9-cell matrix is promoted, install/update remain on the
compatibility contract (standard-family published artifacts); doctor classifies
against that effective runtime and only enforces coverage/PCOV after matrix
cutover. Orbit owns fleet-scoped matrix artifacts for PHP 8.3/8.4/8.5:
coverage on linux-x86_64 and macos-aarch64, standard on linux-x86_64 only (no
linux-aarch64, macos-x86_64, or standard macOS production cells), and every
artifact carries the same SQLite safety floor as the PHP 8.5 app/workspace
runtime image. The Laravel installer installs on `app-dev` only.
Production artifact installs link the
host `orbit` launcher at `$HOME/.local/bin/orbit` by default; set
`ORBIT_BIN_PATH` or pass `--bin` to choose another path during explicit install
or adoption. Normal `update` and `update:all` flows refresh the owner-user
local launcher and do not mutate protected system launchers implicitly. In
source-dev Docker and Incus topologies, `/usr/local/bin/orbit` points directly
at `<source>/apps/cli/orbit`, the current checkout is mounted or copied into
the topology, and mutable node-local Orbit state lives under `~/.config/orbit`.
Internal executor commands verify operation tokens through the gateway API, and
nodes do not store executor token signing material. The
installer creates the local SQLite database where appropriate, enables SQLite
WAL/busy-timeout settings for the gateway database, runs migrations through the
gateway image before starting Swarm services, and links `orbit` into the local
executable path. Human output is a quiet step tree by default; pass `--verbose`
only when the underlying package or shell command output is needed for
debugging.

The installer does not create a client identity for the gateway to trust. That identity is minted later — by `node:new --template=gateway` when bootstrapping the first gateway, or by a later node enrollment flow before the client machine runs `gateway:add`.

Nodes are created through `orbit node:new [name]`.

When no gateway is configured yet, use `node:new <gateway-name> --template=gateway --host=<host> --tld=<gateway-tld> --operator-name=<operator-name> --operator-tld=<operator-tld>` to bootstrap one. This command bootstraps the gateway service, creates the client identity that initiated it, installs that identity locally, stores local gateway trust and endpoint configuration, and verifies gateway API access.

When the client already has a WireGuard identity issued by an existing gateway,
use `gateway:add [gateway_ip]` to join it. This command stores the local
gateway API endpoint, the gateway WireGuard IP, and the trust material, installs
local gateway CA trust when missing, and makes that gateway the active endpoint
for subsequent Orbit commands. Pass `--name=<name>` to keep multiple local
gateway entries and use `gateway:use <name>` to switch the active one.

### Platform and roles

The Orbit CLI binary targets macOS arm64 and Ubuntu x86_64. Ubuntu is Orbit's
only supported Linux host platform for roles in v1. The `gateway`, `vpn`,
`router`, `app-prod`, `agent`, `ingress`, `websocket`, `s3`, `metrics`, and
`analytics` role drivers support Ubuntu only; `app-dev` and `database` support
Ubuntu and macOS. macOS role support applies to adopted/self-managed workload
nodes and requires a reachable Docker-compatible container provider: Orbit uses
an already-working Docker provider first and recommends Colima (`brew install
docker colima`, `colima start --runtime docker`) when none is reachable, while
OrbStack and Docker Desktop remain compatible when already installed and
licensed/allowed for the user's context. Managed-host provisioning through
`node:new` templates remains Ubuntu-only. See [Architecture: Node
roles](architecture.md#node-roles) for the driver concept and [Node Concepts:
Role Platform Support](domains/1_node/node-concepts.md#role-platform-support) for
the full matrix.

The CLI has no client-side role awareness. Every public gateway-backed or
remote command uses the typed gateway HTTPS API over WireGuard. Local-only,
pre-grants-bootstrap, and identity-gated self-management commands follow their
documented lanes. For gateway-backed calls, the CLI gathers local context
(current app, workspace, paths), and the gateway authenticates the WireGuard
peer, derives grants from its own node records, and decides what to do.
Gateway-owned reads/writes stay gateway-only. Node-local execution uses Agent
push when the command family needs node-local work. Normal managed execution
never selects SSH, and break-glass access stays outside Orbit.

One machine in the network carries the gateway service. Gateway code runs only
in that runtime and assumes it is the gateway; it does not require a role flag
in the environment. Public gateway-backed CLI calls, including calls made on
the gateway host itself, enter the node-local Orbit CLI entry point and call the
gateway API over the configured WireGuard/orbit-caddy HTTPS endpoint. Production
installs still use the native CLI binary artifact; source-mounted Docker and Incus
development/E2E topologies point `/usr/local/bin/orbit` directly at
`<source>/apps/cli/orbit`. The gateway finds its own node row through the
singleton active `gateway` role assignment in its local registry.

Non-gateway machines hold only gateway rows in their local `nodes` table — the gateways they know how to reach. Initially empty (fresh install), populated by `gateway:add`. There is no self-row on non-gateway machines.

Platform-specific behavior — installing packages, writing config files, controlling services — lives behind handlers and services, so the rest of Orbit doesn't branch on OS.
