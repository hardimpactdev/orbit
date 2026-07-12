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
backend's client inventory. Every caller uses the typed gateway HTTPS API over
WireGuard. The gateway executes the gateway-coupled backend operation locally.

The command may show backend peers that correspond to active Orbit node
identities, but it does not verify node reachability or repair node drift.

## Output

Use the table below to read each client's status at a glance.

Human output renders a client table with name, WireGuard address, enabled
state, kind, and latest handshake timestamp. Use `--json` for
machine-readable output.

## Requirements

- The caller is the gateway node, has `vpn:read` on the active gateway node,
  or has gateway-admin authority.
- Every caller can reach the typed gateway HTTPS API over WireGuard.
- The active `vpn` role is resolvable and its runtime backend is installed and reachable.
- The operator can authenticate to the VPN backend when TOTP is required.

## Related Commands

Use these commands to manage VPN clients or investigate node health.

- [`orbit vpn-client:new`](../2_vpn-client-new/vpn-client-new.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-client-list.md)
