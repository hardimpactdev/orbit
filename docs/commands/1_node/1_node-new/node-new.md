# `orbit node:new [name]`

[Back to Nodes commands.](../README.md)

Register a new node identity in the Orbit fleet and optionally assign its
initial hosted roles.

Use `node:new` when adding capacity to the fleet. Gateway and app nodes are
provisioned over SSH when needed. Control nodes are enrolled by the gateway so
they can join the Orbit WireGuard network and then run `gateway:add`.
The first gateway bootstrap is the exception: when a control node with no
configured gateway creates the first gateway, the command also onboards that
initiating control node and stores the local gateway configuration.

## Usage

Run this command to register a new node and provision it when required.

```bash
orbit node:new [name] [--role=<hosted-role>]... [--host=<host>] [--control-name=<name>] [--environment=development|production] [--tld=<tld>] [--user=<user>] [--json]
orbit node:new
```

The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request based on the caller's gateway-known
role. First-gateway bootstrap is the exception, because no gateway exists yet
to authenticate against.

## Examples

```bash
orbit node:new client-1
orbit node:new dev-1 --role=app-development --host=app-1.ssh.example.com --tld=test
orbit node:new web-1 --role=app-production --role=database --host=203.0.113.20
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --control-name=control-1
```

## Arguments and options

- `name`: unique node slug in the gateway registry, unless the command is
  converging or adopting a compatible existing node.
- `--role`: repeatable initial hosted role assignment. Supported hosted roles:
  `app-development`, `app-production`, and `database`. No hosted role means a
  joined client/control identity. `gateway` remains an internal bootstrap path.
- `--host`: required for gateway bootstrap and for any initial hosted role that
  provisions a host. This is the SSH/bootstrap endpoint and never the canonical
  node address.
- `--control-name`: initiating control-node name for first-gateway bootstrap
  (a control node with no configured gateway running `--role=gateway`).
  Defaults to the normalized local short hostname. Forbidden outside
  first-gateway bootstrap.
- `--environment`: legacy compatibility input only. `--role=app
  --environment=development` maps to `--role=app-development`; `--role=app
  --environment=production` maps to `--role=app-production`.
- `--tld`: required for `app-development`. Development TLD for the node,
  without a leading dot.
- `--user`: SSH user for provisioning. Defaults to `root`. Stored as the
  steady-state `nodes.user` after the gateway-managed SSH user is set up.
- `--json`: Output JSON.

## Hosted Roles

**Client / control identity**

Creates a joined node identity with no hosted roles by default. This is the new
baseline meaning of `node:new <name>` with no `--role` values.

Legacy `--role=control` is accepted for one compatibility cycle and maps to the
same no-hosted-role identity. Human output warns when a legacy mapping is used.

**`app-development`**

Provisions a host-capable node identity and creates an active hosted role
assignment with settings that include `tld`.

Requires `--host` and non-empty `--tld`.

**`app-production`**

Provisions a host-capable node identity and creates an active hosted role
assignment with no extra settings.

Requires `--host`.

**`database`**

Creates an active hosted role assignment for database responsibilities. It may
be combined with `app-development` or `app-production` on the same provisioned
host.

`app-development` and `app-production` are mutually exclusive. Gateway conflicts
with every hosted role and is not command-assignable through the public hosted
role flow.

**Gateway bootstrap**

Bootstraps or adopts the gateway node that owns fleet configuration, WireGuard
identity, gateway APIs, and node access policy.

When a control node with no configured gateway bootstraps the first gateway,
`node:new --role=gateway` also mints and installs the initiating control node's
WireGuard identity named by `--control-name`, trusts the gateway CA, stores the
local gateway endpoint, and verifies gateway API access. After that successful
flow, the initiating control node does not run `gateway:add`.

Gateway bootstrap also installs the gateway-side DNS substrate:

- `wg-easy` (the WireGuard VPN server) is installed under
  `~/.config/orbit/wg-easy/`. The bcrypt admin password hash is generated and
  persisted as `WG_EASY_PASSWORD_HASH` in the gateway's `.env`.
