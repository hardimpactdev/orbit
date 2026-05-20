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
- **Node role:** A fixed code-defined bundle attached through a role
  assignment. The six roles are `gateway` (singleton authority), `vpn`
  (gateway-coupled infrastructure), `app-development`, `app-production`,
  `database`, and `agent`. The latter four are workload roles.
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
- **Gateway-coupled infrastructure role:** Separate role assignment that is
  coupled to the `gateway` role in v1, so bootstrap assigns it together with
  `gateway` and normal `node role:*` commands cannot manage it independently.
- **Agent role:** Exclusive workload role for first-party autonomous agent
  workloads. Conflicts with `gateway`, `vpn`, `app-development`,
  `app-production`, and `database`. Selectable only during `node:new`.
- **Role assignability:** Flag on a role that decides whether it may be
  selected by `node:new`, by `node role:add`, or by both. `agent` is
  assignable through `node:new` only; `node role:add` rejects it.
- **Role assignment:** Gateway-owned record that attaches one role to one
  node, carries any role-specific settings, and tracks convergence status.
- **Role settings:** Assignment-local configuration for a role. Role-local
  desired configuration lives on the role assignment, not on the generic node
  record.
- **Agent role TLD setting:** Role-assignment setting on the `agent` role.
  Default `agent`. Drives the DNS mapping the gateway owns for that TLD and
  the agent tool internal HTTPS hostnames such as `openclaw.agent` and
  `hermes.agent`.
- **Agent role baseline:** Code-defined desired state for a node with the
  `agent` role: Caddy, Supervisor, WireGuard/node identity and trust material,
  and the shared unprivileged `agent` runtime user.
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

## Legacy Terminology

Orbit now talks in nodes and role assignments. Earlier wording used
"joined client", "hosted node", "operator node", and "control node" for what
is now just a node — a machine with identity, possibly role assignments, and
gateway-stored grants. Legacy terms remain only where migration compatibility
still matters, such as persisted compatibility values, old CLI flags like
`--control-name`, legacy JSON examples, or historical test and file names.

## Role Compatibility

Assignments in `active`, `pending`, or `error` must satisfy this matrix:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | `vpn` | `app-development`, `app-production`, `database`, `agent` |
| `vpn` | `gateway` | `app-development`, `app-production`, `database`, `agent` |
| `app-development` | `database` | `gateway`, `vpn`, `app-production`, `agent` |
| `app-production` | `database` | `gateway`, `vpn`, `app-development`, `agent` |
| `database` | `app-development`, `app-production` | `gateway`, `vpn`, `agent` |
| `agent` | none | `gateway`, `vpn`, `app-development`, `app-production`, `database` |

Compatibility checks treat assignments in `active`, `pending`, or `error` as
unresolved conflicts. Assignments already in `removing` are ignored.

In this version, `gateway` and `vpn` are gateway-coupled infrastructure roles.
They are stored as separate role assignments and shown separately in role
output, but first gateway bootstrap assigns them together and normal
`node role:*` commands cannot add, update, or remove them independently.

## Role Settings

Role-local desired configuration lives on the role assignment when the setting
is specific to that role. Some settings are shared dependencies of multiple
roles and live as node-level fields that any qualifying role assignment can
require, read, and write.

| Role | Role-assignment settings | Node-level settings the role requires |
| --- | --- | --- |
| `vpn` | `public_endpoint`, `wireguard_cidr`, `wireguard_port`, `dns_ip` | — |
| `app-development` | — | `tld` |
| `app-production` | — | — |
| `database` | — | — |
| `gateway` | — | — |
| `agent` | — | `tld` (default `agent` during interactive `node:new` setup) |

A node can hold at most one `tld` value at a time. Roles that depend on `tld`
read and write the same node-level field. This shared field keeps the data
model coherent if a future version allows `app-development` and `agent` to
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

## Role Baselines

Role baselines are code-defined desired state, not editable package lists.

| Role | Baseline intent |
| --- | --- |
| `vpn` | WireGuard server runtime, public endpoint settings, VPN peer defaults, and VPN-facing DNS runtime |
| `app-development` | Development DNS mapping |
| `app-production` | `caddy`, `php`, and `supervisor` running |
| `database` | Docker running as the substrate for managed database service tools |
| `agent` | `caddy` and `supervisor` running, the shared unprivileged `agent` runtime user, and the gateway-owned agent DNS mapping for the role's `tld` |

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
| `active` | The role baseline is converged and can be used for eligibility checks. |
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
| client (no role assignments) | macOS, Ubuntu |
| `app-development` | Ubuntu |
| `app-production` | Ubuntu |
| `database` | Ubuntu |
| `agent` | Ubuntu |

Commands that provision a host or apply node-side artifacts must verify that the
observed host platform is supported for the node's gateway role assignment or
active workload roles before side effects.
Registry-only commands use stored gateway metadata and do not perform live
platform checks; platform drift belongs to `doctor --family=node`.

## Identity and onboarding

These terms describe how nodes join the fleet and prove their identity to the gateway.

- **Node identity:** The node record that the gateway owns, plus its WireGuard
  peer identity, assigned WireGuard address, role assignments, and node name.
- **First-gateway bootstrap:** The one allowed no-gateway path. A client
  provisions the first gateway over SSH, creates the initiating client
  identity, installs local trust and gateway config, and verifies gateway API
  access.
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
  gateway-local, or a node with workload roles — to the gateway API.
- **Gateway-to-node edge:** SSH through `RemoteShell` for node-side applying
  from the gateway.
- **Node event ingestion:** Narrow node-to-gateway callbacks for purpose-built
  lifecycle events, not node-side control-plane authority.
- **Node reality:** Observed role assignments, assignment status, platform,
  WireGuard, SSH, reachability, and gateway runtime readiness for a node.
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
  `doctor:verify`, `node:read`, `tool:read`, `tool:restart`, and
  `tool:update:agent-tools`. Excludes `node:update`, `tool:credentials`, `tool:install`,
  `tool:remove`, `tool:stop`, `tool:reconfigure`, firewall writes, grant
  writes, node role writes, VPN writes, `doctor:restore`, and `doctor:adopt`.
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
- **Developer preset:** Preset for developer workflows on `app-development`
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
- **Agent tool selection:** During `node:new --role=agent`, the optional
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
the `app-development` role.

- **Development DNS mapping owned by the gateway:** Node-family gateway configuration
  and desired DNS mappings and policy owned by the gateway. They map `*.{tld}`
  for an active `app-development` role assignment to that node's WireGuard address.
  Runtime reality for that mapping is served and probed on the active
  gateway-coupled `vpn` role in v1.
- **Agent DNS mapping owned by the gateway:** Same node-family gateway
  configuration and resolver reality as the mapping that `app-development`
  uses, but derived from an active `agent` role assignment's `tld` setting
  (default `agent`). Routes agent tool internal HTTPS hostnames such as
  `openclaw.agent` and `hermes.agent` to the node's WireGuard address.
- **Development DNS configuration model:** Derived from the active
  `app-development` role assignment. A mapping exists only when that assignment
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
part of `app-development` role readiness.

## Node Family Boundaries

The node family owns:

- fleet membership, node roles, role assignments, and supported platforms;
- gateway configuration, node identity, node reachability from the gateway,
  and gateway runtime readiness;
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
