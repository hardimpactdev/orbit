# `orbit node:manage`

`node:manage` lets an active roleless operator node opt into gateway SSH
management. It is run on the operator machine that owns the current WireGuard
identity.

## Usage

```bash
orbit node:manage [--user=<user>] [--json]
```

## Examples

```bash
orbit node:manage
orbit node:manage --user=nicky
orbit node:manage --json
```

## Options

- `--user=<user>`: Local account that the gateway should use for SSH. Defaults
  to the current local user and must match that user in this implementation.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## What It Does

`node:manage` performs a local setup step, then asks the gateway to verify the
managed SSH path:

1. Reads the current gateway identity with `/api/me`.
2. Confirms the identity is active and has no active node roles.
3. Reads the gateway management SSH public key.
4. Adds that key to the current local user's `~/.ssh/authorized_keys` without
   removing existing keys.
5. Detects the local operating system platform.
6. Stores `nodes.user` and `nodes.platform` on the gateway.
7. Pins the local node's SSH host key by `node.wireguard_address`.
8. Verifies gateway SSH reachability over the node's WireGuard address.

The command does not add a node role, create a managed flag, create a transport
object, modify public hostnames, or open public SSH.

## Requirements

- The current machine already has gateway access through `gateway:add` or
  first-gateway bootstrap.
- The current gateway identity is an active roleless operator node.
- The local SSH daemon accepts key authentication for the current local user.
- The node has a WireGuard address recorded on the gateway.

See [`node:manage` technical contract](technical/1_node-manage.md).
