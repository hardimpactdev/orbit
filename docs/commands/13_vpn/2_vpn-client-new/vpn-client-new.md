# `orbit vpn-client:new <name>`

Create a VPN client on the gateway for human or operator access; the client is not an Orbit node.

## Usage

```bash
orbit vpn-client:new <name> [--config] [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-client:new laptop
orbit vpn-client:new laptop --config
orbit vpn-client:new laptop --totp=123456 --json
```

## Arguments and options

- `name`: VPN client name.
- `--config`: Include the generated WireGuard client configuration in output.
- `--totp=<code>`: One-time code for the gateway VPN backend when required.
- `--json`: Return the created client in the JSON output.

## What Happens

Run this command to provision a new admin VPN peer on the gateway backend.

`vpn-client:new` runs on the gateway host and creates a gateway VPN backend
peer for the requested client name. From a operator node, Orbit connects to the
gateway over the Orbit/WireGuard SSH path and runs the gateway-local operation
there.

The created peer is an admin VPN client. It is not an Orbit node identity, does
not create a node record, and does not grant Orbit node access. Active Orbit
node names are reserved and cannot be reused as VPN client names.

When `--config` is supplied, the command returns the generated WireGuard config
for the client. The generated config must use the WireGuard server DNS endpoint
as its only `DNS =` value. For the normal `10.6.0.0/24` network this is
`DNS = 10.6.0.1`; it is not the gateway peer address, such as `10.6.0.2`, and
it must not include public fallback resolvers.

## Output

Your output will include the new client's WireGuard address and, when requested, the generated config.

Human output confirms the client, shows its WireGuard address, and prints the
WireGuard config only when `--config` is supplied. Use `--json` for
machine-readable output.

## Requirements

- The caller is a gateway or authorized operator node.
- Operator callers can SSH to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the VPN backend when TOTP is required.

## Related Commands

Use these commands to list, remove, or provision Orbit node identities.

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`orbit vpn-client:remove`](../5_vpn-client-remove/vpn-client-remove.md)
- [`orbit node:new`](../../1_node/1_node-new/node-new.md)
- [Technical contract](technical/1_vpn-client-new.md)
