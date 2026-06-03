# Node Concepts

This document defines node-family vocabulary and invariants. It supports the
node command contracts and the [node doctor](node-doctor.md); it does not
override the [Architecture](../../architecture.md).

## Role Vocabulary

Each term below has a precise meaning in the node command family.

- **Node:** A gateway-owned fleet member with a stable name, role assignments,
  platform, identity, reachability metadata, and access policy.
- **Client:** Node configured to use a gateway. A client stores local gateway
  configuration and WireGuard identity material. Any node can act as a client
  when it runs the Orbit CLI; the term emphasizes the CLI-caller perspective.
- **Operator:** Node identity with the operator permission preset and grants to
  operate one or more nodes through the gateway. It is not a node role and is
  not mutually exclusive with workload roles; any gateway-known node can be an
  operator when its grants allow that work.
- **Node role:** A fixed code-defined bundle attached through a role
  assignment. The ten roles are `gateway` (singleton authority), `vpn` and
  `router` (gateway-coupled infrastructure), `app-dev`,
  `app-prod`, `database`, `agent`, `ingress`, `websocket`, and `s3`.
  The latter seven are workload roles.
- **Gateway role:** The singleton authority role. The `gateway` role owns
  durable Orbit state, the typed API, root CA material, node access policy, and
  doctor convergence. It is stored as a role assignment, but normal
  role-mutation commands do not add it independently.
- **VPN role:** Gateway-coupled infrastructure role. The `vpn` role owns the
  WireGuard server runtime, public WireGuard endpoint settings, VPN peer
  defaults, and the VPN-facing DNS runtime. In v1 it is stored as a separate
  role assignment, shown separately in role output, assigned together with
  `gateway` during first gateway bootstrap, and not independently mutable
  through normal role commands.
- **Router role:** Gateway-coupled infrastructure role. The `router` role owns
  private `.orbit` DNS/service names, private route artifacts, backend pools,
  and private HTTP/WebSocket/S3 routing. In v1 it is assigned together with
  `gateway` and `vpn` during first gateway bootstrap and is not independently
  mutable through normal role commands.
- **Gateway-coupled infrastructure role:** Separate role assignment that is
  coupled to the `gateway` role in v1, so bootstrap assigns it together with
  `gateway` and normal `node role:*` commands cannot manage it independently.
- **Orbit launcher:** Host `orbit` wrapper installed in the user's path. It
  only resolves the repo root and execs the CLI source entrypoint.
  Production installs still use the native Orbit CLI binary artifact.
  Source-mounted Docker and Incus topologies are development and E2E lanes;
  there, `/usr/local/bin/orbit` points directly at `<source>/apps/cli/orbit`,
  the source entrypoint initializes `ORBIT_HOST_CWD` when absent and preserves
  supplied values, and mutable node-local Orbit state lives under
  `~/.config/orbit`. The CLI entry point owns public gateway-client commands,
  local-only commands, bootstrap commands, and hidden `internal:*` executor
  commands. Gateway maintenance (migrate, tinker, scheduler, queue,
  `orbit:internal:*` bake/build/install commands) uses
  `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` from a
  controlled gateway shell; the public `orbit` command never dispatches to
  gateway Artisan.
- **Orbit gateway image:** First-party
  `ghcr.io/hardimpactdev/orbit-gateway:<version>` FrankenPHP image that bundles
  the gateway application code. Production update plans pin this image by
  digest from the GitHub Release manifest.
- **Orbit gateway service:** Swarm-managed `orbit-gateway` service that serves
  the typed API and mounts `ORBIT_CONFIG_ROOT` for `.env`, `gateway.sqlite`,
  and Orbit CA/certificate material. The separate `orbit-scheduler` Swarm
  service uses the same image for schedule execution. Workload nodes run the
  public Orbit CLI as gateway clients and run workloads in role-specific
  runtime containers.
- **Orbit Caddy container:** Standalone `orbit-caddy` fleet proxy container.
  Nodes have at most one. It owns Orbit HTTP routing on that node, including
  app/workspace, tool, ingress, router, and private backend routes when those
  capabilities apply. In `router-colocated` gateway exposure mode, router-owned
  `orbit-caddy` also fronts the gateway API; in `gateway-direct` mode the
  gateway service publishes gateway HTTPS directly.
