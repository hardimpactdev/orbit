# `orbit vpn-client:list`

List WireGuard clients known to the active `vpn` role runtime backend.

## Usage

```bash
orbit vpn-client:list [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-client:list
orbit vpn-client:list --totp=123456
orbit vpn-client:list --json
```

## Arguments and options

- `--totp=<code>`: One-time code for the active `vpn` role runtime backend when required.
- `--json`: Return the client list in the JSON output.

## What Happens

Run this command to inspect the active `vpn` role runtime backend's current
client inventory.

`vpn-client:list` resolves the active `vpn` role and reads that runtime
backend's client inventory. In this version the active `vpn` role is
gateway-coupled, so Orbit still executes on the gateway host. From a client,
Orbit connects to the active `vpn` role host over the Orbit/WireGuard SSH path
and runs the operation against that runtime.

The command may show backend peers that correspond to active Orbit node
identities, but it does not verify node reachability or repair node drift.

## Output

Use the table below to read each client's status at a glance.

Human output renders a client table with name, WireGuard address, enabled
state, kind, and latest handshake timestamp. Use `--json` for
machine-readable output.

## Requirements

- The caller is a gateway or authorized client.
- Operator callers can SSH to the active `vpn` role host over Orbit/WireGuard.
- The active `vpn` role is resolvable and its runtime backend is installed and reachable.
- The operator can authenticate to the VPN backend when TOTP is required.

## Related Commands

Use these commands to manage VPN clients or investigate node health.

- [`orbit vpn-client:new`](../2_vpn-client-new/vpn-client-new.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-client-list.md)
