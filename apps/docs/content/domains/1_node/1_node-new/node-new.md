# `orbit node:new [name]`

[Back to Nodes commands.](../README.md)

Register a new node identity in the Orbit fleet and optionally assign its
initial roles.

Use `node:new` when adding capacity to the fleet. A configured client uses its
own SSH access to install the minimal WireGuard, Orbit CLI, and Agent bootstrap
on a new workload node; the gateway then finishes provisioning through Agent
push. The gateway never opens target SSH. Clients are enrolled by the gateway
so they can join the Orbit WireGuard network and then run `gateway:add`.
The first gateway bootstrap is the exception: when a client with no
configured gateway creates the first gateway, the command also onboards that
initiating client and stores the local gateway configuration.

## Usage

Run this command to register a new node and provision it when required.

```bash
orbit node:new [name] --tld=<tld> [--template=<template>] [--operator] [--roles=<roles>] [--host=<host>] [--operator-name=<name>] [--operator-tld=<tld>] [--user=<user>] [--ingress=<node>] [--valkey-node=<node>] [--postgres-node=<node>] [--postgres-process=<process>] [--clickhouse-node=<node>] [--s3-data-path=<path>] [--self-grant=<mode>] [--agent-tool=<tool>]... [--grant-to=<node|all>] [--grant-to-preset=<preset>] [--grant-to-permissions=<list>] [--grant-from=<node|all>] [--grant-from-preset=<preset>] [--grant-from-permissions=<list>] [--json|--stream-json]
orbit node:new
```

The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request against the scoped permission set on
the caller's grant to the gateway (`node:new` permission, or the `gateway-admin`
preset). First-gateway bootstrap is the one no-gateway path; the bootstrap flow
materializes the initial gateway-admin grant from the initiating
operator-identity client to the new gateway.

## Examples

```bash
orbit node:new client-1 --tld=client
orbit node:new operator-1 --operator --tld=operator
orbit node:new dev-1 --template=app-development --host=app-1.ssh.example.com --tld=test
orbit node:new dev-1 --roles=app-dev --host=app-1.ssh.example.com --tld=test
orbit node:new edge-1 --template=ingress --host=203.0.113.20 --tld=edge
orbit node:new web-1 --template=app-production --host=203.0.113.21 --tld=web
orbit node:new web-2 --roles=app-prod --ingress=edge-1 --host=203.0.113.22 --tld=web-two
orbit node:new realtime-1 --template=websocket --host=203.0.113.30 --valkey-node=db-1 --tld=realtime
orbit node:new storage-1 --template=s3 --host=203.0.113.31 --s3-data-path=/srv/orbit/s3/data --tld=storage
orbit node:new metrics-1 --template=metrics --host=203.0.113.40 --tld=metrics
orbit node:new app-1 --roles=app-dev,metrics --host=203.0.113.41 --tld=test
orbit node:new analytics-1 --template=analytics --host=203.0.113.32 --postgres-node=db-1 --postgres-process=postgres --clickhouse-node=db-1 --tld=analytics
orbit node:new gateway-1 --template=gateway --host=203.0.113.2 --tld=gateway --operator-name=operator-1 --operator-tld=operator
orbit node:new agent-1 --template=agent --host=192.0.2.10 --tld=agent --self-grant=default
orbit node:new agent-1 --roles=agent --host=192.0.2.10 --tld=agent --agent-tool=openclaw --agent-tool=hermes
orbit node:new agent-1 --roles=agent --host=192.0.2.10 --tld=agent --grant-to=all --grant-to-preset=operator
```

## Arguments and options

- `name`: unique node slug in the gateway registry, unless the command is
  converging or adopting a compatible existing node.
- `--template`: named provisioning template that expands to a role composition
  before validation. Supported templates: `operator`, `app-development`,
  `app-production`, `gateway`, `ingress`, `database`, `s3`, `websocket`,
  `metrics`, `analytics`, and `agent`. Mutually exclusive with `--roles` and with
  `--operator` except for `--template=operator`.