- `orbit-dns` (a dnsmasq container) is installed under `~/.config/orbit/`,
  sharing wg-easy's network namespace, so it answers DNS for fleet TLDs on
  the wg-easy WG IP. The initial `dnsmasq.conf` reflects the current
  `node.tld` + `node.wireguard_address` state and is kept in sync by later
  `node:new`, `node:update`, and `node:remove` calls.

The full contract for the DNS substrate is
[`docs/commands/3_tool/dns-bootstrap-contract.md`](../../3_tool/dns-bootstrap-contract.md).

`--host` is required for every gateway request, including later gateway
convergence checks after a gateway already exists. During first-gateway
bootstrap, the resolved `--host` value becomes the initial gateway endpoint used
in generated WireGuard peer configs *and* is passed to wg-easy as `WG_HOST`,
so it must be an IP address or dotted DNS name reachable by the nodes that will
join the fleet.

Gateway bootstrap internally creates exactly one `gateway` hosted-role
assignment. Public hosted-role assignment does not accept `gateway`.

If the requested gateway is already provisioned and active, and the supplied host is
compatible with that gateway identity, Orbit converges idempotently without
reprovisioning and reports the already-provisioned status. If the gateway is
compatible but drifted or incomplete, `node:new` reports the drift and points to
`doctor --family=node --restore`. Destructive gateway reset is outside `node:new`
and requires a future explicit reset contract.

## What Happens

`node:new` writes the node identity first, then creates each requested initial
hosted role assignment. Development app bootstrap includes the node TLD and the
gateway development DNS mapping for that TLD.

If initial hosted-role validation fails, the command stops before provisioning
or writing the node identity. If an initial hosted role is persisted but its
first convergence fails, the command fails and leaves that role assignment in
`error` for later doctor recovery.

`node:new` does not detect, infer, or store public IPv4/IPv6 metadata. The
provided `--host` is treated as the operator-supplied SSH/bootstrap endpoint;
for first-gateway bootstrap it also seeds the initial gateway endpoint used in
generated WireGuard peer configs. Public IP metadata, when needed, is recorded
explicitly with [`node:update`](../7_node-update/node-update.md) and is not drift-checked
by node doctor.

`node:new` does not set the local default development app node. Run
[`node:default`](../9_node-default/node-default.md) explicitly when the operator wants that
local targeting preference.

It does not configure tools, user apps, workspaces, processes, schedules,
firewall rules, or user proxy routes. Those are managed by their own commands
and by `doctor --family=<family> --restore` or `doctor --family=<family> --adopt`.

## Output

Human output uses progress while the command validates input, provisions
or enrolls the node, writes gateway state, and verifies readiness.

JSON output includes the command result action, node name, role, lifecycle
status, platform-version identifier, environment when applicable, development
TLD when applicable, provisioning status, explicit node addresses, and any
returned WireGuard configuration for control-node enrollment. It distinguishes the SSH/bootstrap endpoint from the Orbit WireGuard address, the
gateway endpoint used in generated peer configs, and the public IPv4/IPv6
metadata that the operator recorded when already present.

## Requirements

- Gateway and app provisioning require SSH access to a target host whose
  platform is supported by the requested role. See the node-family
  [role platform support](../node-concepts.md#role-platform-support) matrix.

For first-gateway bootstrap:

- Requires local permission to install the initiating control node's WireGuard
  identity, trust the gateway CA, and store local gateway configuration. A
  successful first-gateway bootstrap completes local onboarding; do not run
  `gateway:add` on that initiating control node afterward.
- Requires a resolved initiating control-node name. Defaults to the
  normalized local short hostname.

For app-node creation:

- Requires an existing gateway and a resolved SSH/bootstrap endpoint.
- Development app-node creation requires a unique development TLD.

For control-node enrollment:

- Must run against the gateway so the gateway can mint the WireGuard identity
  and matching node record.
- After enrolling a control node, install the returned WireGuard configuration,
  join the Orbit network, then run `orbit gateway:add`.

## Technical Contract

See [`node:new` technical contract](technical/1_node-new.md).
