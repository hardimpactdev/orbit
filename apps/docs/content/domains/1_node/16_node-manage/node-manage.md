# `orbit node:manage`

`node:manage` lets an active roleless operator node opt into managed Agent
intent. Its initial verification uses SSH through a transitional seam that
requires the exact transport marker. That bootstrap will move to Agent push.
Run the command on the operator machine that owns the current WireGuard identity.

## Usage

```bash
orbit node:manage --node-transport=transitional-ssh-fallback [--user=<user>] [--json]
```

## Examples

```bash
orbit node:manage --node-transport=transitional-ssh-fallback
orbit node:manage --node-transport=transitional-ssh-fallback --user=nicky
orbit node:manage --node-transport=transitional-ssh-fallback --json
```

## Options

- `--user=<user>`: Local account that the gateway should use for SSH. Defaults
  to the current local user and must match that user in this implementation.
- `--node-transport=transitional-ssh-fallback`: Required exact marker for this
  tracked transitional SSH seam. No other value authorizes the bootstrap.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## What It Does

`node:manage` performs a local setup step, then asks the gateway to verify the
managed SSH path:

1. Reads the current gateway identity with `/api/me`.
2. Confirms the identity is active and has no active node roles.
3. Preflights roleless eligibility and the exact transitional SSH marker, then
   reads the gateway management SSH public key.
4. Adds that key to the current local user's `~/.ssh/authorized_keys` without
   removing existing keys.
5. Detects the local operating system platform.
6. Stores `nodes.user` and `nodes.platform` on the gateway.
7. Pins the local node's SSH host key by `node.wireguard_address`.
8. Verifies gateway SSH reachability over the node's WireGuard address.
9. Sets `node.managed=true`, opting this roleless node into managed Agent intent.

The command does not add a node role, create a transport object, modify public
hostnames, or open public SSH. Provisioning is Orbit's sole permanent SSH lane;
this command's SSH verification is explicitly transitional.

## Requirements

- The current machine already has gateway access through `gateway:add` or
  first-gateway bootstrap.
- The current gateway identity is an active roleless operator node.
- The local SSH daemon accepts key authentication for the current local user.
- The node has a WireGuard address recorded on the gateway.
- The exact `--node-transport=transitional-ssh-fallback` marker is supplied.

See [`node:manage` technical contract](technical/1_node-manage.md).