- `--operator`: create a client identity with the operator permission preset and
  no workload role assignments. Operator is not a node role; use this flag
  instead of a role value. Mutually exclusive with `--roles` and with
  `--template` values that carry a workload role, other than `operator`.
- `--roles`: role assignments as a comma-separated list, for programmatic callers
  that need an explicit composition instead of a template. Supported role
  values are `app-dev`, `app-prod`, `database`, `agent`, `ingress`,
  `websocket`, `s3`, `metrics`, and `analytics`. Role aliases are not accepted;
  `app-development` and `app-production` are template names only. No assigned role means a
  client identity. `gateway`, `vpn`, and `router` are not accepted through
  `--roles`; use `--template=gateway`. `agent` is exclusive and may only be
  selected during `node:new`; combining it with another role fails before side
  effects.
- `--host`: required for gateway bootstrap and for every path that provisions a
  workload role (`app-dev`, `app-prod`, `ingress`, `agent`, `websocket`, `s3`,
  `metrics`, `analytics`, and gateway bootstrap/convergence). Forbidden for bare client identities,
  `--operator`, and `database`-only identities that do not provision a host.
  This is the SSH/bootstrap endpoint and never the canonical node address.
- `--operator-name`: initiating client name for first-gateway bootstrap
  (a client with no configured gateway running gateway bootstrap via
  `--template=gateway`). Required and forbidden outside first-gateway bootstrap.
- `--operator-tld`: explicit TLD for the initiating operator identity during
  first-gateway bootstrap. Required there, forbidden elsewhere, and must differ
  from the gateway TLD. The reserved value `orbit` is invalid.
- `--tld`: required unique TLD for every node identity. There is no name-based
  default. Agent and development role features consume this node-owned field
  for their DNS mappings. Must be a single lowercase DNS label without a
  leading dot and must not be the reserved value `orbit`.
- `--user`: Bootstrap SSH user used by the initiating CLI. Defaults to `root`, but
  users from cloud images, such as `ubuntu`, remain valid. This value is only
  used only for the client-to-target SSH path that creates or verifies Orbit's
  managed user. SSH keys remain on the initiating client and are never sent to
  the gateway.
  After provisioning, `nodes.user` is normally `orbit`, the owner/runtime user
  used when the Agent executes work on that node. Root SSH login and password
  login are disabled. Break-glass access belongs to the operator and remains
  outside Orbit commands.
- `--ingress`: existing active `ingress` node to use when
  creating a private `app-prod` backend node that does not serve public
  traffic itself.
- `--valkey-node`: existing active `database` node whose Valkey service backs a
  requested `websocket` role. Required when `--roles` includes `websocket`.
- `--postgres-node`: existing active `database` node whose PostgreSQL service
  backs a requested `analytics` role. Required when `--roles` includes
  `analytics`.
- `--postgres-process`: node-owned PostgreSQL 16 `postgres` service process on
  the selected `--postgres-node`. Required when `--roles` includes `analytics`;
  Orbit stores the process ID in the role settings. Other PostgreSQL version
  families are rejected because Plausible requires PostgreSQL 16.
- `--clickhouse-node`: existing active `database` node whose ClickHouse service
  backs a requested `analytics` role. Required when `--roles` includes
  `analytics`.
- `--s3-data-path`: host path mounted into the SeaweedFS container as `/data`.
  Optional when `--roles` includes `s3`; defaults to `/srv/orbit/s3/data`.
  Must be a canonical path under `/media`, `/mnt`, `/opt/orbit`, `/srv`, or
  `/var/lib/orbit`.
- `--self-grant`: `default` to apply the role-union self-preset, `custom`
  to drive the self-grant interactively, or omitted to fall back to the
  documented default for non-interactive runs (`default`).
