# `orbit vpn-client:enable <name>`

Enable an existing gateway VPN client that is not an Orbit node.

## Usage

```bash
orbit vpn-client:enable <name> [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-client:enable laptop
orbit vpn-client:enable laptop --totp=123456
orbit vpn-client:enable laptop --json
```

## Arguments and options

- `name`: VPN client name.
- `--totp=<code>`: One-time code for the gateway VPN backend when required.
- `--json`: Return the updated client in the JSON output.

## What Happens

Run this command to allow a disabled admin VPN client to connect again.

`vpn-client:enable` runs on the gateway host and marks the named VPN backend
client as enabled. From a control node, Orbit connects to the gateway over the
Orbit/WireGuard SSH path and runs the gateway-local operation there.

The command is limited to non-node VPN clients. Active Orbit node peers are
protected because node WireGuard identity belongs to the node lifecycle.

## Output

Your output confirms the new enabled state of the named client.

Human output confirms the client was enabled. Use `--json` for machine-readable
output.

## Requirements

- The caller is a gateway or authorized control node.
- Control callers can SSH to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The named client exists and is not an active Orbit node peer.

## Related Commands

Use these commands to disable, list, or check node health for VPN clients.

- [`orbit vpn-client:disable`](../4_vpn-client-disable/vpn-client-disable.md)
- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-client-enable.md)
