# `orbit vpn-client:disable <name>`

Disable an existing VPN client on the active `vpn` role runtime that is not an
Orbit node, without deleting it.

## Usage

```bash
orbit vpn-client:disable <name> [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-client:disable laptop
orbit vpn-client:disable laptop --totp=123456
orbit vpn-client:disable laptop --json
```

## Arguments and options

- `name`: VPN client name.
- `--totp=<code>`: One-time code for the active `vpn` role runtime backend when required.
- `--json`: Return the updated client in the JSON output.

## What Happens

Run this command to block a VPN client from connecting without removing it.

`vpn-client:disable` resolves the active `vpn` role and marks the named
runtime backend client as disabled. In this version the active `vpn` role is
gateway-coupled, so Orbit still executes on the gateway host. The peer record
remains available so it can be enabled again later.

The command is limited to non-node VPN clients. Active Orbit node peers are
protected because disabling them would break node identity and reachability.

## Output

Your output confirms the new disabled state of the named client.

Human output confirms the client was disabled. Use `--json` for
machine-readable output.

## Requirements

- The caller is a gateway or authorized client.
- Operator callers can SSH to the active `vpn` role host over Orbit/WireGuard.
- The active `vpn` role is resolvable and its runtime backend is installed and reachable.
- The named client exists and is not an active Orbit node peer.

## Related Commands

Use these commands to re-enable, list, or check node health for VPN clients.

- [`orbit vpn-client:enable`](../3_vpn-client-enable/vpn-client-enable.md)
- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-client-disable.md)
