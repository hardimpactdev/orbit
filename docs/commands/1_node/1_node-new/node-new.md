# `orbit node:new [name]`

[Back to Nodes commands.](../README.md)

Register a new gateway, app, or control node in the Orbit fleet.

Use `node:new` when adding capacity to the fleet. Gateway and app nodes are
provisioned over SSH when needed. Control nodes are enrolled by the gateway so
they can join the Orbit WireGuard network and then run `gateway:add`.
The first gateway bootstrap is the exception: when a control node with no
configured gateway creates the first gateway, the command also onboards that
initiating control node and stores the local gateway configuration.

## Usage

Run this command to register a new node and provision it when required.

```bash
orbit node:new [name] [--role=gateway|app|control] [--host=<host>] [--control-name=<name>] [--environment=development|production] [--tld=<tld>] [--user=<user>] [--json]
orbit node:new
```

The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request based on the caller's gateway-known
role. First-gateway bootstrap is the exception, because no gateway exists yet
to authenticate against.

## Examples

```bash
orbit node:new control-1 --role=control
orbit node:new app-1 --role=app --host=app-1.ssh.example.com --environment=development --tld=test
orbit node:new app-2 --role=app --host=203.0.113.20 --environment=production
orbit node:new gateway-1 --role=gateway --host=203.0.113.2 --control-name=control-1
```

## Arguments and options

- `name`: unique node slug in the gateway registry, unless the command is
  converging or adopting a compatible existing node.
- `--role`: required node role: `gateway`, `app`, or `control`.
- `--host`: required for gateway and app nodes. SSH/bootstrap endpoint for
  gateway or app node provisioning. This is never the canonical node address.
- `--control-name`: initiating control-node name for first-gateway bootstrap
  (a control node with no configured gateway running `--role=gateway`).
  Defaults to the normalized local short hostname. Forbidden outside
  first-gateway bootstrap.
- `--environment`: app-node environment: `development` or `production`.
- `--tld`: development TLD for a development app node, without a leading dot.
- `--user`: SSH user for provisioning. Defaults to `root`. Stored as the
  steady-state `nodes.user` after the gateway-managed SSH user is set up.
- `--json`: Output JSON.

## Node Roles

**Control node**

Creates a gateway-owned WireGuard identity and active control-node record. The
command returns the WireGuard configuration for the operator to install on that
control machine before running `gateway:add`.

Control-node enrollment does not SSH to or configure the control machine.

**App node**

Registers an app runtime node. If the target host is not already provisioned,
the gateway provisions it over SSH, installs the Orbit runtime, joins it to the
WireGuard network, and verifies basic readiness.

App nodes are not enrolled through a detached WireGuard config. `node:new
--role=app` requires a resolved SSH/bootstrap endpoint so the gateway can
complete the app-node bootstrap.

Development app nodes also store a node TLD. Future apps and workspaces created
on that node use that TLD for their route domains, and the gateway creates the
development DNS mapping for the TLD to the app node's WireGuard address.

**Gateway node**

Bootstraps or adopts the gateway node that owns fleet configuration, WireGuard
identity, gateway APIs, and node access policy.

When a control node with no configured gateway bootstraps the first gateway,
`node:new --role=gateway` also mints and installs the initiating control node's
WireGuard identity named by `--control-name`, trusts the gateway CA, stores the
local gateway endpoint, and verifies gateway API access. After that successful
flow, the initiating control node does not run `gateway:add`.

`--host` is required for every gateway request, including later gateway
convergence checks after a gateway already exists. During first-gateway
bootstrap, the resolved `--host` value becomes the initial gateway endpoint used
in generated WireGuard peer configs. This endpoint may be a DNS name, public IP,
private IP, or any address reachable by the nodes that will join the fleet.

If the requested gateway is already provisioned and active, and the supplied host is
compatible with that gateway identity, Orbit converges idempotently without
reprovisioning and reports the already-provisioned status. If the gateway is
compatible but drifted or incomplete, `node:new` reports the drift and points to
`doctor --family=node --restore`. Destructive gateway reset is outside `node:new`
and requires a future explicit reset contract.

## What Happens

`node:new` writes the node record in the gateway registry, creates or verifies
the node identity, and performs only the bootstrap needed for that role.
Development app-node bootstrap includes the node TLD and the gateway development
DNS mapping for that TLD.

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