- **WebSocket role:** Private workload role for Orbit-managed realtime
  infrastructure. A websocket node runs Laravel Reverb in a Docker runtime
  container managed by Orbit, binds only to its WireGuard address, receives
  traffic through router-owned private service routes, and uses Redis selected
  from a `database` role node.
- **S3 role:** Private workload role for object storage with an S3-compatible
  API. An S3 node runs one RustFS instance in a Docker runtime container
  rendered by Orbit, binds the S3 API only to the node's WireGuard address, and
  receives private and public S3 traffic through router-owned service routes.
- **Agent role:** Exclusive workload role for first-party autonomous agent
  workloads. Conflicts with `gateway`, `vpn`, `router`, `app-dev`,
  `app-prod`, `database`, `ingress`, `websocket`, and `s3`. Selectable
  only during `node:new`.
- **Ingress role:** Workload role that owns public production HTTP
  ingress, public Caddy route artifacts, public TLS, and public edge
  hardening. It forwards public routes to `router` and may coexist with
  `app-prod`, but conflicts with `gateway`, `vpn`, `router`,
  `app-dev`, `database`, `agent`, and `s3`.
- **Role assignability:** Flag on a role that decides whether it may be
  selected by `node:new`, by `node role:add`, or by both. `agent` is
  assignable through `node:new` only; gateway-coupled infrastructure roles
  are assigned by gateway bootstrap only. `node role:add` rejects them.
- **Role assignment:** Gateway-owned record that attaches one role to one
  node, carries any role-specific settings, and tracks convergence status.
- **Role settings:** Assignment-local configuration for a role. Role-local
  desired configuration lives on the role assignment, not on the generic node
  record.
- **Node setup:** Internal gateway operation that applies gateway-configured
  role and tool intent to a real managed node during `node:new`. For a fresh
  hosted workload node, setup runs after node identity and role intent are
  stored and before the node is marked `active`.
- **Node convergence:** Internal service vocabulary for applying stored
  gateway intent to node reality. Public commands and renderers should describe
  this as setup during `node:new` and restore during doctor repair, not expose
  the service name.
- **Node TLD:** Node-level setting required by the `app-dev` and
  `agent` roles. A node holds at most one `tld` value at a time; roles that
  depend on it read and write the same node-level field. Default `agent` when
  selected through interactive `node:new --template=agent`. Drives the DNS mapping
  the gateway owns for that TLD and the agent tool internal HTTPS hostnames
  such as `openclaw.agent` and `hermes.agent`.
- **Agent role baseline:** Code-defined desired state for a node with the
  `agent` role: `orbit-caddy`, WireGuard/node identity and trust material, the
  shared unprivileged `agent` runtime user, and any role-specific runtime
  containers the agent workload needs.
- **Agent runtime user:** Shared unprivileged Linux user that owns agent
  tool runtimes on a node with the `agent` role. Agent tools never run as the
  privileged `orbit` maintenance user.
- **Role assignment status:** Lifecycle state of one role assignment:
  `pending`, `active`, `error`, or `removing`. Eligibility checks use only
  active assignments. Compatibility checks treat `active`, `pending`, and
  `error` assignments as unresolved conflicts and ignore `removing`.
- **Caller identity:** The gateway-known WireGuard identity that authenticates a
  CLI request. Operation is WireGuard identity plus gateway grants, not a
  built-in role. The CLI does not store or check a caller role locally.

## Role Compatibility

