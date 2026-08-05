# Node Commands

Nodes are Orbit's foundation. The first machine a new user meets Orbit on is usually a
client: the machine where the CLI is installed, prompts are answered, and
commands are run.

From there, nodes define the fleet, the role assignments of each machine, which
platforms are supported, how the gateway reaches nodes, and which consuming nodes may
operate on which serving nodes.

Node commands are not app runtime commands. They establish where Orbit may run
work and who may ask for that work. Apps, workspaces, processes, tools,
database connections, firewall rules, proxy routes, schedules, and deployments
build on top of the node model.

The stable node-family vocabulary is defined in
[`node-concepts.md`](node-concepts.md). The node-family drift, restore, and adopt
contract is defined in [`node-doctor.md`](node-doctor.md). Implementation-shape
details for runtime roles, transport edges, and gateway-to-node applying
live in [tech-stack.md](../../tech-stack.md#platform-and-roles) and
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node).

## Role Model

Orbit distinguishes these concepts:

- **Gateway role:** the singleton authority role. It owns durable Orbit state,
  the typed API, certificate authority material, grants, and doctor
  convergence. A gateway role assignment is stored in the role assignment model
  but normal role commands cannot add, update, or remove it independently.
- **VPN role:** the gateway-coupled infrastructure role. It owns the WireGuard
  server runtime, public WireGuard endpoint settings, VPN peer defaults, and
  the requirement for the DNS tool capability. In v1, first gateway bootstrap assigns `gateway`,
  `vpn`, and `router` together, and normal role commands cannot add, update, or
  remove those roles independently.
- **Router role:** the gateway-coupled private routing role. It owns private
  `.orbit` DNS/service names, private route artifacts, backend pools, and
  private HTTP/WebSocket/S3 routing.
- **Node roles:** composable roles that prepare a node to serve a kind of
  workload. Workload roles are `app-dev`, `app-prod`, `database`, `agent`,
  `ingress`, `websocket`, `s3`, `metrics`, and `analytics`.
  `agent` is exclusive during `node:new`; `node role:add` rejects it.

  `websocket` is a private workload role for Laravel Reverb; its isolated
  container listener is published only on WireGuard and receives traffic
  through router-owned private service routes.
  `s3` is a private workload role for SeaweedFS object storage; it binds only to
  WireGuard and receives traffic through router-owned S3 service routes.

  `metrics` is an optional host-resource observability role; it records and
  starts Prometheus and Grafana process runtimes on the metrics node, records
  and starts node-exporter tool/process runtimes on metrics and active Ubuntu workload
  nodes, and exposes Grafana through `metrics.orbit`.
  `analytics` is a private workload role for Plausible CE; it binds only to
  WireGuard and receives dashboard plus tracking traffic through router-owned
  analytics service routes.
- **Client identity:** a CLI installation that has gateway configuration
  and a gateway-issued WireGuard identity. A client may have no workload role
  assignments. It can request self-scoped actions and can operate other nodes only
  through explicit gateway grants.

