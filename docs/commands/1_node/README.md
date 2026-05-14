# Node Commands

Nodes are Orbit's foundation. The first machine a new user meets Orbit on is usually a
control node: the machine where the CLI is installed, prompts are answered, and
commands are run.

From there, nodes define the fleet, the role of each machine, which platforms
are supported, how the gateway reaches app nodes, and which consuming nodes may
operate on which serving nodes.

Node commands are not app runtime commands. They establish where Orbit may run
work and who may ask for that work. Apps, workspaces, processes, tools, firewall
rules, proxy routes, schedules, and deployments build on top of the node model.

The stable node-family vocabulary is defined in
[`node-concepts.md`](node-concepts.md). The node-family drift, restore, and adopt
contract is defined in [`node-doctor.md`](node-doctor.md). Implementation-shape
details for runtime roles, transport edges, and gateway-to-app-node applying
live in [BUILDING-BLOCKS.md](../../BUILDING-BLOCKS.md#platform-and-roles) and
[BUILDING-BLOCKS.md#gateway-to-app-node](../../BUILDING-BLOCKS.md#gateway-to-app-node).

## Role Model

Orbit has three node roles:

- `control`: CLI caller configured to use a gateway.
- `gateway`: control plane and single source of truth.
- `app`: workload host for apps, workspaces, and app-owned runtime artifacts.

Role names describe behavior and caller eligibility. A phrase like "gateway
node" or "app node" refers to a concrete node record or host with that role.
Supported platforms are tracked in
[`node-concepts.md#role-platform-support`](node-concepts.md#role-platform-support).

App nodes may run the Orbit CLI as a stateless gateway client, but they do not
own fleet state or run a local Orbit control plane. They run workload services,
call the gateway when a local command is invoked, and receive gateway-applied
changes over SSH.

App-node CLI availability is not general write permission. The current
app-node write exception is
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md), as
defined by [ARCHITECTURE.md#app-node](../../ARCHITECTURE.md#app-node); it remains a
gateway-mediated local workflow, not local app-node ownership of configuration.

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

Caller role is a gateway-side property of the authenticated node record
(`control`, `gateway`, or `app`). The gateway uses it to authorize what each
caller may do. The CLI does not check or branch on caller role locally.
Commands that reject specific roles state that in their Prerequisites and
Failure Semantics.

## Hub and spoke model

Orbit uses the
[hub-and-spoke topology](../../ARCHITECTURE.md#hub-and-spoke) defined by the
architecture. The gateway is the hub. Control nodes and app nodes are spokes
connected to the gateway; they do not coordinate Orbit work with each other
directly.

```text
                      +--------------+
                      | control node |
                      +------+-------+
                             |
                             | HTTPS over WireGuard
                             v
+-----------+   SSH   +------+-------+   SSH   +-----------+
| app node  | <------ |   gateway    | ------> | app node  |
+-----+-----+         +------+-------+         +-----+-----+
                             ^
                             | event callbacks only
                             |
                         app-node hooks
```

Control nodes consume the gateway API. App nodes serve workloads and receive
gateway-applied changes over SSH. CLI calls from app nodes also consume the gateway
API and may infer local app or workspace context. Non-CLI app-node to gateway
traffic is limited to narrow event callbacks such as process lifecycle hooks.

## Domain Rules

These rules apply to all node commands and define the invariants the family enforces.

- The gateway is the source of truth for all nodes.
- Node records define fleet membership, role, platform, node identity,
  reachability, and access policy.
- Supported platforms are defined by role in
  [node-concepts.md](node-concepts.md#role-platform-support). Commands that
  provision a host or apply node-side artifacts must validate the observed host
  platform against that matrix before side effects.
- Initial provisioning of gateway and app hosts is always performed over SSH.
- After bootstrap, CLI callers communicate with the gateway over HTTPS through
  WireGuard; the gateway applies app-node changes through its node execution
  primitive.
- Control nodes are dedicated CLI callers; app nodes may also act as CLI callers
  when commands are run from an app or workspace path. Neither role owns fleet
  state.
- A control node may store a local default development app node. Commands that
  accept an app-node target may use this local default when `--node` is omitted
  and no app or workspace context already determines the owning node.
- `node:new` never sets the local default development app node automatically.
  The operator must run `node:default` explicitly.
- Nodes may store a default agent IDE adapter for apps and workspaces on that
  node. App-level settings override the node default.
- Node access grants decide which consuming nodes may operate on which serving
  nodes.
- Every CLI-to-gateway command is authenticated by WireGuard node identity and
  authorized through the node access policy that the gateway owns. Grants are not
  transport-specific and do not grant SSH.
- A non-gateway consuming node must have access to the serving node that owns
  the requested resource before it can read or mutate that resource. Gateway
  policy and history operations require access to the gateway node.
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

- Initial provisioning of gateway and app hosts uses SSH because the target host
  does not yet have enough Orbit identity, certificates, network trust, or
  gateway registration to participate in Orbit HTTPS calls.
- CLI callers use HTTPS over WireGuard to communicate with the gateway after
  local gateway configuration. This lets control nodes and CLI clients on app nodes
  operate without owning fleet state.
- Gateway VPN administration is the exception: `vpn-client:*` and
  `vpn-web-ui:*` commands run on the gateway host, so a control node initiating
  them needs SSH access to the gateway over Orbit/WireGuard.
- The gateway uses SSH to communicate with app nodes. On-node work such as file
  writes, service control, log access, package installation, and shell execution
  is simpler and more explicit over SSH than through an HTTP control plane on the app node.

The steady-state paths are therefore:

1. CLI caller to gateway over HTTPS through WireGuard;
2. gateway to app node over SSH when node-side work is required.

## Role Bootstrap Network Policy

Node provisioning owns the first network policy that makes a node reachable
without turning node bootstrap into editable firewall configuration. The policy
is role-aware:

- every managed Ubuntu gateway or app node denies unsolicited inbound traffic
  by default, allows outbound traffic, and keeps the Orbit WireGuard interface
  open for in-network traffic;
- gateway nodes expose only the WireGuard endpoint publicly. Gateway API HTTPS
  ingress is an Orbit-network service bound to the gateway's WireGuard address;
- development app nodes do not expose app routes publicly by baseline. Their
  Orbit-managed HTTPS routes are reachable through the Orbit network;
- production app nodes expose public HTTP/HTTPS ingress for production domains
  only. SSH and other node-management access stay on the Orbit network.

Node bootstrap applies this baseline with rollback and reachability checks so a
failed policy change does not silently strand a node. Operator-managed firewall
configuration after bootstrap belongs to the `firewall_rule` family, but public
SSH is not part of the steady-state baseline. SSH management traffic must use
the Orbit/WireGuard path.

Development DNS infrastructure is also node-owned during gateway/app-node
bootstrap. Gateway-provisioned development DNS must be reachable through the
Orbit network and must not expose an open public resolver.

## Domain Boundaries

The node domain owns:

- fleet membership;
- node roles and supported platforms;
- gateway configuration and consuming-node identity;
- app-node reachability from the gateway;
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

1. Start from a control node with the Orbit CLI.
2. Add an existing gateway to local config or bootstrap/register a new gateway.
3. Register and provision app nodes.
4. Optionally run `node:default` to set a local default development app node
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

A serving node can be an app node or a gateway when policy allows it. Role
constraints belong to access policy, not to the argument names.

## Node Identity Issuance

Every CLI caller must present a gateway-known WireGuard identity before it can
call the gateway. Identity issuance is node lifecycle work owned by the gateway: it
creates the node registry row, issues the WireGuard peer configuration, and
marks the node identity as active.

A node identity is the node record that the gateway owns, plus its WireGuard peer
identity, assigned WireGuard address, role, and node name. A compatible existing
node is an active node whose role is known to the gateway and whose role, node identity, host, app-node
environment, and development TLD match the resolved command input for the path
being requested.

Gateway, app-node, and control-node identities are minted or adopted during
[`orbit node:new [name]`](1_node-new/node-new.md). Creating a control machine is
local CLI installation: clone Orbit, install dependencies, and symlink
`artisan` as `orbit`; the project README owns those installation steps.

First-gateway bootstrap is a complete onboarding flow for the initiating
control node. When a control node with no configured gateway runs
`orbit node:new <gateway-name> --role=gateway --host=<host> --control-name=<control-name>`,
Orbit provisions the gateway and creates the initiating control node identity named by `<control-name>`.
It then installs that local WireGuard identity, trusts the gateway CA, and stores local
gateway configuration using `<host>` as the initial gateway endpoint for WireGuard peer configs.
Finally it verifies gateway API access.
That initiating control node does not run `gateway:add` afterward.

Control-node enrollment is a two-machine flow:

1. On the gateway, run `orbit node:new <control-name> --role=control` to create
   the active `node` row and mint the WireGuard peer config.
2. Install the returned WireGuard config on the control machine and connect it
   to the Orbit network.
3. On the control machine, run `orbit gateway:add [gateway_ip]` to trust the
   gateway CA, verify `/api/me`, and store local gateway settings.

Before a control node can run
[`orbit gateway:add [gateway_ip]`](../2_gateway/1_gateway-add/gateway-add.md),
it must already have the WireGuard identity material that the gateway issued installed, and
the Orbit WireGuard network must be active. `gateway:add` discovers or verifies
the gateway and stores local gateway connection settings; it does not create
identity or access policy. This does not apply to the initiating control node
after successful first-gateway bootstrap, because that flow already performs the
local onboarding work.

After onboarding,
[`orbit gateway:trust`](../2_gateway/2_gateway-trust/gateway-trust.md) can
repair only local gateway CA trust without re-running identity verification or
changing gateway settings.

When an app host is already provisioned and can prove compatible node identity,
`node:new` may adopt that app identity into gateway configuration. When a gateway is
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

1. New gateway, app, or control node:
   [`orbit node:new [name]`](1_node-new/node-new.md)

Gateway onboarding and gateway trust repair commands live in
[`Gateway commands`](../2_gateway/README.md).

### Inventory

Use these commands to list and inspect nodes registered in the gateway.

2. [`orbit node:list`](3_node-list/node-list.md)
3. [`orbit node:show [name]`](4_node-show/node-show.md)

### Access Policy

Use these commands to manage which nodes may consume resources from which serving nodes.

4. [`orbit node:grant [consuming_node] [serving_node]`](5_node-grant/node-grant.md)
5. [`orbit node:revoke [consuming_node] [serving_node]`](6_node-revoke/node-revoke.md)

### Lifecycle and verification

Use these commands to update, remove, or configure node settings after initial provisioning.

6. [`orbit node:update [name]`](7_node-update/node-update.md)
7. [`orbit node:remove [name]`](8_node-remove/node-remove.md)
8. [`orbit node:default [name]`](9_node-default/node-default.md)
9. [`orbit node:agent-ide [name] [agent_ide]`](10_node-agent-ide/node-agent-ide.md)
