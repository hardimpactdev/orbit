# Architecture

This document describes Orbit's architecture at a high level.

## Components

Orbit uses a hub-and-spoke architecture. The gateway is the hub: it is the singleton authority role, owns fleet configuration, serves a typed API, and applies changes on other nodes. Clients and nodes carrying workload roles are spokes. They join the gateway-managed private network for secure communication.

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

Bootstrap exception before Agent readiness:

Initiating client
  -> client-local SSH to the bootstrap target
  -> minimal managed user, WireGuard, CLI, and Agent substrate
  -> gateway-authored convergence through Agent push

Public production HTTP:

Internet
  -> public 80/443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private HTTP/WebSocket/S3 routing, .orbit DNS, and backend pools
  -> private app-prod backend orbit-caddy
  -> per-app FrankenPHP runtime container

Public WebSocket:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private WebSocket route and backend pool
  -> websocket node Reverb runtime container

Public S3:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private S3 route and backend pool
  -> s3 node SeaweedFS runtime container

Private metrics:

VPN client browser
  -> router private `metrics.orbit` route
  -> metrics node Grafana Docker Swarm service
  -> Prometheus Docker Swarm service on the metrics node
  -> node-exporter host binary tool and systemd process on metrics and Ubuntu workload nodes

Private analytics:

VPN client browser
  -> router private `analytics.orbit` route
  -> analytics node Plausible CE runtime container

Public app analytics tracking:

Browser
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router app analytics route and backend pool
  -> analytics node Plausible CE runtime container
