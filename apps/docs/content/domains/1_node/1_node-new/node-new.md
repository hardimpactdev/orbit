# `orbit node:new [name]`

[Back to Nodes commands.](../README.md)

Register a new node identity in the Orbit fleet and optionally assign its
initial roles.

Use `node:new` when adding capacity to the fleet. Gateway and nodes are
provisioned over SSH when needed. Clients are enrolled by the gateway so
they can join the Orbit WireGuard network and then run `gateway:add`.
The first gateway bootstrap is the exception: when a client with no
configured gateway creates the first gateway, the command also onboards that
initiating client and stores the local gateway configuration.

## Usage

Run this command to register a new node and provision it when required.

```bash
orbit node:new [name] [--role=<role>]... [--host=<host>] [--control-name=<name>] [--tld=<tld>] [--user=<user>] [--ingress=<node>] [--redis-node=<node>] [--s3-data-path=<path>] [--self-grant=<mode>] [--agent-tool=<tool>]... [--grant-to=<node|all>] [--grant-to-preset=<preset>] [--grant-to-permissions=<list>] [--grant-from=<node|all>] [--grant-from-preset=<preset>] [--grant-from-permissions=<list>] [--json]
orbit node:new
```

The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request based on the caller's gateway-known
role. First-gateway bootstrap is the exception, because no gateway exists yet
to authenticate against.

## Examples

```bash
orbit node:new client-1
orbit node:new dev-1 --role=app-dev --host=app-1.ssh.example.com --tld=test
orbit node:new edge-1 --role=ingress --host=203.0.113.20
orbit node:new web-1 --role=app-prod --role=ingress --host=203.0.113.21
orbit node:new web-2 --role=app-prod --ingress=edge-1 --host=203.0.113.22
orbit node:new realtime-1 --role=websocket --host=203.0.113.30 --redis-node=db-1
orbit node:new storage-1 --role=s3 --host=203.0.113.31 --s3-data-path=/srv/orbit/s3/data
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --control-name=control-1
orbit node:new agent-1 --role=agent --host=192.0.2.10 --tld=agent --self-grant=default
orbit node:new agent-1 --role=agent --host=192.0.2.10 --agent-tool=openclaw --agent-tool=hermes
orbit node:new agent-1 --role=agent --host=192.0.2.10 --grant-to=all --grant-to-preset=operator
```

## Arguments and options

- `name`: unique node slug in the gateway registry, unless the command is
  converging or adopting a compatible existing node.
- `--role`: repeatable initial role assignment. Supported roles:
  `app-dev`, `app-prod`, `app-development`, `app-production`, `database`,
  `agent`, `ingress`, `websocket`, and `s3`. `app-dev` maps to `app-development`;
  `app-prod` maps to `app-production`. No assigned role means a client identity.
  `gateway` remains an internal
  bootstrap path. `agent` is exclusive and may only be selected during
  `node:new`; combining it with another role fails before side effects.
- `--host`: required for gateway bootstrap and for any initial role that
  provisions a host. This is the SSH/bootstrap endpoint and never the canonical
  node address.
- `--control-name`: initiating client name for first-gateway bootstrap
  (a client with no configured gateway running `--role=gateway`).
  Defaults to the normalized local short hostname. Forbidden outside
  first-gateway bootstrap.
- `--tld`: required for `app-development`. Used by `agent` as the agent
  TLD (default `agent`). Must be a single lowercase DNS label without a
  leading dot.
- `--user`: Bootstrap SSH user for provisioning. Defaults to `root`, but
  users from cloud images, such as `ubuntu`, remain valid. This value is only
  used for the first SSH path that creates or verifies Orbit's managed user.
  After provisioning, `nodes.user` is `orbit`, and operator SSH access is
  through that gateway-managed user; root SSH login and password login are
  disabled.
- `--ingress`: existing active `ingress` node to use when
  creating a private `app-production` backend node that does not serve public
  traffic itself.
- `--redis-node`: existing active `database` node whose Redis service backs a
  requested `websocket` role. Required when `--role=websocket` is selected.
- `--s3-data-path`: host path mounted into the RustFS container as `/data`.
  Optional when `--role=s3` is selected; defaults to `/srv/orbit/s3/data`.
  Must be an absolute path.
- `--self-grant`: `default` to apply the role-union self-preset, `custom`
  to drive the self-grant interactively, or omitted to fall back to the
  documented default for non-interactive runs (`default`).
