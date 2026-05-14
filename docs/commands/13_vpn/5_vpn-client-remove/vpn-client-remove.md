# `orbit vpn-client:remove <name>`

Remove a gateway VPN client that is not an Orbit node.

## Usage

```bash
orbit vpn-client:remove <name> [--force] [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-client:remove laptop
orbit vpn-client:remove laptop --force
orbit vpn-client:remove laptop --force --json
```

## Arguments and options

- `name`: VPN client name.
- `--force`: Skip the destructive confirmation prompt.
- `--totp=<code>`: One-time code for the gateway VPN backend when required.
- `--json`: Return the removal result in the shared JSON command envelope.

## What Happens

Run this command to permanently delete an admin VPN client from the gateway backend.

`vpn-client:remove` runs on the gateway host and deletes the named VPN backend
client after destructive consent. From a control node, Orbit connects to the
gateway over the Orbit/WireGuard SSH path and runs the gateway-local operation
there.

The command is limited to non-node VPN clients. Removing Orbit node peers
belongs to [`node:remove`](../../1_node/8_node-remove/node-remove.md).

## Output

Your output confirms the client was removed and shows the action taken.

Human output confirms the client was removed. JSON output returns
`success.data.client` with `action="removed"`.

## Requirements

- The caller is a gateway or authorized control node.
- Control callers can SSH to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The named client exists and is not an active Orbit node peer.
- Destructive consent is supplied interactively or with `--force`.

## Related Commands

Use these commands to list remaining clients or remove an Orbit node.

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`orbit node:remove`](../../1_node/8_node-remove/node-remove.md)
- [Technical contract](technical/1_vpn-client-remove.md)
