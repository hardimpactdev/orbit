# `orbit node:update [name]`

[Back to Nodes commands.](../README.md)

Update node registry metadata and role-owned settings.

Modifies existing gateway-tracked node attributes such as host, TLD, or
public IP metadata that the operator supplies, without updating system packages or
re-provisioning the host. Public IPv4/IPv6 metadata is never detected or
refreshed automatically.

Environment switching (between `app-dev` and `app-prod`) is a role-assignment
change, not a metadata update — use [`node role:remove`](../14_node-role-remove/node-role-remove.md)
and [`node role:add`](../12_node-role-add/node-role-add.md).

## Usage

```bash
orbit node:update [name] [--host=<host>] [--tld=<tld>] [--public-ipv4=<address>] [--public-ipv6=<address>] [--json]
```

Run without arguments in a TTY to let the interactive input mode prompt for
the node name and which field to change. Authorization is gateway-side and
grants-only — the caller's grant on the target node must include
`node:update`; the gateway rejects unauthorized requests with
`authorization_failed` before gateway-owned side effects.

In non-interactive input mode, at least one field flag must be provided;
otherwise the command fails before side effects.

## Examples

```bash
orbit node:update app-1 --host=app-1.ssh.example.com
orbit node:update app-1 --tld=test
orbit node:update gateway-1 --public-ipv4=203.0.113.2
orbit node:update app-1 --host=203.0.113.20 --public-ipv4=203.0.113.20 --json
```

## Arguments and options

- `name`: node name to update. Must exist in gateway node configuration.
- `--host=<host>`: SSH/bootstrap endpoint. Valid for `gateway` and any
  workload-role-bearing node. Forbidden on operator-identity nodes. Updating
  this does not change the gateway endpoint used in WireGuard peer configs.
  `node:new --template=gateway --host=<host>` seeds that endpoint only during
  first-gateway bootstrap before peer configs have been issued;
  `node:update --host` is later node metadata.
- `--tld=<tld>`: development TLD. Valid only for nodes carrying an active
  `app-dev` role assignment.
- `--public-ipv4=<address>`: public IPv4 metadata supplied by the operator.
  Valid for `gateway` and workload-role-bearing nodes. Forbidden on
  operator-identity nodes.
- `--public-ipv6=<address>`: public IPv6 metadata supplied by the operator.
  Valid for `gateway` and workload-role-bearing nodes. Forbidden on
  operator-identity nodes.
- `--json`: Output JSON.

Each field flag may be supplied at most once per invocation. Supplying the
same field flag more than once is rejected as a validation failure rather
than silently last-wins.

## What Happens

Use `node:update` to change specific node metadata fields in the gateway registry.

`node:update` updates gateway configuration for supported node metadata. It does not
update operating system packages, Orbit installations, tools, or general system
services on the node; any node-side work is limited to the artifacts that Orbit owns
that are directly affected by the changed metadata.

- Records public IPv4/IPv6 metadata only when explicit options are provided.
  Orbit does not infer public IP metadata from `--host`, the gateway endpoint,
  SSH reachability, or egress checks.
- Treats public IPv4/IPv6 values as operator-supplied metadata only. Updating
  them does not change the gateway endpoint used in WireGuard peer configs.
- Re-applies node-owned host artifacts when a changed setting has node-side
  effects. Re-applying unchanged configuration is owned by
  [`doctor --family=node --restore`](../node-doctor.md), not `node:update`.
- Changes a development node's TLD when `--tld` is supplied. `--tld` is
  valid only for nodes carrying an active `app-dev` role assignment;
  gateway targets, operator-identity targets, and `app-prod` targets are
  rejected before side effects. Broader drift repair after a TLD change belongs
  to [`doctor --family=node --restore`](../node-doctor.md).
- Reconciles the active `vpn` role DNS runtime when `tld` or
  `wireguard_address` actually change for a node. In v1 this materializes the
  desired DNS mappings and policy owned by the gateway onto the
  gateway-coupled `vpn` role runtime without restarting the container. The
  contract for the DNS substrate is
  [`docs/domains/3_tool/dns-bootstrap-contract.md`](../../3_tool/dns-bootstrap-contract.md).
  Other field changes do not touch DNS.
- Does not change node role after creation. Role change is an identity
  migration outside `node:update` scope; a future explicit role-migration
  contract will own that flow.
- Does not update app runtime policy, tool state, firewall policy, proxy
  routes, processes, schedules, or deployment pipelines.

No-op updates where the supplied value equals the current stored value are
reported as successful with an empty `changed` array.

When re-applying artifacts on the node side fails after the gateway configuration was
written, the command still returns success and reports the remaining
node-family drift as a warning that points at
[`doctor --family=node --restore`](../node-doctor.md).

## Output

Human output summarizes changed fields and any applied artifacts.

JSON output returns the updated node record, the `changed` array, and any
apply warnings. See the [JSON renderer contract](technical/6.2_node-update_output-render_json.md)
for the envelope shape.

## Requirements

- Must run on the gateway host or from a configured client.
- The caller's grant on the target node must include the `node:update`
  permission. Denials surface as `authorization_failed`.
- The target node must exist in gateway configuration.
- At least one supported field must be provided in non-interactive input mode.

## Related Commands

Use these commands to inspect, extend, or verify the node you updated.

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`node:remove`](../8_node-remove/node-remove.md) — remove a node from the fleet
- [`doctor --family=node`](../node-doctor.md) — verify and repair node drift

## Technical Contract

See [`node:update` technical contract](technical/1_node-update.md).