Roles are code-defined bundles, not open-ended labels. Orbit stores role
assignments only; capabilities are derived internally from the active
assignments. Supported platforms are tracked in
[`node-concepts.md#role-platform-support`](node-concepts.md#role-platform-support).

Nodes may run the Orbit CLI as a stateless gateway client through the host
`orbit` launcher. Production installs use the native CLI binary artifact;
source-mounted Docker and Incus development/E2E topologies point
`/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`.

The CLI entry point owns four command types:

- Public gateway-backed commands call the gateway typed API over the VPN.
- Commands that are local-only write state on the caller's machine, such as `~/.config/orbit/config.json`.
- Bootstrap commands run before a gateway API exists.
- Hidden `internal:*` executor commands are gated by operation tokens.

Gateway maintenance uses `bin/orbit-gateway-artisan` or direct
`php apps/gateway/artisan` from a controlled gateway shell; the public `orbit`
command never dispatches to gateway Artisan.

Nodes do not own fleet state or run a local control plane. They run workload
services. Gateway-backed commands invoked on a node call the gateway, and nodes
receive typed command envelopes that the gateway sends through Agent push.
Gateway-owned work executes locally. The Orbit Agent does not make a node a
source of truth.

Node-side CLI availability is not general write permission. Any node-side
write is authorized by the gateway before gateway-local handling or Agent push
to a non-gateway node. The node's self-grant must include the permissions for
that write. See
[architecture.md#self-grants-and-self-serving](../../architecture.md#self-grants-and-self-serving).
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md) is
the most visible example today — it works because the `app-dev` self-grant
baseline includes the workspace permissions it needs. `app-prod` self-grants
include no wildcard or workspace permission; production app services never
operate workspaces.

### Compatibility matrix

Active role assignments must satisfy this matrix:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | `vpn`, `router`, `metrics` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`, `analytics` |
| `vpn` | `gateway`, `router`, `metrics` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`, `analytics` |
| `router` | `gateway`, `vpn`, `metrics` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`, `analytics` |
| `app-dev` | `database`, `websocket`, `s3`, `metrics`, `analytics` | `gateway`, `vpn`, `router`, `app-prod`, `agent`, `ingress` |
| `app-prod` | `ingress`, `metrics` | `gateway`, `vpn`, `router`, `app-dev`, `database`, `agent`, `websocket`, `s3`, `analytics` |
| `database` | `app-dev`, `websocket`, `s3`, `metrics`, `analytics` | `gateway`, `vpn`, `router`, `app-prod`, `agent`, `ingress` |
| `agent` | none | `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `ingress`, `websocket`, `s3`, `metrics`, `analytics` |
| `ingress` | `app-prod`, `metrics` | `gateway`, `vpn`, `router`, `app-dev`, `database`, `agent`, `websocket`, `s3`, `analytics` |
| `websocket` | `app-dev`, `database`, `s3`, `metrics`, `analytics` | `gateway`, `vpn`, `router`, `ingress`, `app-prod`, `agent` |
| `s3` | `app-dev`, `database`, `websocket`, `metrics`, `analytics` | `gateway`, `vpn`, `router`, `ingress`, `app-prod`, `agent` |
| `metrics` | `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `ingress`, `websocket`, `s3`, `analytics` | `agent` |
| `analytics` | `app-dev`, `database`, `websocket`, `s3`, `metrics` | `gateway`, `vpn`, `router`, `ingress`, `app-prod`, `agent` |

In this version, `gateway`, `vpn`, and `router` are gateway-coupled
infrastructure roles. They are stored as separate role assignments and shown
separately in role output, but first gateway bootstrap assigns them together and normal
`node role:*` commands cannot add, update, or remove them independently.

### Role baselines

Roles materialize baseline tool intent when a role assignment converges.

| Role | Baseline intent |
| --- | --- |
| `gateway` | Swarm-managed `orbit-gateway` API service, `orbit-scheduler` service, gateway config root, SQLite database, and Orbit CA/certificate material |
| `vpn` | WireGuard server runtime, public endpoint settings, VPN peer defaults, and required DNS tool capability |
| `router` | Private `orbit-caddy` router and proxy-family intent for private `.orbit` service names, route artifacts, backend pools, and private HTTP/WebSocket/S3 routing |
| `app-dev` | Git, GitHub CLI, app runtime baseline, node-owned wildcard DNS projection, `orbit-caddy` app/workspace routes, and process-backed runtime units using the platform-supported backend where configured |
| `app-prod` | Git, GitHub CLI, private `orbit-caddy` backend, FrankenPHP app containers, and process-backed runtime units using the platform-supported backend where configured |
| `database` | Docker running as the substrate for managed database service processes |
| `agent` | Git, `orbit-caddy`, the shared unprivileged `agent` runtime user, the node-owned wildcard DNS projection derived from `tld`, and any role-specific runtime containers the agent workload needs |
| `ingress` | `orbit-caddy` running as the public production HTTP ingress boundary, forwarding public routes to `router` |
| `websocket` | Laravel Reverb in a Docker runtime container managed by Orbit, private TLS backend binding on WireGuard, backend certificate material, and Valkey-backed scaling configuration |
| `s3` | Docker installation and verification, SeaweedFS Docker runtime apply/start, private S3 API binding on WireGuard, service-level credentials on the `seaweedfs` tool row, backend pool registration, and role-owned data path |
| `metrics` | Docker substrate, node-exporter binary, Prometheus/Grafana Swarm processes, node-exporter systemd processes on metrics and active Ubuntu workload nodes, `metrics.orbit`, and Grafana credentials |
| `analytics` | Docker verification, WireGuard-bound Plausible CE Docker apply/start, private routing, public tracking route support, backend pool registration, and authenticated PostgreSQL/ClickHouse endpoint configuration |

Local database client binaries (`sqlite3`, `psql`, `mysql`) are not part of
any role or tool baseline. Orbit interacts with databases through the
`database_connection` state family and `database:*` commands, which use PHP
drivers — no client binary on the node is required.

The `agent` role does not preinstall any agent tool. Hermes is an ordinary
entry in the `tool` catalog with category `agent`; `node:new
--template=agent` or `--roles=agent` may optionally install it through
`--agent-tool=hermes`.

## Thin CLI and gateway authority

For public gateway-backed and remote commands, the Orbit CLI gathers input,
calls the typed gateway HTTPS API over WireGuard, and renders the result.
Commands that run locally, bootstrap before grants exist, or self-manage through
node identity follow their documented lanes. The CLI does not classify itself as operator,
gateway, or app, and it does not gate behavior on a local role label. For
gateway-backed calls, the gateway authenticates the presented WireGuard peer
identity and applies the authorization policy attached to that node.

A caller can be:

- an unconfigured client, with no gateway configuration yet, which can only
  perform first-gateway bootstrap for commands that support it;
- a configured client, with local gateway configuration and a gateway-issued
  WireGuard identity, which can call the gateway over HTTPS through WireGuard.

Client is an identity/onboarding concept, not a role. A configured client may
have no role assignments. Caller eligibility comes from the authenticated
WireGuard identity plus gateway grants. The CLI does not check or branch on
caller role locally.

## Hub and spoke model

Orbit uses the
[hub-and-spoke topology](../../architecture.md#components) defined by the
architecture. The gateway is the hub. Clients and nodes are spokes connected to
the gateway; they do not coordinate steady-state Orbit work with each other.
The one bootstrap exception is an initiating client's direct SSH edge to a
target before that target can accept Agent push.

```text
+--------------+     HTTPS over WireGuard     +-----------+
|    client    | ---------------------------> |  gateway  |
+------+-------+                              +-----+-----+
       |                                            |
       | client-local SSH, bootstrap only           | Agent push
       v                                            v
+--------------+                              +-----------+
| bootstrap    |                              | workload  |
| target       |                              | node      |
+--------------+                              +-----------+
```

Clients consume the gateway API. Before Agent readiness, the initiating client
may open SSH to a bootstrap target only to install the managed user, WireGuard,
CLI, and Agent substrate from the gateway-authored bundle. Nodes then serve
workloads and receive typed Agent push over Orbit/WireGuard. Gateway-owned work
executes locally on the gateway. CLI calls from nodes also consume the gateway
API and may infer local app or workspace context. Non-CLI node-to-gateway
traffic is limited to Agent responses and narrow event callbacks such as
process lifecycle hooks.

## Domain Rules

These rules apply to all node commands and define the invariants the family enforces.

- The gateway is the source of truth for all nodes.
- Node records define fleet membership, role assignments, platform, node
  identity, reachability, and access policy.
- Supported platforms are defined by role in
  [node-concepts.md](node-concepts.md#role-platform-support). Commands that
  provision a host or apply node-side artifacts must validate the observed host
  platform against that matrix before side effects.
- First-gateway bootstrap and new workload bootstrap use initiating-client SSH.
  For workloads the client installs only the minimal WireGuard/CLI/Agent
  substrate, after which the gateway completes provisioning through Agent push.
  The gateway never opens target SSH.
- After bootstrap, CLI callers communicate with the gateway over HTTPS through
  WireGuard; the gateway applies node changes through its node execution
  primitive.
- Clients are dedicated CLI callers; nodes may also act as CLI callers
  when commands are run from an app or workspace path. Neither role owns fleet
  state.
- A client may store a local default node. Commands that
  accept a node target may use this local default when `--node` is omitted
  and no app or workspace context already determines the owning node.
- `node:new` never sets the local default node automatically.
  The caller must run `node:default` explicitly.
- Node access grants decide which consuming nodes may operate on which serving
  nodes. Authorization runs two gates: the grant edge from consuming to
  serving must exist, and the scoped permission set stored on that edge must
  allow the requested action.
- Every CLI-to-gateway command is authenticated by WireGuard node identity and
  authorized through the node access policy that the gateway owns. Grants are not
  transport-specific and do not grant SSH.
- A non-gateway consuming node must have a grant to the serving node that owns
  the requested resource, and that grant must include a permission that allows
  the requested action, before it can read or mutate the resource. Gateway
  policy and history operations require a grant to the gateway node with the
  matching permission. A grant to the gateway whose permissions include `*`
  (the `gateway-admin` preset) is the fleet-wide super-admin grant.
- Permissions support normalization, implication, and dynamic wildcards.
  Redundant permissions are removed before storage, unknown permissions are
  rejected, and wildcards `node:*` or `*` include future permissions that
  belong to the matched namespace.
- Self-grants are explicit. A node does not implicitly have access to itself.
  Role baseline self-grants are created during `node:new` from each assigned
  role's self preset.
- `node:grant` creates the initial grant edge and the initial permissions on
  it. It does not edit an existing grant's permission set.
- `node:permissions` owns viewing, updating, and upserting the permission set
  for a grant. Read mode requires `node:read` or `*` on the grant edge; write
  modes (`--preset`, `--permissions`, `--add`, `--remove`) require
  `node:permissions` or `*`. Write modes may create a missing grant edge when
  the caller submits a valid non-empty permission set through interactive
  selection, `--preset`, `--permissions`, or `--add`. Read-only
  `node:permissions` and `--remove` require an existing grant and fail with
  `node.grant_not_found` otherwise.
- Role-assignment settings live on the role assignment when they are
  role-specific. `tld` is a shared node-level field required by every active
  node, so it lives on the node row and a node holds at most one `tld` value at
  a time. `vpn` stores `public_endpoint`, `wireguard_cidr`,
  `wireguard_port`, and `dns_ip` as role-assignment settings.
  `app-prod` stores `ingress_node_id`; `websocket` stores
  `valkey_node_id`, which points at the `database` role node whose managed Valkey
  service backs Reverb scaling; `database`, `gateway`, and `metrics` have no
  role-assignment settings. `s3` stores `data_path`, which defaults to
  `/srv/orbit/s3/data` and is mounted into the SeaweedFS container as `/data`.
  `analytics` stores `postgres_node_id`, `postgres_process_id`, and
  `clickhouse_node_id`. The process ID selects the exact supported PostgreSQL
  process on the PostgreSQL node; the node IDs point at active `database` role
  nodes whose managed PostgreSQL and ClickHouse services back Plausible CE.
- Role add and role update converge synchronously. Failed convergence makes the
  mutating command fail, while leaving the role assignment in `error` for a
  later `doctor --family=node --restore` retry.
- Service roles that own process runtimes are activated only after their
  required Docker capability is present and the persisted runtime unit has
  applied and started. A runtime transport, render, or start failure leaves
  `node:new` incomplete instead of returning an active role.
- Role removal blocks while dependents managed by Orbit still require the role.
  `--force` removes Orbit-owned dependents and configuration but preserves data.
  `--force --purge-data` deletes role-owned data only where an explicit command
  contract supports that purge.
- WireGuard, provisioning SSH, and certificates are node infrastructure
  details. They support node identity and bootstrap reachability, but they are
  not separate product domains in the command contract.
- Local node defaults do not grant access. The gateway still authenticates the
  caller and authorizes the requested operation through node access policy.
- For gateway nodes, node readiness includes the `orbit-gateway` service,
  `orbit-scheduler` service, gateway config root, and the selected gateway
  exposure mode. Runtime container provisioning commands specific to the
  process manager are not a public node command surface.
- `orbit doctor --family=node` verifies role, platform, WireGuard, Agent
  transport, provisioning SSH policy, and reachability expectations, including
  gateway service readiness for gateway nodes.

The node host contract is Docker-first. Provisioning creates or adopts
WireGuard identity and provisioning SSH hardening material, the Orbit config
local to the node, WireGuard service-address routing, and the Orbit CLI entry
point on the node for every managed Ubuntu node. That state is topology
infrastructure, not app, process, tool, or database runtime prerequisite state.

Production artifact installs use the prebuilt Orbit CLI binary (embedded PHP 8.5 +
`pdo_sqlite`/`openssl`/`curl`/`mbstring`/`tokenizer`/`ctype`/`filter`/`fileinfo`/`json`/`phar`/`zlib`). A node running the gateway role in production requires Docker Engine/CLI,
initialized Docker Swarm, the gateway config root, and the native Orbit CLI binary.
It does not require host PHP, host Composer, Git, or an Orbit source checkout.

Source-mounted Docker and Incus topologies are development and E2E lanes; in those
lanes `/usr/local/bin/orbit` points directly at `<source>/apps/cli/orbit` and
mutable Orbit state local to the node lives under `~/.config/orbit`. `app-dev` and
`app-prod` nodes additionally carry a host PHP toolchain (host PHP 8.4 and 8.5 and
Composer; the Laravel installer on `app-dev` only) for app setup, deployment,
and ad-hoc app CLI. Host Caddy (the `orbit-caddy` container) and host PHP-FPM
remain non-prerequisites and non-fallbacks.

## Transport Model

Node transport has different rules before and after bootstrap:

- First-gateway bootstrap uses its dedicated client-owned SSH path before a
  gateway API exists. Adding another managed workload node uses client-owned
  SSH only for the minimal bootstrap substrate because the target does not yet
  have WireGuard or an Agent listener. The configured client obtains a
  node-specific bundle from the gateway over existing WireGuard, streams it to
  the target, and then the gateway completes provisioning through Agent push.
  The gateway never opens target SSH.
- CLI callers use HTTPS over WireGuard to communicate with the gateway after
  local gateway configuration. This lets clients and CLI clients on nodes
  operate without owning fleet state.
- A roleless active operator node can explicitly opt into managed Agent intent
  by running `orbit node:manage`
  locally. The command does not add a role; it persists `node.managed=true` as
  explicit Agent intent.
- `node:manage` persists `node.user`, `node.platform`, and managed intent, then
  verifies a typed Agent runtime probe over the node's WireGuard identity.
  Failed verification retains that intent and reports Agent drift for
  `doctor --family=node` to surface for repair.
- Bootstrap is the sole managed SSH lane and is owned by the initiating client.
- Bootstrap uses client-local SSH to establish the minimal managed substrate.
  After bootstrap, the gateway sends typed `binary + argv` envelopes to the
  Orbit Agent for work on that node. Work for the gateway executes locally.
- The Orbit Agent lane is reserved for supported nodes, starting with macOS
  `app-dev` and self-managed workload nodes. The runtime in `apps/agent`
  receives gateway-pushed `binary + argv` command envelopes over the node-local
  Agent listener; it is not arbitrary shell transport, not a WebSocket
  requirement, and not an HTTP capability API on the node.
- Protected Agent commands may use the OS prompt when the gateway submits local
  work through direct Agent push. V1 has no separate
  Orbit approval UI or pending/approve flow. The macOS menu icon only means the
  process is running;
  opening the menu may perform a one-shot gateway ping that shows Connected or
  Disconnected, node name, and gateway name/host, plus Restart and Quit actions.
  It does not show command history.
- Orbit Agent is distinct from the existing `agent` workload role and from Agent
  sessions. Orbit Agent is a node-local execution lane for Orbit operations.
  Gateway-pushed commands are limited to Agent-eligible nodes. The `agent`
  workload role supplies derived intent like every other workload role; it is
  not a duplicated capability flag.

The current steady-state paths are therefore:

1. CLI caller to gateway over HTTPS through WireGuard;
2. gateway-local execution for gateway work, or gateway-pushed Agent HTTP for
   non-gateway node-local execution.

Client-owned bootstrap is the sole Orbit SSH lane. `node:manage` verifies the
authenticated Agent-push path and never selects SSH. Break-glass SSH belongs to
the operator and remains outside Orbit commands.

## Role Bootstrap Network Policy

Node provisioning owns the first network policy that makes a node reachable
without turning node bootstrap into editable firewall configuration. The policy
is role-aware:

- every managed Ubuntu gateway or node denies unsolicited inbound traffic
  by default, allows outbound traffic, and keeps the Orbit WireGuard interface
  open for in-network traffic;
- gateway nodes expose only the WireGuard endpoint publicly. Gateway API HTTPS
  ingress is an Orbit-network service bound to the gateway's WireGuard address;
- nodes with `app-dev` expose Orbit-managed HTTPS routes on the Orbit network.
  When the node has a configured private caller-facing `public_ipv4`, the same
  HTTP/HTTPS listeners are also published on that RFC1918 IPv4 for trusted
  local resolver overrides. HTTPS listener intent includes both TCP/443 and
  UDP/443 so HTTP/3-capable clients can use QUIC instead of silently falling
  back to HTTP/2. Private backend ports remain WireGuard-only;
- only nodes with active `ingress` expose public production HTTP/HTTPS, with
  HTTPS represented as TCP/443 plus UDP/443 on the public listener;
- `app-prod` backend port `80` is private backend traffic reachable only through the Orbit/WireGuard network;
- The Agent listener and other endpoints used to manage a node stay on the
  Orbit network. Provisioning SSH must not remain publicly exposed after
  bootstrap.

The node's assigned WireGuard IP is its private service address. TCP service
endpoints and private backend routes use that address directly instead of a
node TLD hostname. Linux managed nodes keep a self-route for their assigned
WireGuard address so a service client running on the same node uses the same
address that remote Orbit peers use.

Node bootstrap applies this baseline with rollback and reachability checks so a
failed policy change does not silently strand a node. Operator-managed firewall
configuration after bootstrap belongs to the `firewall_rule` family. Orbit does
not use SSH as command transport after provisioning. Break-glass SSH belongs to
the operator and remains outside Orbit commands.

Development wildcard record intent and its record projection are node-owned
during gateway and node bootstrap. The tool-owned DNS runtime serves that
projection. The resolver must be reachable through the Orbit network and must
not be exposed as an open public resolver.

## Domain Boundaries

The node domain owns:

- fleet membership;
- node roles, role assignments, and supported platforms;
- gateway configuration and consuming-node identity;
- node reachability from the gateway;
- consuming-to-serving node grants;
- gateway service readiness;
- node lifecycle checks and removal safety.

The node domain does not own:

- tool registration, configuration, or installation policy;
- firewall policy beyond role bootstrap requirements;
- app registration or app runtime policy;
- workspace registration;
- proxy route lifecycle;
- process or schedule definitions;
- deployment pipelines.

Those behaviors may depend on nodes, but their configuration belongs to their
own domains.

## Lifecycle

The ideal node lifecycle is:

1. Start from a client with the Orbit CLI.
2. Add an existing gateway to local config or bootstrap/register a new gateway.
3. Register and provision nodes.
4. Optionally run `node:default` to set a local default node
   for repeated local work.
5. Grant consuming nodes access to serving nodes.
6. Inspect, update, verify, or remove nodes through gateway-owned configuration.
7. Use `doctor --family=node` to detect node drift and restore or adopt it
   explicitly.

## Doctor Relationship

The node family probe, issue dispositions, and `doctor --family=node --restore` /
`doctor --family=node --adopt` boundaries are defined in [`node-doctor.md`](node-doctor.md).
`doctor --fix` runs an interactive resolution flow that prompts per item to
restore or adopt. List commands are registry-only; `doctor --family=node` owns
live node verification and resolution.

## Access Vocabulary

Node access grants use role-agnostic relationship terms:

- `consuming_node`: the node that receives permission to make an Orbit request.
- `serving_node`: the node that may be accessed by that request.

A serving node can be a node or a gateway when policy allows it. Role
constraints belong to access policy, not to the argument names.

Each grant carries a normalized permission set stored on the grant row. The
permission set is what the consuming node may do on the serving node once the
grant edge already permits the call. Presets `agent-self`, `operator`,
`read-only`, `developer`, `admin`, and `gateway-admin` expand into normalized
permission sets; custom permissions can be supplied as a comma-separated list.
Examples:

```json
["doctor:verify", "node:read", "tool:read", "tool:update"]
```

```json
["*"]
```

`*` is dynamic: it matches every current permission and every permission added
to the registry in the future. `gateway-admin` is the explicit preset that
expands to `*` on a grant to the gateway. `node:*` follows the same rule
inside the `node:` namespace.

The workspace boundary narrows that general vocabulary: a normalized set
containing `*` or any `workspace:*` permission is invalid when the consuming
node has `app-prod`, or when the serving node has `app-prod`.
This keeps production app services out of workspace operation even across
cross-node grants.

## Node Identity Issuance

Every CLI caller must present a gateway-known WireGuard identity before it can
call the gateway. Identity issuance is node lifecycle work owned by the
gateway: it creates the node registry row, issues the WireGuard peer
configuration, and marks the node identity as active.

A node identity is the node record that the gateway owns, plus its WireGuard peer
identity, assigned WireGuard address, role assignments, and node name. A compatible existing
node is an active node whose role assignments are known to the gateway and
whose role assignments, node identity, host, and assignment-local settings match the resolved command input for the path
being requested.

Gateway, node, and client identities are minted or adopted during
[`orbit node:new [name]`](1_node-new/node-new.md). Preparing a client
starts with local CLI installation. Production installs download the native
Orbit CLI binary artifact and link the host `bin/orbit` launcher as `orbit`.
Source-mounted Docker and Incus topologies are development and E2E lanes; in
those lanes `/usr/local/bin/orbit` points directly at `<source>/apps/cli/orbit`.
The `orbit-gateway` and `orbit-scheduler` Swarm services remain gateway-role
concerns, not blanket client prerequisites. The app README owns those
installation steps.

First-gateway bootstrap is a complete onboarding flow for the initiating
client. When a client with no configured gateway runs
`orbit node:new <gateway-name> --template=gateway --host=<host> --tld=<gateway-tld> --operator-name=<operator-name> --operator-tld=<operator-tld>`,
Orbit provisions the gateway with `<gateway-tld>` as its node identity and
creates the initiating client identity named by `<operator-name>` with the
separate `<operator-tld>` identity.
It then installs that local WireGuard identity, trusts the gateway CA, and stores local
gateway configuration using `<host>` as the initial gateway endpoint for WireGuard peer configs.
Finally it verifies gateway API access.
That initiating client does not run `gateway:add` afterward.

Client enrollment is a two-machine flow:

1. On the gateway, run the client enrollment flow to create the active
   `node` row and mint the WireGuard peer config.
2. Install the returned WireGuard config on the client machine and connect it
   to the Orbit network.
3. On the client machine, run `orbit gateway:add [gateway_ip]` to trust the
   gateway CA, verify `/api/me`, and store local gateway settings.

Before a client can run
[`orbit gateway:add [gateway_ip]`](../2_gateway/1_gateway-add/gateway-add.md),
it must already have the WireGuard identity material that the gateway issued installed, and
the Orbit WireGuard network must be active. `gateway:add` discovers or verifies
the gateway and stores local gateway connection settings; it does not create
identity or access policy. This does not apply to the initiating client
after successful first-gateway bootstrap, because that flow already performs the
local onboarding work.

After onboarding,
[`orbit gateway:trust`](../2_gateway/2_gateway-trust/gateway-trust.md) can
repair only local gateway CA trust without re-running identity verification or
changing gateway settings.

When a node is already provisioned and can prove compatible node identity,
`node:new` may adopt that existing identity into gateway configuration. When a gateway is
already known to the gateway registry, `node:new --template=gateway` converges that
gateway-owned identity instead of minting a duplicate node. Missing gateway-row
materialization belongs to first-gateway bootstrap or a future explicit recovery
contract, not gateway-local `node:new`.

## Commands

### Family Doctor

Run this command to detect and repair drift across all node-family artifacts.

- [`doctor --family=node`](node-doctor.md)

### Add or bootstrap

Use these commands to register and provision new fleet members.

1. New gateway or node:
   [`orbit node:new [name]`](1_node-new/node-new.md)

Gateway onboarding and gateway trust repair commands live in
[`Gateway commands`](../2_gateway/README.md).

### Inventory

Use these commands to list and inspect nodes registered in the gateway.

2. [`orbit node:list`](3_node-list/node-list.md)
3. [`orbit node:show [name]`](4_node-show/node-show.md)

### Access Policy

Use these commands to manage which nodes may consume resources from which
serving nodes and what scoped permissions each grant carries.

4. [`orbit node:grant [consuming_node] [serving_node]`](5_node-grant/node-grant.md)
5. [`orbit node:revoke [consuming_node] [serving_node]`](6_node-revoke/node-revoke.md)
6. [`orbit node:permissions [consuming_node] [serving_node]`](15_node-permissions/node-permissions.md)

### Lifecycle and verification

Use these commands to update, remove, or configure node settings after initial provisioning.

7. [`orbit node:update [name]`](7_node-update/node-update.md)
8. [`orbit node:remove [name]`](8_node-remove/node-remove.md)
9. [`orbit node:default [name]`](9_node-default/node-default.md)
10. [`orbit node:manage`](16_node-manage/node-manage.md)

### Role assignments

Use these commands to inspect and mutate the role assignments on an existing node.
Role assignment settings are changed through the command that owns the setting.
The node-level `tld` (mandatory and unique for every active node, at most one
per node, with `orbit` reserved for the proxy namespace) is changed through
[`orbit node:update [name] --tld=...`](7_node-update/node-update.md).

11. [`orbit node role:list [node]`](11_node-role-list/node-role-list.md)
12. [`orbit node role:add [node] [role]`](12_node-role-add/node-role-add.md)
13. [`orbit node role:remove [node] [role]`](14_node-role-remove/node-role-remove.md)
