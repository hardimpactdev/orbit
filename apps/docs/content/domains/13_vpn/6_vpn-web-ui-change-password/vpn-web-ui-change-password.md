# `orbit vpn-web-ui:change-password [password]`

Change the active `vpn` role runtime backend web UI password.

## Usage

```bash
orbit vpn-web-ui:change-password [password] [--force] [--totp=<code>] [--json]
```

## Examples

```bash
orbit vpn-web-ui:change-password
orbit vpn-web-ui:change-password 'new-long-password' --force
orbit vpn-web-ui:change-password 'new-long-password' --force --json
```

## Arguments and options

- `password`: New active `vpn` role runtime backend web UI password. Interactive input mode
  prompts when omitted.
- `--force`: Confirm the destructive credential rotation without an interactive
  confirmation prompt.
- `--totp=<code>`: One-time code for the active `vpn` role runtime backend when required.
- `--json`: Return the password rotation result in the shared JSON command
  envelope.

## What Happens

Run this command to rotate the admin password for the active `vpn` role runtime
backend web UI.

`vpn-web-ui:change-password` resolves the active `vpn` role and rotates the
password used to administer that runtime backend. In this version the active
`vpn` role is gateway-coupled, so Orbit still executes on the gateway host. It
verifies backend authentication, updates the backend credential, invalidates
existing backend admin sessions when the backend supports it, and updates the
Orbit-managed credential storage on the active `vpn` role host.

The command does not rotate WireGuard client keys, node identities, gateway CA
material, or Orbit node access grants.

## Output

Your output confirms the password was rotated successfully.

Human output shows progress and confirms password rotation. Use `--json` for
machine-readable output.

## Requirements

All of the following must be true before the command runs.

- The caller is the gateway node, has `vpn:write` on the active gateway node,
  or has gateway-admin authority.
- Non-gateway callers can SSH to the active `vpn` role host over Orbit/WireGuard.
- The active `vpn` role is resolvable and its runtime backend is installed and reachable.
- The operator can authenticate to the VPN backend when TOTP is required.
- The new password satisfies the active `vpn` role runtime backend password policy.
- Destructive consent is supplied interactively or with `--force`.

## Related Commands

Use these commands to list VPN clients or investigate node health.

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-web-ui-change-password.md)
