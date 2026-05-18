# Node Concepts

This document defines node-family vocabulary and invariants. It supports the
node command contracts and the [node doctor](node-doctor.md); it does not
override the [Architecture](../../architecture.md).

## Role Vocabulary

Each term below has a precise meaning in the node command family.

- **Node:** A gateway-owned fleet member with a stable name, role assignments,
  platform, identity, reachability metadata, and access policy.
- **Gateway:** Special singleton authority role that owns durable Orbit state,
  the typed API, WireGuard coordination, root CA material, DNS coordination,
  node access policy, and doctor convergence. `gateway` is stored as a role
  assignment, but normal hosted-role mutation does not add it.
- **Joined client:** CLI caller configured to use a gateway. A joined client
  stores local gateway configuration and WireGuard identity material, but it
  is not a hosted role and does not orchestrate hosted nodes directly.
- **Hosted node:** Workload host for apps, workspaces, databases, and managed
  runtime artifacts. A hosted node may run the Orbit CLI as a stateless gateway
  client, but it is not a control plane.
- **Hosted role:** A fixed code-defined bundle attached through a role
  assignment. v1 hosted roles are `app-development`, `app-production`, and
  `database`.
- **Role assignment:** Gateway-owned record that attaches one role to one node,
  carries any role-specific settings, and tracks convergence status.
- **Hosted role settings:** Assignment-local configuration for a hosted role.
  Role-local desired configuration lives on the role assignment, not on the
  generic node record.
- **Role assignment status:** Lifecycle state of one role assignment:
  `pending`, `active`, `error`, or `removing`. Eligibility checks use only
  active assignments. Compatibility checks treat `active`, `pending`, and
  `error` assignments as unresolved conflicts and ignore `removing`.
- **Caller identity:** The gateway-known WireGuard identity that authenticates a
  CLI request. Operation is WireGuard identity plus gateway grants, not an
  operator role. The CLI does not store or check a caller role locally.

## Role Compatibility

Assignments in `active`, `pending`, or `error` must satisfy this matrix:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | none | `app-development`, `app-production`, `database` |
| `app-development` | `database` | `gateway`, `app-production` |
| `app-production` | `database` | `gateway`, `app-development` |
| `database` | `app-development`, `app-production` | `gateway` |

Compatibility checks treat assignments in `active`, `pending`, or `error` as
unresolved conflicts. Assignments already in `removing` are ignored.

## Role Settings

Role-local desired configuration lives on the role assignment, not on the
generic node record. Each role assignment has typed settings:

| Role | Settings |
| --- | --- |
| `app-development` | `tld` |
| `app-production` | none in v1 |
| `database` | none in v1 |
| `gateway` | none in v1 |

Changing role settings is a desired-state change and triggers the same baseline
convergence path as adding the role.

## Hosted Role Baselines

Role baselines are code-defined desired state, not editable package lists.

| Role | Baseline intent |
| --- | --- |
| `app-development` | Development DNS mapping and `sqlite3` as an installed local utility |
| `app-production` | `caddy`, `php`, and `supervisor` running, plus `sqlite3` as an installed local utility |
| `database` | Docker running as the substrate for managed database service tools |

Database clients belong to the service tool that needs them. The `postgres`
tool installs `postgresql-client`, and the `mysql` tool installs
`default-mysql-client`; the `database` role baseline does not install them
preemptively.

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

| Role kind | Supported platforms |
| --- | --- |
| `gateway` | Ubuntu |
| joined client | macOS, Ubuntu |
| `app-development` | Ubuntu |
| `app-production` | Ubuntu |
| `database` | Ubuntu |

Commands that provision a host or apply node-side artifacts must verify that the
observed host platform is supported for the node's gateway role assignment or
active hosted roles before side effects.
Registry-only commands use stored gateway metadata and do not perform live
platform checks; platform drift belongs to `doctor --family=node`.

## Identity and onboarding

These terms describe how nodes join the fleet and prove their identity to the gateway.

- **Node identity:** The node record that the gateway owns, plus its WireGuard
  peer identity, assigned WireGuard address, role assignments, and node name.
- **First-gateway bootstrap:** The one allowed no-gateway path. A joined client
  provisions the first gateway over SSH, creates the initiating joined-client
  identity, installs local trust and gateway config, and verifies gateway API
  access.
- **Joined-client enrollment:** A two-machine path: the gateway mints the
  joined-client identity, the client machine installs that WireGuard identity,
  and then runs `gateway:add`.
- **Compatible existing node:** An active node whose role assignments are known
  to the gateway and whose role assignments, identity, host, and
  assignment-local settings match the resolved command input for the requested
  path.

## Transport and authority

These terms describe how nodes communicate and how authority is enforced.

- **CLI-to-gateway edge:** HTTPS over WireGuard from joined clients, hosted-node
  CLI clients, or the gateway-local CLI to the gateway API.
- **Gateway-to-hosted-node edge:** SSH through `RemoteShell` for node-side
  applying.
- **Hosted-node event ingestion:** Narrow hosted-node-to-gateway callbacks for
  purpose-built lifecycle events, not hosted-node control-plane authority.
- **Node reality:** Observed role assignments, assignment status, platform,
  WireGuard, SSH, reachability, and gateway runtime readiness for a node.

## Access Policy

These terms define the relationship model for node access grants.

- **Consuming node:** The node that receives permission to make an Orbit
  request.
- **Serving node:** The node that may be accessed by that request.

Node access grants are gateway-owned policy. They are not transport-specific,
do not grant SSH, and do not replace WireGuard authentication.

## Development DNS Mapping

These terms describe how the gateway maintains DNS resolution for development
hosted nodes.

- **Gateway-owned development DNS mapping:** Node-family gateway configuration
  and gateway-local resolver reality that maps `*.{tld}` for an active
  `app-development` role assignment to that node's WireGuard address. The
  gateway owns this mapping.
- **Development DNS configuration model:** Derived from the active
  `app-development` role assignment. A mapping exists only when that assignment
  is active, its `tld` setting is a single lowercase DNS label without a leading
  dot, and the node row has a non-empty WireGuard address.
  The canonical domain is `*.{tld}` and the canonical target is the
  node's WireGuard address.
- **Development DNS applier:** Internal node-family gateway service that
  converges or removes resolver artifacts on the gateway from
  the derived configuration model. It is used by hosted-node provisioning,
  hosted-node adoption and materialization, node removal, and
  `doctor --family=node --restore`.
- **Development DNS probe:** Internal node-family gateway service that reads
  gateway-local resolver reality for derived development DNS configuration and
  reports node-family drift when the mapping is absent, points at another
  target, or is publicly exposed.

Development DNS mappings are not a public `dns:*` command surface and do not
create a `dns` state family. The `dns:*` commands own only the resolver overrides
local to the caller. The node family owns the gateway mapping lifecycle because it is
part of development hosted-role readiness.

## Node Family Boundaries

The node family owns fleet membership, node roles, role assignments, supported
platforms, gateway configuration, node identity, hosted-node reachability from
the gateway, access policy, gateway runtime readiness, gateway-owned
development DNS mappings, and node lifecycle checks.

The node family does not own app registration, workspace registration, process
or schedule definitions, proxy route lifecycle, tool registration, or editable
firewall policy beyond role bootstrap requirements. Those domains may depend on
nodes, but their configuration belongs to their own command families.