- `--agent-tool`: repeatable. Names an agent tool slug to install during
  provisioning when `--roles` includes `agent`. Forbidden when the node has no
  `agent` role. Zero, one, or many may be supplied; no default agent tool is
  installed when this flag is omitted.
- `--grant-to`: node selector (a specific node name or `all`) for grants
  from the new node to other nodes. `all` expands to all current eligible
  serving nodes only; future nodes are not auto-granted.
- `--grant-to-preset` / `--grant-to-permissions`: initial permission set
  for the `--grant-to` direction. Mutually exclusive.
- `--grant-from`: node selector (a specific node name or `all`) for grants
  from other nodes to the new node. `all` expands to all current eligible
  consuming nodes only.
- `--grant-from-preset` / `--grant-from-permissions`: initial permission
  set for the `--grant-from` direction. Mutually exclusive.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON for gateway-mediated
  provisioning. Mutually exclusive with `--json`; unsupported for
  first-gateway bootstrap.

## Templates At A Glance

Each template pre-selects a role set and provisioning path. Use a template when you want a standard composition; use `--roles` for explicit control.

| Template | Roles | Optional add-ons | Requires `--host` | Status |
| --- | --- | --- | --- | --- |
| `operator` | (none; client identity with operator preset) | — | no | live |
| `app-development` | `app-dev` + `database` | `s3`, `websocket` | yes | live |
| `app-production` | `app-prod` + `ingress` (colocated) or `app-prod` alone (requires `--ingress=<node>`) | — | yes | live |
| `gateway` | `gateway` + `vpn` + `router` | — | yes | live |
| `ingress` | `ingress` | — | yes | live |
| `database` | `database` | `s3`, `websocket`, `analytics` | yes | live |
| `s3` | `s3` | — | yes | live |
| `websocket` | `websocket` | — | yes | implementation pending |
| `metrics` | `metrics` | — | yes | live |
| `analytics` | `analytics` | — | yes | live |
| `agent` | `agent` | agent tools via `--agent-tool=` | yes | live |