```

One hub, one path: there is exactly one place to answer "what should exist?", and exactly one place changes are written. Spokes initiate commands and serve workloads, but durable configuration always lives on the gateway.

### Client

A client is where you drive Orbit from, usually your Mac or Ubuntu workstation.
It runs the host `orbit` launcher, presents a WireGuard identity, and
communicates with the gateway to handle operations. The launcher resolves the
repo root and execs the node-local Orbit CLI entry point; `apps/cli/orbit`
preserves a supplied `ORBIT_HOST_CWD` value or initializes it from `getcwd()`
when absent. Production installs still use the native CLI binary artifact;
source-mounted Docker and Incus development/E2E topologies point
`/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`.
Clients do not write fleet state directly; they call the gateway and let the
gateway do the work.

### Gateway node

The `gateway` role is Orbit's singleton authority. It owns durable Orbit
state, the typed API, root CA material, access policy, and convergence
decisions.

The gateway is the central store of everything Orbit knows: apps, nodes, workspaces, processes, schedules, tools, and firewall rules. It is the source of truth for all of them.

The gateway exposes the typed API that the CLI talks to. The managed execution
model has two normal paths: gateway-owned work stays gateway-only, and
node-local execution goes through Orbit Agent. Workload bootstrap SSH is a
client-to-target edge: the gateway authors the node-specific bundle but never
receives target credentials or opens target SSH. Break-glass SSH is
operator-owned recovery performed by a super admin whose SSH key is present on
the nodes, outside normal Orbit command execution. The gateway remains the
source of truth for intent, authorization, operation history, release manifests,
update plans, and activity logs; the Orbit Agent is the local executor.

The gateway API runs in the Swarm-managed `orbit-gateway` service, using the
first-party `ghcr.io/hardimpactdev/orbit-gateway:<version>` FrankenPHP image.
The image bundles the gateway application code and reads mutable state from the
gateway config root: `ORBIT_CONFIG_ROOT` for `.env`, `gateway.sqlite`, and
Orbit CA/certificate material. The gateway scheduler runs as a separate
one-replica `orbit-scheduler` Swarm service using the same image.

Gateway HTTPS exposure has two modes:

- `router-colocated`: when the gateway node also carries the `router` role,
  router-owned `orbit-caddy` owns host `tcp/80`, `tcp/443`, and `udp/443`.
  `orbit-gateway` binds no host ports; router Caddy reaches it through the
  attachable overlay `orbit-network` by the `orbit-gateway` service alias.
- `gateway-direct`: when the router role lives elsewhere, `orbit-gateway`
  publishes gateway HTTPS directly on the gateway host. The gateway leaf
  certificate still chains to the Orbit root CA. Orbit configures Docker's
  firewall path so only Orbit/WireGuard peers can reach TCP/443 or UDP/443.

Workload nodes run the public Orbit CLI as a gateway client and run workloads
in role-specific runtime containers. Moving the API into Docker does not make
the launcher or any local runtime container a state writer: durable writes still
happen only on the gateway.

### Node roles

A node carries one or more **roles** assigned by the gateway. Roles are fixed code-defined bundles: `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`, `metrics`, and `analytics`. The `gateway` role is the singleton authority role described above.

The `gateway` role also owns the operations WebSocket/Reverb surface for Orbit
operation streams. The gateway persists each progress frame in the operation
journal before publishing it, and subscribers replay gaps by journal cursor
before following live frames. The gateway Swarm stack renders a single
`orbit-operations-reverb` service on the gateway role, using the same
`orbit-reverb` runtime image as the workload websocket role. This service is
separate from app WebSocket bindings and `websocket.orbit`: it has its own
operations app config path, does not depend on Valkey or a database-role node,
and does not move non-stream command APIs off gateway API plus Agent push.
Direct SSE is exact-marked transitional transport and disappears as each
operation-backed command moves to this durable stream.

The `vpn` role is a gateway-coupled infrastructure role in this version. It
owns the WireGuard server runtime, public WireGuard endpoint settings, VPN
peer defaults, and the requirement for the DNS tool capability. First gateway bootstrap assigns
`gateway`, `vpn`, and `router` to the same node, and normal role commands
cannot manage those roles independently.

The other nine are workload roles applied to nodes in the fleet.

`app-dev` uses a local TLD for URLs (`myapp.test`, for example); `app-prod` serves real domains. Staging is a usage pattern of `app-prod`, not a separate role.

Application roles use a per-artifact production substrate. PHP apps and PHP
workspaces run in dedicated FrankenPHP containers represented as process-backed
runtime units. Orbit-defined Linux host command processes run as systemd-backed
process units, while containerized app and workspace runtimes use Docker-backed
process units. Host PHP-FPM is not an app or workspace runtime fallback.
Gateway Laravel/artisan/PDO work runs inside the gateway container or the
durable update runner. Packaged node-local helpers that need host file access
and PHP/PDO use the token-gated local executor lane. See [Runtime Execution
Lanes](execution-lanes.md).

The `websocket` role is a private workload role for Orbit-managed realtime
infrastructure. A websocket node runs Laravel Reverb in a Docker runtime
container managed by Orbit. Reverb listens on the isolated container interface,
while Docker publishes port `8080` only on the node's WireGuard address. The role
receives traffic through router-owned private service routes. Public WebSocket traffic enters
through `ingress`, then flows to `router`, then to the websocket backend pool.
Apps use the stable `websocket.orbit` endpoint and never target a concrete
websocket node. The role depends on a Valkey service selected from a
`database` role node and does not install or own Valkey itself.

The `s3` role is a private workload role for Orbit-managed S3-compatible object
storage. An S3 node runs one SeaweedFS instance in a Docker runtime container
rendered by Orbit, binds its S3 API only to the node's WireGuard address, and
receives traffic through router-owned private service routes. Public S3 traffic
enters through `ingress`, then flows to `router`, then to the S3 backend pool.
In v1 the backend pool contains one SeaweedFS node. Apps and VPN clients use the
stable `s3.orbit` endpoint and never target a concrete S3 node.

The `metrics` role is a private workload role for host-resource observability.
A metrics node records and starts Prometheus and Grafana process runtimes on the
selected metrics host and node-exporter tool/process runtimes on the metrics
host plus every active Ubuntu workload node. Prometheus and Grafana run as Docker Swarm
service processes, while node-exporter is a host binary capability with a
systemd process. The role exposes
Grafana through the router-owned private route `metrics.orbit`. In v1 the
metrics slice tracks host resources only and does not scrape app-, container-,
or database-specific metrics. On Ubuntu, the role can run on a dedicated node
or be co-located with any non-agent role, including the gateway/router node.

The `analytics` role is a private workload role for Orbit-managed Plausible CE
analytics. An analytics node runs Plausible CE as a process-owned Docker
container, publishes it only on the node's WireGuard address, and receives
dashboard and tracking traffic through router-owned private service routes. The private
dashboard/admin endpoint is `analytics.orbit`. App-owned public analytics hosts
such as `analytics.example.com` enter through `ingress`, flow to `router`, and
proxy only Plausible script and event-ingest paths to the analytics backend.
The role depends on one explicitly identified PostgreSQL process and a
ClickHouse Docker service process selected from active `database` role nodes.
The PostgreSQL process identity is stored in the analytics role settings; a
legacy assignment with one candidate remains compatible, while multiple
candidates without a stored identity fail as ambiguous. Those services publish
only on their database nodes' WireGuard addresses and keep generated
credentials in encrypted gateway storage. The database processes may live on
the same node as each other, and may live on the analytics node only when that
node also has the active `database` role.

The fleet supports exactly one analytics role assignment. Analytics role
convergence verifies the Plausible runtime, then creates and enacts the
router-owned `analytics.orbit` route and its Orbit-managed TLS before the
assignment succeeds. Proxy doctor owns route and certificate drift repair.
Removing the analytics role removes both the runtime and the private route with
its rendered artifacts and TLS material.

The `agent` role runs first-party autonomous agent tools — OpenClaw and Hermes — that operate Orbit through the gateway API on the fleet's behalf. The `agent` role is exclusive: it cannot combine with `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `ingress`, `websocket`, `s3`, `metrics`, or `analytics`, and it can only be selected during `node:new`. `node role:add` rejects `agent` because adding it to an existing node bypasses the isolation model the role enforces. A node carrying the `agent` role combines that workload role with explicit scoped grants so the agent can call the gateway like any other caller. Agent tool web UIs are exposed only as internal HTTPS routes under that node's node-owned TLD (for example `https://openclaw.agent` and `https://hermes.agent`); they have no ingress baseline. Activity emitted while autonomous agent tools work is attributed to the node identity — Orbit does not claim per-tool sub-identities.