- `--agent-tool`: repeatable. Names an agent tool slug to install during
  provisioning when `--role=agent`. Forbidden when the node has no `agent`
  role. Zero, one, or many may be supplied; no default agent tool is
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

## Workload Roles

**Client identity**

Creates a client identity with no role assignments by default. The
`--role=operator` option uses this client identity shape for an operator
node: a node that operates one or more nodes through the gateway by grant.

**`app-dev` / `app-development`**

Provisions a host-capable node identity and creates an active role
assignment with settings that include `tld`.

Requires `--host` and non-empty `--tld`.

**`app-prod` / `app-production`**

Provisions a host-capable node identity and creates an active role assignment
whose settings point at the selected ingress node.

Requires `--host`.

Interactive `node:new --role=app-prod` asks:

```text
Serve public traffic from this node? [yes]
```

Answering `yes` creates a colocated production node with both
`app-production` and `ingress`. Answering `no` creates a private backend
node and requires an existing active `ingress` node.

**`database`**

Creates an active role assignment for database responsibilities. It may
be combined with `app-development`, `websocket`, and `s3` on the same
provisioned host.

**`websocket`**

Provisions a private realtime node and creates an active role assignment whose
settings point at the selected Redis node. The role runs Laravel Reverb in a
Docker runtime container managed by Orbit, binds only to the node's WireGuard
address, and receives traffic through router-owned private service routes.

Requires `--host` and `--redis-node`. `--redis-node` must name an active
`database` role node with Redis expected or installed. The websocket role uses
that Redis service but does not install or own Redis.

**`s3`**

Provisions a private object-storage node and creates an active role assignment
whose settings include the RustFS data path. The role runs RustFS in a Docker
runtime container rendered by Orbit, binds the S3 API only to the node's
WireGuard address, and receives traffic through router-owned private service
routes. Public S3 hosts, when published, enter through `ingress` and then
forward to `router`; ingress must not route directly to S3 nodes.

Requires `--host`. `--s3-data-path` is optional and defaults to
`/srv/orbit/s3/data`. The path must be absolute, is mounted into the RustFS
container as `/data`, and is role-owned persistent data. Removing the role
without `--purge-data` must not delete it.

`app-development` and `app-production` are mutually exclusive. In v1,
`gateway`, `vpn`, and `router` are gateway-coupled and conflict with
`app-development`, `app-production`, `database`, `agent`, `ingress`, and
`websocket`, and `s3`.
The `agent` role conflicts with `gateway`, `vpn`, `router`,
`app-development`, `app-production`, `database`, `ingress`, `websocket`, and
`s3`. `ingress` may combine only with `app-production`. `websocket` and `s3`
may combine with `app-development`, `database`, and each other.
`gateway`, `vpn`, and `router` are not command-assignable through the
public role flow.

**`agent`**

Provisions an isolated agent host. The `agent` role assignment carries a
`tld` setting (default `agent`) and applies a baseline of `orbit-runtime`,
`orbit-caddy`, WireGuard/node identity material, and the shared
unprivileged `agent` runtime user. Supervisor is only used for explicit
residual runtime units where a tool or process contract configures it.

Requires `--host`. `--tld` is optional; the default is `agent`. The TLD
must be unique across active TLD-backed role assignments.

`--agent-tool=<tool>` may be repeated to select agent tools to install
during provisioning. Supported agent tools are `openclaw` and `hermes`.
Selecting more than one agent tool emits the same multiple-agent-tool
warning that `tool:install` uses: interactive callers confirm before
proceeding, machine-readable callers receive a structured
`tool.multiple_agent_tools_running` warning and the command proceeds. No
agent tool is installed when `--agent-tool` is omitted.

`agent` cannot be added through `node role:add`. Combining `--role=agent`
with `--role=app-development`, `--role=app-production`, `--role=database`,
or `--role=gateway` fails before side effects.

**Gateway bootstrap**

Bootstraps or adopts the gateway node that owns fleet configuration, WireGuard
identity, gateway APIs, and node access policy.

When a client with no configured gateway bootstraps the first gateway,
`node:new --role=gateway` also handles initiating-client onboarding:

- mints and installs the initiating client's WireGuard identity named by `--control-name`;
- trusts the gateway CA and stores the local gateway endpoint;
- creates the initiating client-to-gateway gateway-admin grant;
- verifies gateway API access.

