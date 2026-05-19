# Node Commands

Nodes are Orbit's foundation. The first machine a new user meets Orbit on is usually a
joined client: the machine where the CLI is installed, prompts are answered, and
commands are run.

From there, nodes define the fleet, the role assignments of each machine, which
platforms are supported, how the gateway reaches hosted nodes, and which consuming nodes may
operate on which serving nodes.

Node commands are not app runtime commands. They establish where Orbit may run
work and who may ask for that work. Apps, workspaces, processes, tools,
database connections, firewall rules, proxy routes, schedules, and deployments
build on top of the node model.

The stable node-family vocabulary is defined in
[`node-concepts.md`](node-concepts.md). The node-family drift, restore, and adopt
contract is defined in [`node-doctor.md`](node-doctor.md). Implementation-shape
details for runtime roles, transport edges, and gateway-to-hosted-node applying
live in [tech-stack.md](../../tech-stack.md#platform-and-roles) and
[tech-stack.md#gateway-to-hosted-node](../../tech-stack.md#gateway-to-hosted-node).

## Role Model

Orbit distinguishes three concepts:

- **Gateway role:** the singleton authority role. It owns durable Orbit state,
  the typed API, WireGuard coordination, certificate authority material,
  development DNS coordination, grants, and doctor convergence. A gateway role
  assignment is stored in the role assignment model but cannot be added through
  normal role commands and conflicts with every hosted role.
- **Hosted node roles:** composable roles that prepare a node to serve a kind of
  workload. The initial hosted roles are `app-development`, `app-production`,
  `database`, and `agent`. `agent` is exclusive and selectable only during
  `node:new`; `node role:add` rejects it.
- **Joined client identity:** a CLI installation that has gateway configuration
  and a gateway-issued WireGuard identity. A joined client may have no hosted
  roles. It can request self-scoped actions and can operate other nodes only
  through explicit gateway grants.

Hosted roles are code-defined bundles, not open-ended labels. Orbit stores role
assignments only; capabilities are derived internally from the active
assignments. Supported platforms are tracked in
[`node-concepts.md#role-platform-support`](node-concepts.md#role-platform-support).

Hosted nodes may run the Orbit CLI as a stateless gateway client, but they do
not own fleet state or run a local Orbit operator capability layer. They run workload
services, call the gateway when a local command is invoked, and receive
gateway-applied changes over SSH.

Hosted-node CLI availability is not general write permission. The current
hosted-node write exception is
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md), as
defined by [architecture.md#hosted-node](../../architecture.md#hosted-node); it remains a
gateway-mediated local workflow, not local hosted-node ownership of
configuration.

### Compatibility matrix

Active role assignments must satisfy this matrix:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | none | `app-development`, `app-production`, `database`, `agent` |
| `app-development` | `database` | `gateway`, `app-production`, `agent` |
| `app-production` | `database` | `gateway`, `app-development`, `agent` |
| `database` | `app-development`, `app-production` | `gateway`, `agent` |
| `agent` | none | `gateway`, `app-development`, `app-production`, `database` |

### Hosted role baselines

Hosted roles materialize baseline tool intent when a role assignment converges.

| Role | Baseline intent |
| --- | --- |
| `app-development` | Development DNS mapping and `sqlite3` as an installed local utility |
| `app-production` | `caddy`, `php`, and `supervisor` running, plus `sqlite3` as an installed local utility |
| `database` | Docker running as the substrate for managed database service tools |
| `agent` | `caddy` and `supervisor` running, the shared unprivileged `agent` runtime user, and the gateway-owned agent DNS mapping for the role's `tld` |

The `database` role does not preinstall every database client. Service-specific
tools install their own helpers: `postgres` installs `postgresql-client`, and
`mysql` installs `default-mysql-client`.

The `agent` role does not preinstall any agent tool. OpenClaw and Hermes are
ordinary entries in the `tool` catalog with category `agent`; `node:new
--role=agent` may optionally install zero, one, or several of them.

## Thin CLI and gateway authority

The Orbit CLI is a thin gateway client. It gathers input, calls the gateway,
and renders the result. It does not classify itself as control, gateway, or
app, and it does not gate behavior on a local role label. The CLI calls the
gateway; the gateway authenticates the presented WireGuard peer identity and
applies the authorization policy attached to that node.

A caller can be:

- an unconfigured CLI, with no gateway configuration yet, which can only
  perform first-gateway bootstrap for commands that support it;
- a configured CLI, with local gateway configuration and a gateway-issued
  WireGuard identity, which can call the gateway over HTTPS through WireGuard.

Joined client is an identity/onboarding concept, not a hosted role. A joined
client may have no hosted roles. Caller eligibility comes from the
authenticated WireGuard identity plus gateway grants. The CLI does not check or
branch on caller role locally.
Commands that reject specific roles state that in their Prerequisites and
Failure Semantics.

## Hub and spoke model

Orbit uses the
[hub-and-spoke topology](../../architecture.md#hub-and-spoke) defined by the
architecture. The gateway is the hub. Joined clients and hosted nodes are spokes
connected to the gateway; they do not coordinate Orbit work with each other
directly.

```text
                      +--------------+
                      | joined client |
                      +------+-------+
                             |
                             | HTTPS over WireGuard
                             v
+-----------+   SSH   +------+-------+   SSH   +-----------+
| hosted    | <------ |   gateway    | ------> | hosted    |
| node      |         |              |         | node      |
+-----+-----+         +------+-------+         +-----+-----+
                             ^
                             | event callbacks only
                             |
                        hosted-node hooks
```

Joined clients consume the gateway API. Hosted nodes serve workloads and receive
gateway-applied changes over SSH. CLI calls from hosted nodes also consume the gateway
API and may infer local app or workspace context. Non-CLI hosted-node to gateway
traffic is limited to narrow event callbacks such as process lifecycle hooks.

## Domain Rules

These rules apply to all node commands and define the invariants the family enforces.

- The gateway is the source of truth for all nodes.
- Node records define fleet membership, role assignments, platform, node
  identity, reachability, and access policy.
- Supported platforms are defined by role in
  [node-concepts.md](node-concepts.md#role-platform-support). Commands that
  provision a host or apply node-side artifacts must validate the observed host
  platform against that matrix before side effects.
- Initial provisioning of gateway and hosted hosts is always performed over SSH.
- After bootstrap, CLI callers communicate with the gateway over HTTPS through
  WireGuard; the gateway applies hosted-node changes through its node execution
  primitive.
- Joined clients are dedicated CLI callers; hosted nodes may also act as CLI callers
  when commands are run from an app or workspace path. Neither role owns fleet
  state.
- A joined client may store a local default development hosted node. Commands that
  accept a hosted-node target may use this local default when `--node` is omitted
  and no app or workspace context already determines the owning node.
- `node:new` never sets the local default development hosted node automatically.
  The operator must run `node:default` explicitly.
- Nodes may store a default agent IDE adapter for apps and workspaces on that
  node. App-level settings override the node default.
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
  Role baseline self-grants are created during `node:new` from each hosted
  role's self preset.
- `node:grant` creates the initial grant edge and the initial permissions on
  it. It does not edit an existing grant's permission set.
- `node:permissions` owns viewing, updating, and upserting the permission set
  for a grant. It is gateway-admin only and may create a missing grant edge
  when the caller submits a valid non-empty permission set through
  interactive selection, `--preset`, `--permissions`, or `--add`. Read-only
  `node:permissions` and `--remove` require an existing grant and fail with
  `node.grant_not_found` otherwise.
- Role settings live on the role assignment. In v1, `app-development` and
  `agent` each store a `tld` (the `agent` default is `agent`);
  `app-production`, `database`, and `gateway` have no assignment settings.
- Role add and role update converge synchronously. Failed convergence leaves the
  role assignment in `error` for a later `doctor --family=node --restore`
  retry.
- Role removal blocks while dependents managed by Orbit still require the role.
  `--force` removes Orbit-owned dependents and configuration but preserves data.
  `--force --purge-data` deletes role-owned data only where an explicit command
  contract supports that purge.
- Current implementation may retain legacy node-row role, environment, and TLD
  shadow fields during migration. Role assignments remain the product
  contract.
- WireGuard, SSH, and certificates are node infrastructure details. They support
  node identity and reachability, but they are not separate product domains in
  the command contract.
- Local node defaults do not grant access. The gateway still authenticates the
  caller and authorizes the requested operation through node access policy.
- For gateway nodes, node readiness includes the gateway runtime service needed
  to serve the Orbit API. FPM provisioning commands specific to the process manager are
  not a public node command surface.
- `orbit doctor --family=node` verifies role, platform, WireGuard, SSH, and
  reachability expectations, including gateway runtime readiness for gateway
  nodes.

## Transport Model

Node transport has different rules before and after bootstrap:

- Initial provisioning of gateway and hosted hosts uses SSH because the target host
  does not yet have enough Orbit identity, certificates, network trust, or
  gateway registration to participate in Orbit HTTPS calls.
- CLI callers use HTTPS over WireGuard to communicate with the gateway after
  local gateway configuration. This lets joined clients and CLI clients on hosted nodes
  operate without owning fleet state.
- Gateway VPN administration is the exception: `vpn-client:*` and
  `vpn-web-ui:*` commands run on the gateway host, so a joined client initiating
  them needs SSH access to the gateway over Orbit/WireGuard.
- The gateway uses SSH to communicate with hosted nodes. On-node work such as file
  writes, service control, log access, package installation, and shell execution
  is simpler and more explicit over SSH than through an HTTP operator capability layer on the hosted node.

The steady-state paths are therefore:

1. CLI caller to gateway over HTTPS through WireGuard;
2. gateway to hosted node over SSH when node-side work is required.

## Role Bootstrap Network Policy

Node provisioning owns the first network policy that makes a node reachable
without turning node bootstrap into editable firewall configuration. The policy
is role-aware:

- every managed Ubuntu gateway or hosted node denies unsolicited inbound traffic
  by default, allows outbound traffic, and keeps the Orbit WireGuard interface
  open for in-network traffic;
- gateway nodes expose only the WireGuard endpoint publicly. Gateway API HTTPS
  ingress is an Orbit-network service bound to the gateway's WireGuard address;
- hosted nodes with `app-development` do not expose app routes publicly by baseline. Their
  Orbit-managed HTTPS routes are reachable through the Orbit network;
- hosted nodes with `app-production` expose public HTTP/HTTPS ingress for production domains
  only. SSH and other node-management access stay on the Orbit network.

Node bootstrap applies this baseline with rollback and reachability checks so a
failed policy change does not silently strand a node. Operator-managed firewall
configuration after bootstrap belongs to the `firewall_rule` family, but public
SSH is not part of the steady-state baseline. SSH management traffic must use
the Orbit/WireGuard path.

Development DNS infrastructure is also node-owned during gateway/hosted-node
bootstrap. Gateway-provisioned development DNS must be reachable through the
Orbit network and must not expose an open public resolver.

## Domain Boundaries

The node domain owns:

- fleet membership;
- node roles, role assignments, and supported platforms;
- gateway configuration and consuming-node identity;
- hosted-node reachability from the gateway;
- consuming-to-serving node grants;
- gateway runtime readiness;
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

1. Start from a joined client with the Orbit CLI.
2. Add an existing gateway to local config or bootstrap/register a new gateway.
3. Register and provision hosted nodes.
4. Optionally run `node:default` to set a local default development hosted node
   for repeated local work.
5. Grant consuming nodes access to serving nodes.
6. Inspect, update, verify, or remove nodes through gateway-owned configuration.
7. Use `doctor --family=node` to detect node drift and restore or adopt it
   explicitly.

## Doctor Relationship

The node family probe, drift kinds, and `doctor --family=node --restore` /
`doctor --family=node --adopt` boundaries are defined in [`node-doctor.md`](node-doctor.md).
`doctor --fix` runs an interactive resolution flow that prompts per item to
restore or adopt.
`node:list --doctor` is a node-family-only operator convenience that attaches a
node-family doctor summary to the registry list. It is not a shared list-command
convention; app and workspace list commands remain registry-only and point to
their family doctor commands for live verification.

## Access Vocabulary

Node access grants use role-agnostic relationship terms:

- `consuming_node`: the node that receives permission to make an Orbit request.
- `serving_node`: the node that may be accessed by that request.

A serving node can be a hosted node or a gateway when policy allows it. Role
constraints belong to access policy, not to the argument names.

Each grant carries a normalized permission set stored on the grant row. The
permission set is what the consuming node may do on the serving node once the
grant edge already permits the call. Presets such as `agent-self`, `operator`,
`developer`, `admin`, and `gateway-admin` expand into normalized permission
sets; custom permissions can be supplied as a comma-separated list. Examples:

```json
["doctor:verify", "node:read", "tool:read", "tool:restart", "tool:update:agent-tools"]
```

```json
["*"]
```

`*` is dynamic: it matches every current permission and every permission added
to the registry in the future. `gateway-admin` is the explicit preset that
expands to `*` on a grant to the gateway. `node:*` follows the same rule
inside the `node:` namespace.

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

Gateway, hosted-node, and joined-client identities are minted or adopted during
[`orbit node:new [name]`](1_node-new/node-new.md). Preparing a joined client
starts with local CLI installation: clone Orbit, install dependencies, and
symlink `artisan` as `orbit`; the project README owns those installation steps.

First-gateway bootstrap is a complete onboarding flow for the initiating
joined client. When a joined client with no configured gateway runs
`orbit node:new <gateway-name> --role=gateway --host=<host> --control-name=<control-name>`,
Orbit provisions the gateway and creates the initiating joined-client identity named by `<control-name>`.
It then installs that local WireGuard identity, trusts the gateway CA, and stores local
gateway configuration using `<host>` as the initial gateway endpoint for WireGuard peer configs.
Finally it verifies gateway API access.
That initiating joined client does not run `gateway:add` afterward.

Joined-client enrollment is a two-machine flow:

1. On the gateway, run the joined-client enrollment flow to create the active
   `node` row and mint the WireGuard peer config.
2. Install the returned WireGuard config on the client machine and connect it
   to the Orbit network.
3. On the client machine, run `orbit gateway:add [gateway_ip]` to trust the
   gateway CA, verify `/api/me`, and store local gateway settings.

Before a joined client can run
[`orbit gateway:add [gateway_ip]`](../2_gateway/1_gateway-add/gateway-add.md),
it must already have the WireGuard identity material that the gateway issued installed, and
the Orbit WireGuard network must be active. `gateway:add` discovers or verifies
the gateway and stores local gateway connection settings; it does not create
identity or access policy. This does not apply to the initiating joined client
after successful first-gateway bootstrap, because that flow already performs the
local onboarding work.

After onboarding,
[`orbit gateway:trust`](../2_gateway/2_gateway-trust/gateway-trust.md) can
repair only local gateway CA trust without re-running identity verification or
changing gateway settings.

When a hosted host is already provisioned and can prove compatible node identity,
`node:new` may adopt that hosted identity into gateway configuration. When a gateway is
already known to the gateway registry, `node:new --role=gateway` converges that
gateway-owned identity instead of minting a duplicate node. Missing gateway-row
materialization belongs to first-gateway bootstrap or a future explicit recovery
contract, not gateway-local `node:new`.

## Commands

### Family Doctor

Run this command to detect and repair drift across all node-family artifacts.

- [`doctor --family=node`](node-doctor.md)

### Add or bootstrap

Use these commands to register and provision new fleet members.

1. New gateway or hosted node:
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

6. [`orbit node:update [name]`](7_node-update/node-update.md)
7. [`orbit node:remove [name]`](8_node-remove/node-remove.md)
8. [`orbit node:default [name]`](9_node-default/node-default.md)
9. [`orbit node:agent-ide [name] [agent_ide]`](10_node-agent-ide/node-agent-ide.md)