Roles compose only where the role matrix allows it. In v1, `gateway`, `vpn`,
and `router` are coupled to each other, but the `metrics` role may be added to
that coupled node because it observes host resources and owns no public edge.
`app-dev` may combine with `database`, `websocket`, `s3`, `metrics`, and
`analytics`.
`app-prod` may combine with `ingress` and `metrics`, but conflicts with
`database`, `websocket`, `s3`, and `analytics`. `database`, `websocket`, and
`s3` may combine with `app-dev`, each other, `metrics`, and `analytics`.
`analytics` may combine with `app-dev`, `database`, `websocket`, `s3`, and
`metrics`, but conflicts with gateway-coupled infrastructure, `ingress`,
`app-prod`, and `agent`. `metrics` may combine with every non-agent role. The
`agent` role remains exclusive. The full compatibility matrix lives in
[Node Concepts](domains/1_node/node-concepts.md#role-compatibility).

Each role has a **driver** — the code that knows how to install, configure, and verify that role on a node. A role can only be assigned to a node whose host operating system is supported by that role's driver. New OS support for an existing role is a driver change, not an architecture change. Ubuntu is Orbit's only supported Linux host platform for roles in v1; `app-dev` and `database` also support macOS on adopted/self-managed workload nodes backed by a reachable Docker-compatible container provider. Current driver OS support is enumerated in [Node Concepts: Role Platform Support](domains/1_node/node-concepts.md#role-platform-support).

Nodes other than the gateway do not own durable Orbit state and do not run a
local control plane. The Orbit CLI can run on any node. Every public
gateway-backed or remote command uses the typed gateway HTTPS API over
WireGuard. Local-only, pre-grants-bootstrap, and identity-gated self-management
commands follow their documented lanes.

### VPN

The VPN is the secure network every Orbit node joins. Steady-state traffic
flows over it: CLI calls to the gateway, changes the gateway pushes to other
nodes, and events those nodes send back. The `vpn` role owns the WireGuard
server runtime, the public endpoint settings peers use to reach it, peer
defaults, and its dependency on the DNS tool capability. In v1 that role is gateway-coupled,
so the active `vpn` role runs on the same node as the active `gateway` role.
Nodes with only `app-dev`, `database`, `websocket`, `s3`, `metrics`, `analytics`, or private
`app-prod` roles do not need a public face. Only nodes with an active
`ingress` role expose public production HTTP/HTTPS. SSH and the Orbit API stay
reachable only over the VPN. The current VPN implementation is WireGuard; see
[tech-stack.md](tech-stack.md).

### DNS responsibilities

Orbit splits DNS responsibility across distinct owner concerns. These do not
overlap.

| Concern | Owner | Verified by |
|---|---|---|
| `dnsmasq.d/10-node-records.conf`: one concrete `orbit.{tld}` record for every active node, plus wildcard and local-zone directives only for active `app-dev` and `agent` nodes | node family; node TLD `orbit` is reserved for the proxy namespace | `doctor --family=node` (`node.dns_mapping_mismatch`) |
| `dnsmasq.d/20-proxy-records.conf`: router/private `.orbit` directives and exact backend records, currently including S3 backends | proxy family | `doctor --family=proxy` (`proxy.dns_mapping_mismatch`) |
| Base `dnsmasq.conf`, DNS container/service, listener, VPN forwarding, and client-DNS settings | `dns` tool capability | `doctor --family=tool` (`tool.dns_base_config_mismatch` and granular DNS runtime issues) |
| Caller-local resolver overrides on an operator's own machine | `dns:*` command family | — |
| Public DNS / CDN for production domains | Cloudflare integration | `cf-*` command family |

The shared DNS materializer and reload path atomically replaces each requested
artifact under one lock and reloads once; it is not an all-three-file
transaction. It remains ownership-neutral. A family restore rewrites only that
family's artifact before using the shared reload path. The `dns:*` command
family does not edit gateway-private records, and the tool family does not own
the record projections. There is no `dns` role or DNS state family; the active
`vpn` role requires the `dns` tool capability.

Node identity create, update, remove, and activation paths reconcile the node
and proxy record projections together because a node or WireGuard-address
change can affect both. Role add/remove that changes only `app-dev` or `agent`
wildcard eligibility reconciles the node projection alone. Neither path touches
tool-owned base configuration. Full three-artifact staging and
`migrateLegacyLayout()` are reserved for installation and explicit layout
migration.

`wg-easy` and `orbit-dns` are a coupled VPN substrate. In the current Compose
runtime, `orbit-dns` runs in the wg-easy network namespace so VPN clients can
resolve node TLDs and private Orbit names. The Swarm migration keeps them as
separate Swarm-managed services, not one multi-process container: coupling is a
placement and networking contract. In v1 the gateway-coupled `router` and
`vpn` roles use the DNS tool runtime on the gateway edge node. The Swarm target gives `wg-easy` and
`orbit-dns` a shared private Swarm network, keeps DNS unpublished publicly, and
forwards VPN-side DNS traffic from the WireGuard namespace to the DNS service.
The VPN task self-converges that forwarding rule after task recreation, and
tool doctor verifies the rule explicitly for Swarm DNS runtimes.

### CLI

The CLI is the product surface for humans, AI agents, and CI. Production
installs use the native CLI binary artifact. Source-mounted Docker and Incus
topologies are development and E2E lanes; in those lanes, `/usr/local/bin/orbit`
points directly at `<source>/apps/cli/orbit`. The gateway API runs in the
Swarm-managed `orbit-gateway` service and the scheduler runs in the
`orbit-scheduler` service, but the public `orbit` command never dispatches to
gateway Artisan. Gateway maintenance (migrate, tinker, scheduler, queue,
internal bake/build/install commands) uses the gateway container entrypoint or
durable one-shot runner in production; source-development shells may use
`bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a
controlled checkout.

Public operator commands are owned by the `apps/cli` application. Gateway
Artisan is for gateway maintenance and internal automation only — database
migrations, the scheduler and queue runtime, `orbit:internal:*` provisioning
helpers, and the E2E/provisioning harness — and is not a public Orbit command
target. When a public command moves to `apps/cli`, its gateway command class is
removed and no gateway alias remains.

Gateway-backed CLI calls reach the gateway over HTTPS via the WireGuard tunnel.
Gateway hosts calling their own API also use HTTPS over the gateway's own
WireGuard address, with the gateway's CA PEM trusted from
`~/.config/orbit/gateways/<name>/`. There is no privileged local loopback
bypass.

Public gateway-backed commands gather local input, call the gateway typed API,
and render output. Commands that return structured data expose `--json`.

## Relationships

### Trust and transport

Current Orbit has three implemented network edges.

| Edge | Transport | Purpose |
|---|---|---|
| CLI caller → gateway | HTTPS over the VPN | Commands, reads, streaming progress |
| Initiating client → bootstrap target | Client-local SSH | Observe the target and install only the managed user, WireGuard, CLI, and Agent substrate before Agent readiness |
| Gateway → node | Authenticated Agent HTTP over WireGuard | Structured node-local `binary + argv` dispatch after bootstrap; gateway-owned work remains local and does not cross this edge |

The gateway owns the first Orbit Agent protocol skeleton and the monorepo now
contains two local Rust surfaces: `apps/agent` is the headless Axum service
binary and `apps/macos` is the macOS-only Tauri tray UI. The lane does not add
a node-side control plane: for reachable Agent-eligible nodes, the gateway opens
an authenticated HTTP connection to the node's Agent listener over the
Orbit/WireGuard network, sends a typed Orbit command envelope with a scoped
operation token, and receives stdout/stderr/status/exit frames back. The gateway
does not have a target-SSH path or SSH recovery transport.
Reachable Agent nodes use gateway push only; the gateway is the
sole initiator and the Agent runs no background retrieval loop. Agent command
delivery itself does not use WebSockets; durable long-operation progress uses
the separate gateway Operations WebSocket/Reverb plane.

The HTTPS choice for the caller→gateway edge is intentional. A CLI caller talks to the gateway over a typed API; it does not need shell access to any node. That limits what every caller can do to what Orbit explicitly exposes: no arbitrary shell commands, no SSH key sprawl, no hand-tuning a production host.

The blast radius of any single caller, including an AI agent driving Orbit, is bounded by the API surface. If a caller needs to be cut off — a runaway agent, a compromised laptop, a former contributor — revoking its VPN access shuts down everything it could do, immediately.

CLI callers can run on any node — a client, the gateway, or a node carrying workload roles. The caller location changes how local context (current app, current workspace) is resolved. The convenience wrapper only resolves the repo root and execs the CLI source entrypoint; the source entrypoint initializes `ORBIT_HOST_CWD` when absent and preserves supplied values so current-directory ergonomics survive dispatch without broad host access. Caller location never changes who writes state — that is always the gateway.

Nodes other than the gateway do not accept Orbit API calls from other nodes.
They run workloads, not orchestration. When node-local execution is needed, the
managed steady state is an authenticated gateway-pushed command envelope over
the Orbit/WireGuard network to the node-local Orbit Agent. This is a narrow
Agent listener endpoint, not general inbound Orbit RPC: the gateway sends
structured `binary + argv` requests and the Agent executes only allowlisted
node-local binaries with scoped operation tokens. A command's stable contract
fixes its execution lane, and public commands expose no transport selector.

Break-glass SSH is outside normal Orbit command execution. It is operator-owned
recovery performed by a super admin who has an SSH key installed on all nodes;
Orbit command selection should not depend on that access as a managed
transport.

Orbit Agent is reserved as a node-local execution lane. It is not a new control
plane or arbitrary shell transport. The gateway owns structured binary argv
envelopes, a hidden `orbit version --json` proof envelope, authenticated Agent
listener delivery, scoped operation tokens, lifecycle reporting, and
operation/activity recording.

The local Orbit Agent service lives in `apps/agent` as a headless Rust/Axum
binary. It loads local config, exposes an authenticated Agent listener reachable
from the gateway over Orbit/WireGuard plus minimal loopback `/health` and
`/status` endpoints, receives gateway-authorized `binary + argv` requests, and
reports collected stdout, stderr, status, and exit frames. The gateway builds
the argv and owns caller authorization, grants, node targeting, operation
history, and activity history. The Agent keeps a final node-local binary
allowlist, starting with `orbit`, and executes with no-shell process APIs
rather than shell strings or `sh -c`. The background service loop belongs to
this service binary.

Agent intent comes from exactly two sources: an active workload role, whose
convergence installs the Agent, or the explicit `managed` opt-in on a roleless
operator node. Eligibility is derived from that intent, a supported platform,
a non-gateway identity, and a configured WireGuard address. The command
listener binds only to that WireGuard address. `/health` and `/status` use a
separate loopback-only listener. A gateway is never an Agent-push target, and
wildcard or non-WireGuard command binds fail closed.

Orbit installs and updates the Agent artifact in the configured owner user's
local installation and restarts its managed service when that service already
exists. Bootstrap owns first service creation; `update` and `update:all` replace
and restart an existing service but do not create a missing one. Native platform
installer packaging, signing, and notarization remain separate deferred work.
Agent-push requests are structured Orbit CLI invocations submitted by the
gateway. App-dev convergence is sent as a direct gateway-pushed command
envelope; `node role:add` does not create an Agent work item because
workload-role convergence sends the envelope directly.

The macOS menu-bar surface lives in `apps/macos` and is intentionally minimal:
it can show service/gateway status, refresh status on menu open or Refresh, and
offer UI-level Restart and Quit actions. When no local Agent service is
reachable, the macOS UI starts an embedded `apps/agent` service inside the app
process and quitting the UI stops that embedded instance. If an external
service is already reachable, the UI uses it without managing that service's
lifetime. Execution history belongs in gateway operation/activity history.

`RemoteLocalExecutor` is the gateway-dispatched lane for packaged node-local
helper logic that needs host file access plus PHP/PDO without relying on ad hoc
`python3` or `sqlite3` snippets. The gateway still owns authority. The
authority path is:

`CLI caller -> gateway API -> gateway authorization -> operation record -> agent-push to node -> token-gated local executor -> result recorded`

Commands fail clearly when Agent push is unavailable rather than selecting SSH.
The authority path still starts and ends at the gateway.

Node-local CLI execution is never an authority bypass. Internal local executor
commands are hidden from normal CLI help, require a gateway-issued operation
token, and must fail before side effects when invoked directly without a valid
token. In source-mounted nodes, node-local mutable Orbit state lives under
`~/.config/orbit`.

Gateway operation tokens are minted by the gateway-side operation token
factory, using the gateway Laravel `APP_KEY` and the configured
`ORBIT_OPERATION_TOKEN_TTL_SECONDS` value. The default TTL is 120 seconds. Each
token carries the operation id, target node, internal command name, issued
timestamp, expiry timestamp, and signature. Internal executor commands verify
operation tokens through the gateway API before side effects. Missing `APP_KEY`
configuration prevents minting; token minting is stateless and uses the
existing operation id rather than creating operation persistence itself. Nodes
do not store executor token signing material or target-node identity env values;
the gateway derives the node from the authenticated WireGuard API request when
verifying the token target.

### Authentication and authorization

Every remote action against another node or gateway-owned state needs a
WireGuard identity and gateway-owned authorization. Local-only commands do not
enter that path.

**Identity** comes from the VPN. Every node joins the VPN with its own credentials. The gateway knows which node is on the other end of every API call.

**Authorization** is controlled by the gateway. Stored grants are the default
gate, not a built-in caller role. For each node, the gateway stores which other
nodes are allowed to manage it. The same applies to gateway-owned data.

Authorization is two gates: a grant edge between a consuming node and a serving node decides whether the consuming node can reach the serving node at all, and the scoped permission set stored on that grant decides what the consuming node may do once it does. A grant with no permissions denies every action. Permissions are normalized permission strings such as `node:read`, `tool:update`, `firewall_rule:read`, or `doctor:verify`; wildcards `node:*` and `*` are dynamic and include future permissions added in that namespace. Self-grants are explicit and required — a node does not implicitly have access to itself. A grant from a node to the gateway with `*` (the `gateway-admin` preset) is the fleet-wide super-admin grant: it covers every current node and every node added in the future. `node:new` itself requires a gateway-admin grant on the calling node, or an explicit `node:new` permission on its grant to the gateway. First-gateway bootstrap materializes that initial gateway-admin grant from the initiating operator node to the new gateway.

Any node with a gateway-known identity and the required grants can act through
that identity-and-grants path. All nodes are clients of the Orbit network when
they call the gateway. An operator is a node identity with the operator
permission preset and grants to operate one or more nodes through the gateway.
`operator` is not a workload role.

#### Authorization classes

Orbit names the classes that authorize a command instead of describing every
surface as grants-only:

- **Default grants gate:** a stored grant edge and its scoped permissions
  authorize the action. Revocation removes the grant or permission.
- **Gateway implicit authority:** a node carrying the `gateway` role has
  implicit authority for every permission against every other node because the
  singleton policy owner must converge the fleet. It creates no grant row and
  is revoked by removing the gateway role.
- **Pre-grants bootstrap:** first-gateway `node:new` and `gateway:add` establish
  the identity and initial grant material needed by later calls.
- **Local-only:** commands such as `profile` touch only caller-local state or a
  caller-reachable URL and make no gateway authorization request.
- **Identity-gated self-management:** a known roleless operator identity may
  manage its own approved node fields, including the explicit `managed` Agent
  opt-in, without inventing an operator workload role. Disabling its peer
  revokes that identity.

All remote classes remain gateway-audited. `NodeAccessAuthorizer::allows()`
implements the default grants gate and gateway implicit authority; individual
bootstrap and self-management controllers enforce their narrower predicates.

This grant model lets you scope access naturally:

- A developer's client might have a `developer` preset to nodes with the `app-dev` role and no grant at all to nodes with the `app-prod` role.
- A CI runner's client might have an `operator` preset only to the apps it deploys.
- A node's self-grant gives its own local CLI the actions it needs on itself — for example, a node with the `agent` role has a self-grant that includes `tool:read` and `tool:update:agent-tools` but excludes `tool:credentials`, `tool:install`, `tool:start`, `tool:stop`, `tool:restart`, firewall writes, and node role mutation. Nodes with `app-dev` or `app-prod` roles can read only their own project and instance registry rows through `project:read` and `instance:read`. An `app-dev` node can also register instances on itself, manage process definitions for concrete instances served by itself, and operate app-dev workspaces. `app-prod` self-grants remain read-only and never include wildcard or `workspace:*` permissions. These self-grants do not grant project or instance writes, credentials, deploy, runtime lifecycle process start/stop/restart, or cross-node project, instance, or process visibility.

Workspace permission policy applies to both endpoints of every grant. A
permission set containing `*` or `workspace:*` is rejected when its consuming
node has `app-prod`, or when its serving node has `app-prod`.
Request authorization repeats the consuming-node check so legacy grant drift
cannot let a production app service operate another node's workspace.

Authority is revocable through the lever that owns its class: remove a grant or
permission, remove the gateway role, or disable the peer. `node:grant` creates
the initial grant edge and permissions; long-term editing belongs to
`node:permissions`, which is itself a gateway-admin-only surface.

#### Self-grants and self-serving

A self-grant is a grant where the consuming node and the serving node are the
same node. It is the default way a workload node accesses itself. The named
identity-gated self-management class is limited to approved roleless operator
node fields and does not grant general workload permissions. Self-grants are
created during `node:new` from each workload role's self preset.

Self-targeting commands flow through the gateway like any other command. When a CLI on node `N` calls a command targeting `N`, the path is:

`N → gateway (HTTPS over WireGuard) → gateway authorizes the self-grant → gateway dispatches to N through the available node execution lane`

Node-side state is never written by the public local CLI. The gateway is the
only authority, even when the gateway dispatches token-gated local executor
work back to the same node.

This is why `workspace:setup` works for app-dev workspaces placed on the
self-granted app-dev node, why `project:list` includes projects with at least
one instance on that node, and why `project:show` can inspect apps served there.
Production app nodes never create, own, set up, remove, diagnose, or execute
workspaces. It is also why
`instance:register`, `process:add`, `process:update`, and `process:remove` work from
inside an `app-dev` node for instance-owned state on that same node. The node's
self-grant includes the necessary scoped permissions; this is the self-grant
model, not an exception.

A roleless operator node cannot run workload commands by default. It becomes an
Agent execution target only through its explicit `managed` opt-in on a
supported platform; otherwise the gateway has no node-local lane to dispatch.

### Command and API model

Orbit commands are the stable contract. Each one has documented inputs, outputs, JSON shape, and failure modes — the same surface humans, AI agents, and CI all depend on.

The CLI is what you call through the host launcher. For gateway-backed and
remote commands, the CLI gathers input, calls the typed gateway HTTPS API over
WireGuard, and renders the result. Local-only, pre-grants-bootstrap, and
identity-gated self-management commands use their documented command lanes.

Command contracts live under [docs/domains/](domains/), one folder per family.

#### Canonical JSON envelope

Every gateway typed API response and every CLI `--json` output uses one of two envelopes.

Success:

```json
{"success":{"data":{"example":true},"meta":{"request_id":"abc"}}}
```

Failure:

```json
{"error":{"code":"validation_failed","message":"Invalid input.","meta":{"field":"name"}}}
```

The `success.data` key carries the typed payload; `success.meta` carries non-payload context (request id, pagination, profile). The `error.code` is a machine-stable identifier; `error.message` is a human-readable string; `error.meta` carries structured error context (validation fields, missing permissions, etc.).

CLI rendering: when a gateway-backed command receives a `success` envelope from the gateway, the CLI passes it through verbatim. The CLI's `renderSuccess` helper unwraps `success.data` and `success.meta` into the helper's `data`/`meta` arguments rather than nesting; the CLI never emits `success.success`. Local-only and bootstrap commands construct the envelope themselves through the same helpers.

Direct API consumers — including Solo orchestration agents, Codex/loop roles, and custom scripts — depend on this shape. Breaking changes happen at a coordinated release boundary, named in the release notes for that cycle.

## State

### State model

The gateway database is Orbit's source of truth. It stores four kinds of records:

- **Registry** — what exists (nodes, apps).
- **Configuration** — how things should be set up (processes, schedules, proxy routes, tools, firewall rules).
- **Policy** — repeatable workflows (deployment step definitions).
- **History** — what happened (deployment runs, activity logs).

For standing configuration, a database row is not a cache. It describes a desired physical fact on a node — a FrankenPHP app process that should exist, a proxy route that should resolve, a systemd-backed or Docker-backed process unit that should be running. The node-side artifact is the *applied* representation of that row.

The core invariant:

> Gateway configuration must converge with node reality.

When the two diverge, one of these happened: an apply step failed or only partially completed, someone manually changed the node, a migration changed configuration without reconciling artifacts, or a restored gateway database differs from the fleet.

### State families

A **state family** is one type of thing Orbit tracks — like apps, processes, or schedules. For each one, the gateway stores how it should be set up, and applies that to the right node.

Orbit has nine state families:

| Family | Owns | Concept doc |
|---|---|---|
| `node` | Which nodes exist, their role assignments, VPN identity, SSH access | [Node Concepts](domains/1_node/node-concepts.md) |
| `app` | App config, runtime policy, deploy steps, app health | [App Concepts](domains/5_project/project-concepts.md) |
| `workspace` | Workspace config, URL, runtime policy, setup/teardown policy | [Workspace Concepts](domains/6_workspace/workspace-concepts.md) |
| `process` | Lifecycle-managed long-running units scoped to nodes, apps, or workspaces | [Process Concepts](domains/7_process/process-concepts.md) |
| `proxy` | Every HTTP/HTTPS route Orbit serves | [Proxy Concepts](domains/8_proxy/proxy-concepts.md) |
| `schedule` | Recurring tasks for apps, nodes, and Orbit | [Schedule Concepts](domains/9_schedule/schedule-concepts.md) |
| `tool` | Node-level capabilities installed on each node | [Tool Concepts](domains/3_tool/tool-concepts.md) |
| `firewall_rule` | What network traffic each node allows | [Firewall Concepts](domains/4_firewall/firewall-concepts.md) |
| `database_connection` | Reusable database connection intent mapped into app and workspace `.env` files | [Database Concepts](domains/18_database/database-concepts.md) |

Security is not a tenth state family. Security findings are sections inside the
family that owns the protected state: host security under `node.security.*`,
production runtime hardening under `app.security.*`, development workspace
isolation under `workspace.security.*`, and firewall-owned representation drift
under `firewall_rule.security.*` when needed. `doctor --family=security` is not
accepted.

These names are how Orbit thinks about each thing. The tools behind them — `orbit-caddy` for proxy routes, UFW for firewall rules, Docker for containerized process units, and systemd for Linux host command process units — are implementation choices. The family names stay stable even when the backend changes. See [tech-stack.md](tech-stack.md) for the backends in use today.

### Keeping nodes in sync

Reality drifts. The gateway tracks configuration; a node is meant to match it; over time those can fall apart. **Drift** can be a config mismatch (a proxy route is missing on the node, a process definition has changed), a pending update (security patches the node hasn't installed), or a runtime problem (an app that should be responding isn't).

`orbit doctor` is how you catch and resolve all of those. It runs across a single family, a single node, or the whole fleet, and reports everything that isn't in the expected state.

Doctor has four modes. Without any flag it only reports. The other three modes are selected by mutually-exclusive flags:

| Mode | Flag | Meaning |
|---|---|---|
| Verify | *(none)* | Default. Compare gateway configuration and node reality; report only. |
| Interactive | `--fix` | Prompt per drifted item: restore, adopt, skip, or view details. |
| Restore | `--restore` | Force-restore non-interactively. The gateway is right; re-apply gateway configuration on every drifted item. |
| Adopt | `--adopt` | Force-adopt non-interactively. The node is right; record observed node reality into gateway configuration for every drifted item. |

The mode names are also used by the doctor permission registry: `doctor:verify`, `doctor:restore`, and `doctor:adopt` are the permission strings that gate access to each mode.

Restore is the common case: you fix a node by pushing the gateway's version of the world back onto it. Adopt is the recovery case — a manual host setup, a migration, a disaster recovery — where the node holds the right answer and the gateway needs to learn it.

Doctor is safe to run often, and safe to scope. Running it after every deploy and on a daily schedule is the simplest way to catch problems early.

## Boundaries

Orbit's extension points and identity rules keep product concepts stable while implementations can change underneath them.

### Agent IDE integration

AI agents that work on apps typically run inside an agent IDE — PolyScope, OpenCode, or similar — on a developer's machine. Orbit can integrate with those IDEs so that the agent has a smooth experience: opening a workspace by name, getting notified when a process crashes, receiving messages from the gateway when something needs the agent's attention.

The agent IDE adapter is configured per node, with optional override per app. When something happens that the active agent should know about — a crash, a deploy failure, a doctor finding — Orbit resolves the effective adapter for the app or workspace and sends the message through. If no session is active, the event is still recorded; nothing is lost.

Agent IDE adapters are extension points. New IDEs can be supported by writing an adapter without touching the rest of Orbit.

This integration is for human-driven coding sessions. Autonomous agents that operate the fleet on their own — OpenClaw, Hermes — run as ordinary managed tools under the `agent` role instead. There is no default agent tool: a node with the `agent` role may be created with zero, one, or several agent tools selected. Running multiple agent tools on the same node is allowed, but Orbit warns about weaker node-level traceability whenever a second running agent tool is started or installed — interactive callers see a confirmation prompt, machine-readable callers receive a structured warning under `success.meta.warnings[]` and the command proceeds when input is otherwise valid.

### Identity names

Apps, workspaces, processes, and nodes are identified by **slugs** — short, lowercase, URL-safe names that drive paths, hostnames, file names, and database keys. A future presentation label may add spaces or capitalization, but the slug stays canonical.

A slug must match:

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Length limits:

- project slug: up to 40 characters
- node slug: up to 63 characters
- workspace slug: up to 63 characters (independent of the parent project slug)
- process slug: up to 64 characters

**Workspace hostnames** prepend the workspace slug to the parent project's hostname. For a development project, that's `{workspace}.{project}.{tld}`.

**Process names** combine the app, workspace, and process slugs into a single identifier:

```text
orbit_<project>_<instance>_<workspace|main>_<process>
```

Examples:

```text
orbit_docs_main_vite
orbit_docs_feature-docs_vite
```

`orbit_` marks the name as Orbit-owned. `_` separates segments and is not allowed inside a slug.

### Next

For backend implementations — WireGuard, `orbit-caddy`, Docker runtime
containers, the SQLite schema, client bootstrap bundles, and the Orbit Agent
push lane — see [tech-stack.md](tech-stack.md).
Command contracts live under [docs/domains/](domains/).