After that successful flow, the initiating client does not run `gateway:add`.

Gateway bootstrap also installs the runtime substrate for the gateway-coupled
`vpn` role:

- `wg-easy` (the active `vpn` role WireGuard server runtime) is installed under
  `~/.config/orbit/wg-easy/`. The admin password is generated and persisted as
  `WG_EASY_PASSWORD` in `apps/gateway/.env` so that wg-easy v15 can run
  unattended setup.
- `wg-easy` owns UDP `51820`. The gateway host's `wg-orbit` interface is
  configured as a peer/client of `wg-easy`, not as a second WireGuard server.
- `orbit-dns` (a dnsmasq container) is installed under `~/.config/orbit/`,
  sharing wg-easy's network namespace. DNS for fleet TLDs is served by the
  gateway-coupled `vpn` role on the wg-easy WG IP. The initial
  `dnsmasq.conf` reflects the current
  `node.tld` + `node.wireguard_address` state and is kept in sync by later
  `node:new`, `node:update`, and `node:remove` calls.

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

## What Happens

`node:new` writes the node identity first, then creates each requested initial
role assignment. Development app bootstrap includes the node TLD and the
gateway development DNS mapping for that TLD.

For provisioned Linux nodes, `node:new` configures node-owned security policy
by default. That policy belongs to the `node` family and may surface as
`node.security.*` doctor findings. `node:new` does not configure tools,
user-facing firewall rules, apps, or workspaces.

If initial role validation fails, the command stops before provisioning
or writing the node identity. If an initial role is persisted but its
first convergence fails, the command fails and leaves that role assignment in
`error` for later doctor recovery.

During hosted-node provisioning, `provisioning` is a transient node status for a
row that is being baked. A failed bake deletes the provisional node row instead
of leaving a permanent failed node identity.

`node:new` does not detect, infer, or store public IPv4/IPv6 metadata. The
provided `--host` is treated as the operator-supplied SSH/bootstrap endpoint;
for first-gateway bootstrap it also seeds the initial gateway endpoint used in
generated WireGuard peer configs. Public IP metadata, when needed, is recorded
explicitly with [`node:update`](../7_node-update/node-update.md) and is not drift-checked
by node doctor.

`node:new` does not set the local default development node. Run
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

For `--role=app-prod` / `--role=app-production`, non-interactive input must choose placement
explicitly:

- `--role=app-prod --role=ingress` serves public traffic from the
  same node.
- `--role=app-prod --ingress=<node>` creates a private backend
  app-production node that uses an existing active ingress node.

## Output

Expect progress in human output while the command validates input, provisions
or enrolls the node, writes gateway state, and verifies readiness.

Add `--json` when you need a machine-readable payload. The JSON output gives
you the command result action, node name, role, lifecycle status,
platform-version identifier, environment when applicable, development TLD when
applicable, provisioning status, explicit node addresses, and any returned
WireGuard configuration for client enrollment.

Read the addresses carefully: the JSON distinguishes the SSH/bootstrap
endpoint from the Orbit WireGuard address, the gateway endpoint used in
generated peer configs, and the public IPv4/IPv6 metadata that you recorded
when already present.

## Requirements

- Gateway and app provisioning require SSH access to a target host whose
  platform is supported by the requested role. See the node-family
  [role platform support](../node-concepts.md#role-platform-support) matrix.

For first-gateway bootstrap:

- Requires local permission to install the initiating client's WireGuard
  identity, trust the gateway CA, and store local gateway configuration. A
  successful first-gateway bootstrap completes local onboarding; do not run
  `gateway:add` on that initiating client afterward.
- Requires a resolved initiating client name. Defaults to the
  normalized local short hostname.
- Installs Docker Engine and Docker Compose on Ubuntu gateway hosts when they
  are missing, because the gateway-coupled `vpn` role runs the WireGuard
  server runtime and VPN-served DNS runtime as containers.

For app-role creation:

- Requires an existing gateway and a resolved SSH/bootstrap endpoint.
- Development app-role creation requires a unique development TLD.

For client enrollment:

- Must run against the gateway so the gateway can mint the WireGuard identity
  and matching node record.
- After enrolling a client, install the returned WireGuard configuration,
  join the Orbit network, then run `orbit gateway:add`.

## Technical Contract

See [`node:new` technical contract](technical/1_node-new.md).