Assignments in `active`, `pending`, or `error` must satisfy this matrix:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | `vpn`, `router` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3` |
| `vpn` | `gateway`, `router` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3` |
| `router` | `gateway`, `vpn` | `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3` |
| `app-dev` | `database`, `websocket`, `s3` | `gateway`, `vpn`, `router`, `app-prod`, `agent`, `ingress` |
| `app-prod` | `ingress` | `gateway`, `vpn`, `router`, `app-dev`, `database`, `agent`, `websocket`, `s3` |
| `database` | `app-dev`, `websocket`, `s3` | `gateway`, `vpn`, `router`, `app-prod`, `agent`, `ingress` |
| `agent` | none | `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `ingress`, `websocket`, `s3` |
| `ingress` | `app-prod` | `gateway`, `vpn`, `router`, `app-dev`, `database`, `agent`, `websocket`, `s3` |
| `websocket` | `app-dev`, `database`, `s3` | `gateway`, `vpn`, `router`, `ingress`, `app-prod`, `agent` |
| `s3` | `app-dev`, `database`, `websocket` | `gateway`, `vpn`, `router`, `ingress`, `app-prod`, `agent` |

Compatibility checks treat assignments in `active`, `pending`, or `error` as
unresolved conflicts. Assignments already in `removing` are ignored.

In this version, `gateway`, `vpn`, and `router` are gateway-coupled
infrastructure roles. They are stored as separate role assignments and shown
separately in role output, but first gateway bootstrap assigns them together and normal
`node role:*` commands cannot add, update, or remove them independently.

## Role Settings

Role-local desired configuration lives on the role assignment when the setting
is specific to that role. Some settings are shared dependencies of multiple
roles and live as node-level fields that any qualifying role assignment can
require, read, and write.

| Role | Role-assignment settings | Node-level settings the role requires |
| --- | --- | --- |
| `vpn` | `public_endpoint`, `wireguard_cidr`, `wireguard_port`, `dns_ip` | — |
| `router` | — | — |
| `app-dev` | — | `tld` |
| `app-prod` | `ingress_node_id` | — |
| `database` | — | — |
| `gateway` | — | — |
| `agent` | — | `tld` (default `agent` during interactive `node:new` setup) |
| `ingress` | — | — |
| `websocket` | `redis_node_id` | — |
| `s3` | `data_path` | — |

A node can hold at most one `tld` value at a time. Roles that depend on `tld`
read and write the same node-level field. This shared field keeps the data
model coherent if a future version allows `app-dev` and `agent` to
coexist on one node (the v1 compatibility matrix forbids that today).
Changing the node-level `tld` is a desired-state change and triggers
baseline convergence
for every active role assignment that depends on it.

The `tld` value follows the single-lowercase-DNS-label rule and must be unique
across the active fleet.

Changing role-assignment settings (the per-role columns above) is also a
desired-state change and triggers the same baseline convergence path as
adding the role.

`public_endpoint` is the host or IP WireGuard peers use to reach the VPN.
`wireguard_cidr` defaults to `10.6.0.0/24`.
`wireguard_port` defaults to `51820`.
`dns_ip` defaults to `10.6.0.1` and is the DNS endpoint written into peer
configs. In v1 the DNS resolver runtime is coupled to the `vpn` role.
`redis_node_id` references the active `database` role node whose managed Redis
service backs Reverb scaling for the `websocket` role. The websocket role uses
that Redis service but does not install or own Redis.
`data_path` defaults to `/srv/orbit/s3/data`. It is the host path mounted into
the RustFS container as `/data` and is role-owned persistent data. Removing the
role without `--purge-data` must not delete this path.

## Role Baselines

Role baselines are code-defined desired state, not editable package lists.

| Role | Baseline intent |
| --- | --- |
| `gateway` | Swarm-managed `orbit-gateway` API service, `orbit-scheduler` service, gateway config root, SQLite database, and Orbit CA/certificate material |
| `vpn` | WireGuard server runtime, public endpoint settings, VPN peer defaults, and VPN-facing DNS runtime |
| `router` | Private `orbit-caddy` router for private `.orbit` DNS/service names, private route artifacts, backend pools, and private HTTP/WebSocket/S3 routing |
| `app-dev` | App runtime baseline, development DNS mapping, `orbit-caddy` app/workspace routes, and Supervisor process programs where configured |
| `app-prod` | Private `orbit-caddy` backend, FrankenPHP app containers, and Supervisor process programs where configured |
| `database` | Docker running as the substrate for managed database service tools |
| `agent` | `orbit-caddy`, the shared unprivileged `agent` runtime user, the gateway-owned agent DNS mapping for the role's `tld`, and any role-specific runtime containers the agent workload needs |
| `ingress` | `orbit-caddy` running as the public production HTTP ingress boundary, forwarding public routes to `router` |
| `websocket` | Laravel Reverb in a Docker runtime container managed by Orbit, private TLS backend binding on WireGuard, backend certificate material, and Redis-backed scaling configuration |
| `s3` | RustFS in a Docker runtime container rendered by Orbit, private S3 API binding on WireGuard, service-level credentials on the `rustfs` tool row, backend pool registration, and role-owned data path |

Baseline convergence first stores the gateway intent for the selected role.
When `node:new` provisions a real managed workload host, node setup then
applies the overlapping node and tool intent to the host before activation. The
initial app-development setup slice applies the role baseline tools
`caddy`, `php-cli`, `composer`, and `laravel-installer` through the shared
convergence path. After a node is active, `doctor --restore` uses the same
internal path for overlapping safe repairs while keeping family-specific issue
ownership and output.

Local database client binaries (`sqlite3`, `psql`, `mysql`) are not part of
any role or tool baseline. Orbit interacts with databases through the
`database_connection` state family and `database:*` commands, which run
queries via PHP drivers from the gateway or owning node — no client binary on
the node is required.

## Role Assignment Lifecycle

Role assignments use these lifecycle statuses:

| Status | Meaning |
| --- | --- |
| `pending` | The desired role has been stored, but convergence has not completed. |
| `active` | The role baseline intent is stored, setup-required host state has been applied for newly provisioned managed nodes, and the role can be used for eligibility checks. |
| `error` | Convergence failed; `doctor --family=node --restore` can retry after blockers are addressed. |
| `removing` | Cleanup is in progress or failed; the role is not eligible for new resources. |

Eligibility checks only use `active` assignments. Assignments in `pending`,
`error`, or `removing` are not eligible for workloads.

## Role Platform Support

Each role is supported on a specific set of host platforms.

| Role | Supported platforms |
| --- | --- |
| `gateway` | Ubuntu |
| `vpn` | Ubuntu |
| `router` | Ubuntu |
| client (no role assignments) | macOS, Ubuntu |
| `app-dev` | Ubuntu |
| `app-prod` | Ubuntu |
| `database` | Ubuntu |
| `agent` | Ubuntu |
| `ingress` | Ubuntu |
| `websocket` | Ubuntu |
| `s3` | Ubuntu |

Commands that provision a host or apply node-side artifacts must verify that the
observed host platform is supported for the node's gateway role assignment or
active workload roles before side effects.
Registry-only commands use stored gateway metadata and do not perform live
platform checks; platform drift belongs to `doctor --family=node`.

Managed Ubuntu nodes use a Docker-first prerequisite baseline. Production
artifact installs use the prebuilt Orbit CLI binary (embedded PHP 8.5 +
`pdo_sqlite`/`openssl`/`curl`/`mbstring`/`tokenizer`/`ctype`/`filter`/`fileinfo`/`json`/`phar`). A production gateway-only node requires Docker
Engine/CLI, initialized Docker Swarm, the gateway config root, WireGuard/SSH
identity material, and the native Orbit CLI binary. It does not require host
PHP, host Composer, Git, or an Orbit source checkout. Source-mounted Docker and
Incus topologies are development and E2E lanes; in those lanes
`/usr/local/bin/orbit` points directly at `<source>/apps/cli/orbit` and mutable
node-local Orbit state lives under `~/.config/orbit`.

Host PHP and Composer are production prerequisites only on nodes with
`app-dev` or `app-prod` roles. Those app-role nodes carry a host PHP
command-line toolchain — host PHP 8.4 and 8.5 and Composer on both; the Laravel
installer on `app-dev` only — installed and repaired as node tools, because app
setup, deployment, and ad-hoc app CLI run Composer and Artisan on the host
(matched to the app's PHP version) against the app source
the FrankenPHP container serves. This host PHP toolchain is distinct from the
Orbit CLI binary's embedded PHP, which only runs the CLI itself. Host Caddy and
host PHP-FPM remain non-prerequisites and non-fallbacks: Caddy runs only as the
`orbit-caddy` container, and PHP-FPM is never used. Internal executor commands
verify operation tokens through the gateway API; nodes do not store executor
token signing material.

## Identity and onboarding

These terms describe how nodes join the fleet and prove their identity to the gateway.

- **Node identity:** The node record that the gateway owns, plus its WireGuard
  peer identity, assigned WireGuard address, role assignments, and node name.
- **WireGuard service address:** The node's assigned WireGuard IP used as the
  stable private service host for TCP tool endpoints and backend-to-router
  targets. Linux managed nodes keep a self-route for this address so local
  services and local clients on the same node use the same WireGuard service
  address as remote peers.
- **First-gateway bootstrap:** The one allowed no-gateway path. A client
  provisions the first gateway over SSH, creates the initiating client
  identity, installs local trust and gateway config, and verifies gateway API
  access. This bootstrap path uses the dedicated gateway bootstrap installer
  until a gateway API exists; it does not route through node setup or the
  shared node convergence service.
- **Client enrollment:** A two-machine path: the gateway mints the client
  identity, the client machine installs that WireGuard identity, and then runs
  `gateway:add`.
- **Compatible existing node:** An active node whose role assignments are known
  to the gateway and whose role assignments, identity, host, and
  assignment-local settings match the resolved command input for the requested
  path.

## Transport and authority

These terms describe how nodes communicate and how authority is enforced.

- **CLI-to-gateway edge:** HTTPS over WireGuard from any node's CLI — client,
  gateway-local, or a node with workload roles — to the gateway API. On every
  node role, the launcher enters the node-local Orbit CLI entry point. In
  production that is the native CLI binary artifact; in source-mounted Docker
  and Incus development/E2E topologies `/usr/local/bin/orbit` points directly
  at `<source>/apps/cli/orbit`. The CLI calls the gateway API for public
  gateway-backed commands, mutates caller-local state for local-only commands,
  runs bootstrap commands before a gateway API exists, and routes hidden
  `internal:*` executor commands gated by an operation token. Gateway hosts
  call their own API as HTTPS over the
  gateway's own WireGuard address; there is no privileged local-loopback
  bypass. Gateway maintenance uses `bin/orbit-gateway-artisan` or direct
  `php apps/gateway/artisan` from a controlled gateway shell.
- **Gateway-to-node edge:** SSH through `RemoteShell` for node-side applying
  from the gateway.
- **Node event ingestion:** Narrow node-to-gateway callbacks for purpose-built
  lifecycle events, not node-side control-plane authority.
- **Node reality:** Observed role assignments, assignment status, platform,
  WireGuard, SSH, reachability, and gateway service readiness for a node.
- **VPN role settings:** Assignment-local `vpn` settings: `public_endpoint`,
  `wireguard_cidr`, `wireguard_port`, and `dns_ip`.

## Access Policy

These terms define the relationship model for node access grants.

- **Consuming node:** The node that receives permission to make an Orbit
  request.
- **Serving node:** The node that may be accessed by that request.

Node access grants are gateway-owned policy. They are not transport-specific,
do not grant SSH, and do not replace WireGuard authentication.

## Permissions

These terms describe what a node access grant authorizes once the grant edge
exists.

- **Node access permission:** Normalized permission string stored on a node
  access grant. Decides what the consuming node may do on the serving node
  after the grant edge already allows the call.
- **Permission registry:** Code-defined catalog of permission names, their
  labels, descriptions, namespaces, implications, and dynamic wildcard
  matching rules.
- **Permission implication:** Registry-declared relationship where one
  permission implies another. For example, `tool:read` implies `tool:list`,
  `tool:show`, and `tool:logs`.
- **Permission normalization:** Process of removing redundant permissions
  (implied or duplicated) and rejecting unknown permission strings before a
  grant is stored.
- **Wildcard permission:** Permission `*`. Matches every current and future
  permission across every namespace. Used by the `gateway-admin` preset.
- **Namespace wildcard permission:** Permission of the form `<namespace>:*`
  such as `node:*` or `tool:*`. Matches every current and future permission
  in that namespace.
- **Permission preset:** Code-defined named bundle of permissions selected by
  `--preset`. Presets do not embed wildcard permissions except the
  `gateway-admin` preset.
- **Agent self preset:** Preset used by `agent` self-grants. Contains
  `doctor:verify`, `node:read`, `tool:read`, `tool:restart`, and `tool:update`.
  Excludes `node:update`, `tool:credentials`, `tool:install`, `tool:remove`,
  `tool:stop`, `tool:reconfigure`, firewall writes, grant writes, node role
  writes, VPN writes, `doctor:restore`, and `doctor:adopt`.
- **Operator preset:** Default cross-node preset for nodes with the `agent`
  role and the general-purpose preset for fleet operators. Reads firewall
  rules and database registry or schema metadata, and reports firewall doctor
  findings, but cannot create, update, or remove firewall rules. Excludes SQL
  query access, database registry writes, `doctor:restore`, and `doctor:adopt`
  by default.
- **Read-only preset:** Preset that grants only read permissions across the
  product surface. It includes `database:read` but not `database:query`,
  because reading table rows is separate from reading registry or schema
  metadata.
- **Developer preset:** Preset for developer workflows on `app-dev`
  nodes. Includes app, workspace, process, schedule, proxy, deploy, database,
  and tool surfaces required to drive development work.
- **Admin preset:** Preset that grants full administrative authority over a
  serving node short of fleet-wide gateway admin.
- **Gateway-admin preset:** Preset `gateway-admin` expanding to `*`. Only
  meaningful as a consumer-to-gateway grant.

## Grant Setup

These terms describe how grants are created and what shape they take.

- **Self-grant:** Explicit consumer-to-serving grant where consumer and
  serving are the same node. Required for self-access; never implicit.
- **Gateway-admin grant:** Grant from a consumer to the gateway whose
  permissions include `*`. Confers fleet-wide super-admin authority,
  including authority over nodes added later.
- **Cross-node grant:** Grant where consumer and serving are different
  nodes. Default cross-node preset for nodes with the `agent` role is
  `operator`.
- **Directional grant setup:** During `node:new`, optional configuration of
  grants from the new node to other nodes (`--grant-to`,
  `--grant-to-preset`, `--grant-to-permissions`) and from other nodes to
  the new node (`--grant-from`, `--grant-from-preset`,
  `--grant-from-permissions`). The selector `all` expands to every current
  eligible node only; future nodes are not added automatically.
- **Agent tool selection:** During `node:new --template=agent` or
  `node:new --roles=agent`, the optional
  set of agent tools selected for first install. Zero, one, or several
  agent tools may be selected; there is no default agent tool.
- **Multi-agent-tool warning:** Warning emitted when a second running agent
  tool is selected or started on the same node with the `agent` role. Human
  callers receive an interactive confirmation; machine-readable callers
  receive a structured `tool.multiple_agent_tools_running` warning under
  `success.meta.warnings[]` and the command proceeds when input is otherwise
  valid.

## Development DNS Mapping

These terms describe how the gateway maintains DNS resolution for nodes with
the `app-dev` role.

The `router` role is gateway-coupled in v1. It owns private `.orbit`
DNS/service names, private route artifacts, backend pools, and private
HTTP/WebSocket/S3 routing. The development and agent TLD mappings below remain
node-family desired state, but they are not the public `dns:*` command surface.

- **Development DNS mapping owned by the gateway:** Node-family gateway configuration
  and desired DNS mappings and policy owned by the gateway. They map `*.{tld}`
  for an active `app-dev` role assignment to that node's WireGuard address.
  Runtime reality for that mapping is served and probed on the active
  gateway-coupled `vpn` role in v1.
- **Agent DNS mapping owned by the gateway:** Same node-family gateway
  configuration and resolver reality as the mapping that `app-dev`
  uses, but derived from an active `agent` role assignment's `tld` setting
  (default `agent`). Routes agent tool internal HTTPS hostnames such as
  `openclaw.agent` and `hermes.agent` to the node's WireGuard address.
- **Development DNS configuration model:** Derived from the active
  `app-dev` role assignment. A mapping exists only when that assignment
  is active, its `tld` setting is a single lowercase DNS label without a leading
  dot, and the node row has a non-empty WireGuard address.
  The canonical domain is `*.{tld}` and the canonical target is the
  node's WireGuard address.
- **Development DNS applier:** Internal node-family gateway service that
  uses desired DNS mappings and policy owned by the gateway to converge or remove
  resolver artifacts on the active `vpn` role runtime. In v1 that runtime is
  gateway-coupled. It is used by node provisioning, node adoption and
  materialization, node removal, and
  `doctor --family=node --restore`.
- **Development DNS probe:** Internal node-family gateway service that reads
  resolver reality from the active `vpn` role runtime for derived development
  DNS configuration and reports node-family drift when the mapping is absent,
  points at another target, or is publicly exposed. In v1 that runtime is
  gateway-coupled.

Development DNS mappings are not a public `dns:*` command surface and do not
create a `dns` state family. The `dns:*` commands own only the resolver overrides
local to the caller. The node family owns the gateway mapping lifecycle because it is
part of `app-dev` role readiness.

## Node Family Boundaries

The node family owns:

- fleet membership, node roles, role assignments, and supported platforms;
- gateway configuration, node identity, node reachability from the gateway,
  and gateway service readiness;
- the node access grant edge and the scoped permissions stored on each grant,
  plus the permission registry, presets, and normalization;
- the development and agent DNS mappings the gateway maintains;
- the `vpn` role's WireGuard server runtime, public endpoint settings, peer
  defaults, and DNS baseline exposed through the VPN runtime;
- node lifecycle checks.

The node family does not own app registration, workspace registration, process
or schedule definitions, proxy route lifecycle, tool registration, or editable
firewall policy beyond role bootstrap requirements. Those domains may depend on
nodes, but their configuration belongs to their own command families.
