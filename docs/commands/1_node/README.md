# Node Commands

Nodes are Orbit's foundation. A first-time user usually meets Orbit from a
control node: the machine where the CLI is installed, prompts are answered, and
commands are run.

From there, nodes define the fleet, the role of each machine, which platforms
are supported, how the gateway reaches app nodes, and which consuming nodes may
operate on which serving nodes.

Node commands are not app runtime commands. They establish where Orbit may run
work and who may ask for that work. Apps, workspaces, processes, tools, firewall
rules, proxy routes, schedules, and deployments build on top of the node model.

The stable node-family vocabulary is defined in
[`node-concepts.md`](node-concepts.md). The node-family drift, fix, and adopt
contract is defined in [`node-doctor.md`](node-doctor.md).

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
call the gateway when a local command is invoked, and receive gateway-enacted
changes over SSH.

App-node CLI availability is not general write permission. The current
app-node write exception is
[`workspace:setup`](../6_workspace/2_workspace-setup/workspace-setup.md), as
defined by [BLUEPRINT.md#app-node](../../BLUEPRINT.md#app-node); it remains a
gateway-mediated local workflow, not local app-node ownership of intent.

## Local Caller Role

Commands determine the local caller role from the local Orbit settings, not from
host heuristics, installed services, or the presence of a gateway configuration.
The foundation contract lives in
[BLUEPRINT.md#local-node-role-setting](../../BLUEPRINT.md#local-node-role-setting).

The setting is `general.local_node_role` with allowed values `control`,
`gateway`, and `app`. When the setting is unset or `null`, the caller role is
`control`. Gateway and app nodes must set this value explicitly to `gateway` or
`app`; otherwise they are treated as control callers. Control nodes may leave
the setting unset or store `control` explicitly.

Gateway configuration is a separate local setting. A control caller can be:

- an unconfigured control node, with no gateway configuration yet, which can
  only perform first-gateway bootstrap for commands that support it;
- a configured control node, with local gateway configuration and
  gateway-issued WireGuard identity, which can call the gateway over HTTPS
  through WireGuard.

If `general.local_node_role` contains an unsupported value or cannot be read,
commands fail before prompts or side effects with a local context error. A
missing role setting is not an error.

For commands that call the gateway, the local caller role only selects the
local command path. The gateway still authenticates the presented WireGuard
identity and applies gateway-owned access policy before accepting the request.

## Hub And Spoke Model

Orbit uses the
[hub-and-spoke node topology](../../BLUEPRINT.md#node-roles) defined by the
blueprint. The gateway is the hub. Control nodes and app nodes are spokes
connected to the gateway; they do not coordinate Orbit work with each other
directly.

```text
                      +--------------+
                      | control node |
                      +------+-------+
                             |
                             | HTTP over WireGuard
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
gateway-enacted changes over SSH. App-node CLI calls also consume the gateway
API and may infer local app or workspace context. Non-CLI app-node to gateway
traffic is limited to narrow event callbacks such as process lifecycle hooks.

## Domain Rules

- The gateway is the source of truth for all nodes.
- Node records define fleet membership, role, platform, node identity,
  reachability, and access policy.
- Supported platforms are defined by role in
  [node-concepts.md](node-concepts.md#role-platform-support). Commands that
  provision a host or enact node-side artifacts must validate the observed host
  platform against that matrix before side effects.
- Initial provisioning of gateway and app hosts is always performed over SSH.
- After bootstrap, CLI callers communicate with the gateway over HTTPS through
  WireGuard; the gateway enacts app-node changes through its node execution
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
  authorized through gateway-owned node access policy. Grants are not
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
  to serve the Orbit API. Backend-specific FPM provisioning commands are not a
  public node command surface.
- `orbit doctor --family=node` verifies role, platform, WireGuard, SSH, and
  reachability expectations, including gateway runtime readiness for gateway
  nodes.

## Transport Model

Node transport has different rules before and after bootstrap:

- Initial provisioning of gateway and app hosts uses SSH because the target host
  does not yet have enough Orbit identity, certificates, network trust, or
  gateway registration to participate in Orbit HTTP calls.
- CLI callers use HTTP to communicate with the gateway after local gateway
  configuration. This lets control nodes and app-node CLI clients operate
  without owning fleet state.
- Gateway VPN administration is the exception: `vpn-client:*` and
  `vpn-web-ui:*` commands run on the gateway host, so a control node initiating
  them needs SSH access to the gateway.
- The gateway uses SSH to communicate with app nodes. On-node work such as file
  writes, service control, log access, package installation, and shell execution
  is simpler and more explicit over SSH than through an app-node HTTP control
  plane.

The steady-state paths are therefore:

1. CLI caller to gateway over HTTPS through WireGuard;
2. gateway to app node over SSH when node-side work is required.

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

Those behaviors may depend on nodes, but their intent belongs to their own
domains.

## Lifecycle

The ideal node lifecycle is:

1. Start from a control node with the Orbit CLI.
2. Add an existing gateway to local config or bootstrap/register a new gateway.
3. Register and provision app nodes.
4. Optionally run `node:default` to set a local default development app node
   for repeated local work.
5. Grant consuming nodes access to serving nodes.
6. Inspect, update, verify, or remove nodes through gateway-owned intent.
7. Use `doctor --family=node` to detect node drift and fix or adopt it
   explicitly.

## Doctor Relationship

The node family probe, drift kinds, and `doctor --family=node --fix` /
`--adopt` boundaries are defined in [`node-doctor.md`](node-doctor.md).
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
call the gateway. Identity issuance is gateway-owned node lifecycle work: it
creates the node registry row, issues the WireGuard peer configuration, and
marks the node identity as active.

A node identity is the gateway-owned node record plus its WireGuard peer
identity, assigned WireGuard address, role, and node name. A compatible existing
node is an active gateway-known node whose role, node identity, host, app-node
environment, and development TLD match the resolved command input for the path
being requested.

Gateway, app-node, and control-node identities are minted or adopted during
[`orbit node:new [name]`](1_node-new/node-new.md). Creating a control machine is
local CLI installation: clone Orbit, install dependencies, and symlink
`artisan` as `orbit`; the project README owns those installation steps.

First-gateway bootstrap is a complete onboarding flow for the initiating
control node. When a control node with no configured gateway runs
`orbit node:new <gateway-name> --role=gateway --host=<host> --control-name=<control-name>`,
Orbit provisions the gateway, creates the initiating control node identity
named by `<control-name>`, installs that local WireGuard identity, trusts the
gateway CA, stores local gateway configuration using `<host>` as the initial
gateway endpoint for WireGuard peer configs, and verifies gateway API access.
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
it must already have gateway-issued WireGuard identity material installed and
the Orbit WireGuard network must be active. `gateway:add` discovers or verifies
the gateway and stores local gateway connection settings; it does not create
identity or access policy. This does not apply to the initiating control node
after successful first-gateway bootstrap, because that flow already performs the
local onboarding work.

After onboarding,
[`orbit gateway:trust`](../2_gateway/2_gateway-trust/gateway-trust.md) can
repair only local gateway CA trust without re-running identity verification or
changing gateway settings.

When a gateway or app host is already provisioned and already known to the
gateway registry, `node:new` adopts or converges that gateway-owned identity
instead of minting a duplicate node.

## Commands

### Family Doctor

- [`doctor --family=node`](node-doctor.md)

### Add Or Bootstrap

1. New gateway, app, or control node:
   [`orbit node:new [name]`](1_node-new/node-new.md)

Gateway onboarding and gateway trust repair commands live in
[`Gateway commands`](../2_gateway/README.md).

### Inventory

2. [`orbit node:list`](3_node-list/node-list.md)
3. [`orbit node:show [name]`](4_node-show/node-show.md)

### Access Policy

4. [`orbit node:grant [consuming_node] [serving_node]`](5_node-grant/node-grant.md)
5. [`orbit node:revoke [consuming_node] [serving_node]`](6_node-revoke/node-revoke.md)

### Lifecycle And Verification

6. [`orbit node:update [name]`](7_node-update/node-update.md)
7. [`orbit node:remove [name]`](8_node-remove/node-remove.md)
8. [`orbit node:default [name]`](9_node-default/node-default.md)
9. [`orbit node:agent-ide [name] [agent_ide]`](10_node-agent-ide/node-agent-ide.md)
