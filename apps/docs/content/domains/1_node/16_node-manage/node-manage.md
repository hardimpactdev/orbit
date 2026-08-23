# `orbit node:manage`

`node:manage` lets an active roleless operator node opt into managed Agent
execution and verifies that the gateway can reach its Agent over WireGuard.
Run the command on the operator machine that owns the current WireGuard identity.

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

- `--user=<user>`: Local account that runs the Orbit Agent. Defaults
  to the current local user and must match that user in this implementation.
- `--json`: Emit the canonical JSON envelope and do not prompt.

## What It Does

`node:manage` asks the gateway to verify managed Agent execution:

1. Reads the current gateway identity with `/api/me`.
2. Confirms the identity is active and has no active node roles.
3. Detects the local operating system platform.
4. Stores `node.user`, `node.platform`, and `node.managed=true` as durable
   management intent.
5. Dispatches a typed Agent runtime probe over the node's WireGuard address.
6. Reports `orbit_agent_unavailable` when the probe fails while retaining the
   stored intent so `doctor --family=node` can report the Agent drift for
   repair.

The command does not add a node role, create a transport object, modify public
hostnames, install SSH keys, or open public SSH.

## Requirements

- The current machine already has gateway access through `gateway:add` or
  first-gateway bootstrap.
- The current gateway identity is an active roleless operator node.
- The node has a WireGuard address recorded on the gateway.

See [`node:manage` technical contract](technical/1_node-manage.md).
