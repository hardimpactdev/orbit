# Node Concepts

This document defines node-family vocabulary and invariants. It supports the
node command contracts and the [node doctor](node-doctor.md); it does not
override the [Architecture](../../ARCHITECTURE.md).

## Role Vocabulary

- **Node:** A gateway-owned fleet member with a stable name, role, platform,
  identity, reachability metadata, and access policy.
- **Gateway:** Node that owns durable Orbit state, the typed API, WireGuard
  coordination, root CA material, DNS coordination, node access policy, and
  doctor convergence.
- **Control node:** CLI caller configured to use a gateway. A control node
  stores local gateway configuration and WireGuard identity material, but it
  does not orchestrate app nodes directly.
- **App node:** Workload host for apps, workspaces, and managed runtime
  artifacts. It may run the Orbit CLI as a stateless gateway client, but it is
  not a control plane.
- **Local caller role:** The local `general.local_node_role` setting. Unset or
  `null` resolves to `control`; gateway and app nodes must write explicit
  values after identity and readiness are established.

## Role Platform Support

| Role | Supported platforms |
| --- | --- |
| `control` | macOS, Ubuntu |
| `gateway` | Ubuntu |
| `app` | Ubuntu |

Commands that provision a host or enact node-side artifacts must verify that the
observed host platform is supported for the node's role before side effects.
Registry-only commands use stored gateway metadata and do not perform live
platform checks; platform drift belongs to `doctor --family=node`.

## Identity And Onboarding

- **Node identity:** The gateway-owned node record plus its WireGuard peer
  identity, assigned WireGuard address, role, and node name.
- **First-gateway bootstrap:** The one allowed no-gateway path. A control node
  provisions the first gateway over SSH, creates the initiating control-node
  identity, installs local trust and gateway config, and verifies gateway API
  access.
- **Control-node enrollment:** A two-machine path: the gateway mints the
  control-node identity through `node:new --role=control`; the control machine
  installs that WireGuard identity and runs `gateway:add`.
- **Compatible existing node:** An active gateway-known node whose role,
  identity, host, app-node environment, and development TLD match the resolved
  command input for the requested path.

## Transport And Authority

- **CLI-to-gateway edge:** HTTPS over WireGuard from control nodes, app-node CLI
  clients, or the gateway-local CLI to the gateway API.
- **Gateway-to-app-node edge:** SSH through `RemoteShell` for node-side
  enactment.
- **App-node event ingestion:** Narrow app-node-to-gateway callbacks for
  purpose-built lifecycle events, not app-node control-plane authority.
- **Node reality:** Observed role, platform, WireGuard, SSH, reachability, and
  gateway runtime readiness for a node.

## Access Policy

- **Consuming node:** The node that receives permission to make an Orbit
  request.
- **Serving node:** The node that may be accessed by that request.

Node access grants are gateway-owned policy. They are not transport-specific,
do not grant SSH, and do not replace WireGuard authentication.

## Development DNS Mapping

- **Gateway-owned development DNS mapping:** Node-family gateway intent and
  gateway-local resolver reality that maps `*.{nodes.tld}` for an active
  development app node to that node's WireGuard address.
- **Development DNS intent model:** Derived from the active app-node row. A
  mapping exists only when the node row is an active development app node,
  `nodes.tld` is non-empty, and the node row has a non-empty WireGuard address.
  The canonical domain is `*.{nodes.tld}` and the canonical target is the
  node's WireGuard address.
- **Development DNS enactor:** Internal node-family gateway service that
  converges or removes gateway-local development DNS resolver artifacts from
  the derived intent model. It is used by app-node provisioning, app-node
  adoption/materialization, node removal, and `doctor --fix --family=node --restore`.
- **Development DNS probe:** Internal node-family gateway service that reads
  gateway-local resolver reality for derived development DNS intent and reports
  node-family drift when the mapping is absent, points at another target, or is
  publicly exposed.

Development DNS mappings are not a public `dns:*` command surface and do not
create a `dns` state family. The `dns:*` commands own caller-local resolver
overrides only. The node family owns the gateway mapping lifecycle because it is
part of development app-node readiness.

## Node Family Boundaries

The node family owns fleet membership, node roles, supported platforms, gateway
configuration, node identity, app-node reachability from the gateway, access
policy, gateway runtime readiness, gateway-owned development DNS mappings, and
node lifecycle checks.

The node family does not own app registration, workspace registration, process
or schedule definitions, proxy route lifecycle, tool registration, or editable
firewall policy beyond role bootstrap requirements. Those domains may depend on
nodes, but their intent belongs to their own command families.