> **Status:** The `websocket` template is documented so the CLI surface stays
> stable, but current behavior fails before side effects with
> `validation_failed` until the WebSocket implementation lands. See the
> [JSON renderer contract](technical/6.2_node-new_output-render_json.md#validation-error)
> for the exact machine-readable failure shape.

## Templates

**Client identity**

Creates a client identity with no role assignments by default. Use
`--operator` (or `--template=operator`) for a client identity that receives the
operator permission preset. Operator is not a node role.

**`app-development` template**

Expands to `app-dev` + `database` and may optionally add `s3` and `websocket`
on the same host.

Requires `--host` and non-empty `--tld`.

**`app-production` template**

Expands to `app-prod` plus either colocated `ingress` or a private `app-prod`
backend that requires `--ingress=<node>`.

Requires `--host`.

Interactive `node:new --template=app-production` asks:

```text
Serve public traffic from this node? [yes]
```

Answering `yes` creates a colocated production node with both `app-prod` and
`ingress`. Answering `no` creates a private backend node and requires an
existing active `ingress` node.

**`database` template**

Creates an active `database` role assignment. It may combine with `app-dev`,
`websocket`, `s3`, and `analytics` on the same provisioned host.

Requires `--host` when the template provisions a host.

**`websocket` template**

Provisions a private realtime node and creates an active `websocket` role
assignment whose settings point at the selected Valkey node.

Requires `--host` and `--valkey-node`. Implementation pending.

**`s3` template**

Provisions a private object-storage node and creates an active `s3` role
assignment whose settings include the SeaweedFS data path.

Requires `--host`. The initiating client installs the managed-node bootstrap
over SSH before the gateway converges the SeaweedFS process and credentials.

**`metrics` template**

Provisions a private host-resource observability node and creates an active
`metrics` role assignment. The role baseline records Docker substrate intent,
records and starts node-exporter host binary/tool-backed systemd process
runtimes, records and starts Prometheus and Grafana Docker Swarm process
runtimes, records the router-owned `metrics.orbit` route, and generates Grafana
admin credentials.

Requires `--host`.

**`analytics` template**

Provisions a private analytics node and creates an active `analytics` role
assignment whose settings point at the selected PostgreSQL 16 process and
ClickHouse service node. Plausible runs as a node-owned Docker process published only on
the analytics node's WireGuard address. The fleet permits one analytics role
assignment. Provisioning rejects a second assignment before opening the target
bootstrap path, and succeeds only after `analytics.orbit` and its TLS material
are enacted on router.

Requires `--host`, `--postgres-node`, `--postgres-process`, and
`--clickhouse-node`.

**`agent` template**

Provisions an isolated agent host with the exclusive `agent` role assignment.

Requires `--host` and an explicit unique `--tld`. The agent role consumes the
node-owned TLD for its DNS mapping; it does not define a role-local default.

**Explicit `--roles` composition**

Use `--roles=<csv>` for advanced or generated compositions that should not use
a template. Every workload role in the explicit selection that provisions a
host requires `--host`. `--roles` is mutually exclusive with `--template` and
`--operator`.

**Role composition rules**

`app-dev` and `app-prod` are mutually exclusive. In v1, `gateway`, `vpn`, and
`router` are gateway-coupled and conflict with `app-dev`, `app-prod`,
`database`, `agent`, `ingress`, `websocket`, `s3`, and `analytics`; `metrics`
may be co-located with that gateway-coupled node. The `agent` role conflicts
with every other workload role. `ingress` may combine with `app-prod` and
`metrics`. `websocket` and `s3` may combine with `app-dev`, `database`,
`metrics`, `analytics`, and each other. `analytics` may also combine with
`database` and `metrics`, but conflicts with gateway-coupled infrastructure,
`app-prod`, `ingress`, and `agent`. `gateway`, `vpn`, and `router` are not
command-assignable through the public role flow.

**Gateway bootstrap**

Bootstraps or adopts the gateway node that owns fleet configuration, WireGuard
identity, gateway APIs, and node access policy. Use `--template=gateway`.

When a client with no configured gateway bootstraps the first gateway,
gateway bootstrap also handles initiating-client onboarding:

- mints and installs the initiating client's WireGuard identity named by `--operator-name`;
- trusts the gateway CA and stores the local gateway endpoint;
- creates the initiating client-to-gateway gateway-admin grant;
- verifies gateway API access.

After that successful flow, the initiating client does not run `gateway:add`.

Gateway bootstrap also installs the runtime substrate for the gateway-coupled
`vpn` role:

- `wg-easy` (the active `vpn` role WireGuard server runtime) is installed under
  `~/.config/orbit/wg-easy/`. The admin password is generated and persisted as
  `WG_EASY_PASSWORD` in `ORBIT_CONFIG_ROOT/.env` (default `~/.config/orbit/.env`) so that wg-easy v15 can run
  unattended setup.
- `wg-easy` owns UDP `51820`. The gateway host's `wg-orbit` interface is
  configured as a peer/client of `wg-easy`, not as a second WireGuard server.
- `orbit-dns` (a dnsmasq container) is installed under `~/.config/orbit/`,
  sharing wg-easy's network namespace. The gateway-local `dns` tool serves
  private fleet DNS on the wg-easy WG IP and is required by the
  gateway-coupled `vpn` role. Bootstrap materializes tool-owned base
  `dnsmasq.conf`, node-owned `dnsmasq.d/10-node-records.conf`, and proxy-owned
  `dnsmasq.d/20-proxy-records.conf`. Later `node:new`, `node:update`, and
  `node:remove` calls reconcile the two record projections together without
  rewriting the base file.

The full contract for the DNS substrate is
[`docs/domains/3_tool/dns-bootstrap-contract.md`](../../3_tool/dns-bootstrap-contract.md).

`--host` is required for every gateway request, including later gateway
convergence checks after a gateway already exists.

During first-gateway bootstrap, the resolved `--host` value becomes the initial
gateway endpoint used in generated WireGuard peer configs. It is also passed to
wg-easy as `INIT_HOST`. As a result, it must be an IP address or dotted DNS
name reachable by the nodes that will join the fleet.

Gateway bootstrap internally creates coupled `gateway`, `vpn`, and `router`
role assignments on the same node. Public role assignment does not accept
`gateway`, `vpn`, or `router`.

If the requested gateway is already provisioned and active, and the supplied host is
compatible with that gateway identity, Orbit converges idempotently without
reprovisioning and reports the already-provisioned status. If the gateway is
compatible but drifted or incomplete, `node:new` reports the drift and points to
`doctor --family=node --restore`. Destructive gateway reset is outside `node:new`
and requires a future explicit reset contract.

## Explicit role composition

When no `--template` or `--operator` is supplied, `--roles=<csv>` remains
available for explicit programmatic compositions. Canonical stored role values
are `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`,
`metrics`, and `analytics`. The sections below describe agent-tool and grant behavior that applies
regardless of whether a template or explicit role list was used.

**`agent` role details**

`--agent-tool=<tool>` may be repeated to select agent tools to install
during provisioning. Supported agent tools are `openclaw` and `hermes`.
Selecting more than one agent tool emits the same multiple-agent-tool
warning that `tool:install` uses: interactive callers confirm before
proceeding, machine-readable callers receive a structured
`tool.multiple_agent_tools_running` warning and the command proceeds. No
agent tool is installed when `--agent-tool` is omitted.

`agent` cannot be added through `node role:add`. Combining `--roles=agent`
with `app-dev`, `app-prod`, `database`, or gateway
bootstrap roles fails before side effects.

## What Happens

For host-provisioned workloads, `node:new` atomically reserves the pending node
identity, WireGuard peer, and bootstrap record after the initiating client has
observed a supported target platform and architecture. Completion creates each
requested initial role assignment, then atomically marks the node active and
the bootstrap complete. Development app bootstrap includes the node TLD and
node-owned wildcard DNS directives for that TLD. Every activated node also
receives its concrete `orbit.{tld}` record in the node projection.

For provisioned Linux nodes, `node:new` configures node-owned security policy
by default. That policy belongs to the `node` family and may surface as
`node.security.*` doctor findings. `node:new` does not configure tools,
user-facing firewall rules, apps, or workspaces.

If initial role validation fails, the command stops before provisioning
or writing the node identity. If an initial role is persisted but its
first convergence fails, the command fails and leaves that role assignment in
`error` for later doctor recovery.

During provisioning, `provisioning` is a transient node status: the pending
node, WireGuard peer, and bootstrap record exist in the gateway database, but client-local
bootstrap and the first Agent-push convergence have not completed. A compatible
retry first asks the gateway whether that initiating-client-bound request can
continue without SSH. An Agent-ready pending bootstrap or completed bootstrap
continues directly through completion; only an unreserved or not-yet-Agent-ready
bootstrap returns to client-local SSH.

The gateway serializes WireGuard address
allocation and enforces address uniqueness in durable node state, so concurrent
reservations cannot claim the same address. Readiness or convergence
failure remains inspectable until the bootstrap is retried or explicitly
removed.

The gateway also serializes completion for each bootstrap. Overlapping callers
wait for the winning request, refresh the terminal state, and return the same
active result without rerunning convergence or duplicating `node.created`.

`node:new` does not detect, infer, or store public IPv4/IPv6 metadata. The
provided `--host` is treated as the operator-supplied SSH/bootstrap endpoint;
for first-gateway bootstrap it also seeds the initial gateway endpoint used in
generated WireGuard peer configs. Public IP metadata, when needed, is recorded
explicitly with [`node:update`](../7_node-update/node-update.md) and is not drift-checked
by node doctor.

`node:new` does not set the local default node. Run
[`node:default`](../9_node-default/node-default.md) explicitly when the operator wants that
local targeting preference.

## Grant Setup

`node:new` always creates an explicit self-grant for the new node. Self-grants
are required for self-access; they are never implicit. The self-grant preset
for each role is the union of role-default self-grant permissions
across all active roles on the node. Permission conflicts between
roles indicate role incompatibility rather than deny rules.

For interactive runs, `node:new` asks two grant questions:

- "Does this node need access to other nodes?" Default `no`. When `yes`, the
  caller selects target serving nodes and the permission set for those
  outbound grants.
- "Should other nodes need access to this node?" Default `no`. When `yes`,
  the caller selects consuming nodes and the permission set for those
  inbound grants.

Non-interactive runs use the directional flags `--grant-to`,
`--grant-to-preset`, `--grant-to-permissions`, `--grant-from`,
`--grant-from-preset`, and `--grant-from-permissions`. The selector `all`
expands to every current eligible node only — future nodes are not added
automatically.

Agent setup does not offer `gateway-admin` by default. `node:new` itself
requires the caller to hold a grant to the gateway with `node:new` or `*`.
The normal way to grant that authority is the `gateway-admin` preset.

It does not configure tools, user apps, workspaces, processes, schedules,
firewall rules, or user proxy routes. Those are managed by their own commands
and by `doctor --family=<family> --restore` or `doctor --family=<family> --adopt`.

For `--template=app-production` or `--roles` that includes `app-prod`, non-interactive input must choose placement
explicitly:

- `--template=app-production` with colocated ingress, or
  `--roles=app-prod,ingress`, serves public traffic from the same node.
- `--roles=app-prod --ingress=<node>` creates a private backend `app-prod`
  node that uses an existing active ingress node.

## Output

Expect progress in human output while the command validates input, provisions
or enrolls the node, writes gateway state, and verifies readiness.

Add `--json` when you need a machine-readable payload. The JSON output gives
you the command result action, node name, role, lifecycle status,
platform-version identifier, development TLD when applicable, provisioning
status, explicit node addresses, and any returned WireGuard configuration for
client enrollment.

Read the addresses carefully: the JSON distinguishes the SSH/bootstrap
endpoint from the Orbit WireGuard address, the gateway endpoint used in
generated peer configs, and the public IPv4/IPv6 metadata that you recorded
when already present.

## Requirements

- Workload provisioning requires SSH access from the initiating client to a
  target host whose platform is supported by the requested role. The target
  must also be able to send WireGuard UDP to the gateway endpoint and reach the
  configured operating-system package repositories and release artifact host.
  The gateway does not need and must not have target SSH access. See the node-family
  [role platform support](../node-concepts.md#role-platform-support) matrix.

For first-gateway bootstrap:

- Requires local permission to install the initiating client's WireGuard
  identity, trust the gateway CA, and store local gateway configuration. A
  successful first-gateway bootstrap completes local onboarding; do not run
  `gateway:add` on that initiating client afterward.
- Requires explicit initiating client name and TLD values distinct from the
  gateway node identity.
- Installs Docker Engine and Docker Compose on Ubuntu gateway hosts when they
  are missing, because the gateway-coupled `vpn` role runs the WireGuard
  server runtime and VPN-served DNS runtime as containers.

For app-role creation:

- Requires an existing gateway and a resolved SSH/bootstrap endpoint reachable
  from the initiating client.
- Every node identity requires an explicit unique node TLD; `orbit` is reserved
  for the proxy-owned `.orbit` namespace.
- There is no manual no-SSH or public pre-WireGuard enrollment fallback.

For client enrollment:

- Must run against the gateway so the gateway can mint the WireGuard identity
  and matching node record.
- After enrolling a client, install the returned WireGuard configuration,
  join the Orbit network, then run `orbit gateway:add`.

## Technical Contract

See [`node:new` technical contract](technical/1_node-new.md).
