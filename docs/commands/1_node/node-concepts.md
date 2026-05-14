# Node Concepts

This document defines node-family vocabulary and invariants. It supports the
node command contracts and the [node doctor](node-doctor.md); it does not
override the [Architecture](../../ARCHITECTURE.md).

## Role Vocabulary

Each term below has a precise meaning in the node command family.

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
- **Caller role:** The role recorded on the gateway-owned node record that
  authenticates a CLI request (`control`, `gateway`, or `app`). The gateway
  derives the caller role from the presented WireGuard peer identity and uses
  it for authorization. The CLI does not store or check this role locally.

## Role Platform Support

Each role is supported on a specific set of host platforms.

| Role | Supported platforms |
| --- | --- |
| `control` | macOS, Ubuntu |
| `gateway` | Ubuntu |
| `app` | Ubuntu |

Commands that provision a host or apply node-side artifacts must verify that the
observed host platform is supported for the node's role before side effects.
Registry-only commands use stored gateway metadata and do not perform live
platform checks; platform drift belongs to `doctor --family=node`.

## Identity and onboarding

These terms describe how nodes join the fleet and prove their identity to the gateway.

- **Node identity:** The node record that the gateway owns, plus its WireGuard peer
  identity, assigned WireGuard address, role, and node name.
- **First-gateway bootstrap:** The one allowed no-gateway path. A control node
  provisions the first gateway over SSH, creates the initiating control node
  identity, installs local trust and gateway config, and verifies gateway API
  access.
- **Control-node enrollment:** A two-machine path: the gateway mints the
  control node identity through `node:new --role=control`; the control machine
  installs that WireGuard identity and runs `gateway:add`.
- **Compatible existing node:** An active node whose role is known to the gateway
  and whose role, identity, host, app-node environment, and development TLD match the resolved
  command input for the requested path.

## Transport and authority

These terms describe how nodes communicate and how authority is enforced.

- **CLI-to-gateway edge:** HTTPS over WireGuard from control nodes, app-node CLI
  clients, or the gateway-local CLI to the gateway API.
- **Gateway-to-app-node edge:** SSH through `RemoteShell` for node-side
  applying.
- **App-node event ingestion:** Narrow app-node-to-gateway callbacks for
  purpose-built lifecycle events, not app-node control-plane authority.
- **Node reality:** Observed role, platform, WireGuard, SSH, reachability, and
  gateway runtime readiness for a node.

## Access Policy

These terms define the relationship model for node access grants.

- **Consuming node:** The node that receives permission to make an Orbit
  request.
- **Serving node:** The node that may be accessed by that request.

Node access grants are gateway-owned policy. They are not transport-specific,
do not grant SSH, and do not replace WireGuard authentication.

## Development DNS Mapping

These terms describe how the gateway maintains DNS resolution for development app nodes.

- **Gateway-owned development DNS mapping:** Node-family gateway configuration
  and gateway-local resolver reality that maps `*.{nodes.tld}` for an active
  development app node to that node's WireGuard address. The gateway owns this mapping.
- **Development DNS configuration model:** Derived from the active app-node row.
  A mapping exists only when the node row is an active development app node,
  `nodes.tld` is non-empty, and the node row has a non-empty WireGuard address.
  The canonical domain is `*.{nodes.tld}` and the canonical target is the
  node's WireGuard address.
- **Development DNS applier:** Internal node-family gateway service that
  converges or removes resolver artifacts on the gateway from
  the derived configuration model. It is used by app-node provisioning, app-node
  adoption and materialization, node removal, and `doctor --family=node --restore`.
- **Development DNS probe:** Internal node-family gateway service that reads
  gateway-local resolver reality for derived development DNS configuration and
  reports node-family drift when the mapping is absent, points at another
  target, or is publicly exposed.

Development DNS mappings are not a public `dns:*` command surface and do not
create a `dns` state family. The `dns:*` commands own only the resolver overrides
local to the caller. The node family owns the gateway mapping lifecycle because it is
part of development app-node readiness.

## Node Family Boundaries

The node family owns fleet membership, node roles, supported platforms, gateway
configuration, node identity, app-node reachability from the gateway, access
policy, gateway runtime readiness, gateway-owned development DNS mappings, and
node lifecycle checks.

The node family does not own app registration, workspace registration, process
or schedule definitions, proxy route lifecycle, tool registration, or editable
firewall policy beyond role bootstrap requirements. Those domains may depend on
nodes, but their configuration belongs to their own command families.
