# `orbit vpn-client:remove <name>`

Remove a VPN client from the active `vpn` role runtime that is not an Orbit
node.

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
- `--totp=<code>`: One-time code for the active `vpn` role runtime backend when required.
- `--json`: Return the removal result in the JSON output.

## What Happens

Run this command to permanently delete an admin VPN client from the active
`vpn` role runtime backend.

`vpn-client:remove` resolves the active `vpn` role and deletes the named
runtime backend client after destructive consent. Every caller uses the typed
gateway HTTPS API over WireGuard. The gateway executes the gateway-coupled
backend operation locally.

The command is limited to non-node VPN clients. Removing Orbit node peers
belongs to [`node:remove`](../../1_node/8_node-remove/node-remove.md).

## Output

Your output confirms the client was removed and shows the action taken.

Human output confirms the client was removed. Use `--json` for machine-readable
output.

## Requirements

- The caller is the gateway node, has `vpn:write` on the active gateway node,
  or has gateway-admin authority.
- Every caller can reach the typed gateway HTTPS API over WireGuard.
- The active `vpn` role is resolvable and its runtime backend is installed and reachable.
- The named client exists and is not an active Orbit node peer.
- Destructive consent is supplied interactively or with `--force`.

## Related Commands

Use these commands to list remaining clients or remove an Orbit node.

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`orbit node:remove`](../../1_node/8_node-remove/node-remove.md)
- [Technical contract](technical/1_vpn-client-remove.md)
