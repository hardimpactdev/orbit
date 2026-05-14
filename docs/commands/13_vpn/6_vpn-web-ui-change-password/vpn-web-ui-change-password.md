# `orbit vpn-web-ui:change-password [password]`

Change the gateway VPN backend web UI password.

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

- `password`: New gateway VPN backend web UI password. Interactive input mode
  prompts when omitted.
- `--force`: Confirm the destructive credential rotation without an interactive
  confirmation prompt.
- `--totp=<code>`: One-time code for the gateway VPN backend when required.
- `--json`: Return the password rotation result in the shared JSON command
  envelope.

## What Happens

Run this command to rotate the admin password for the gateway VPN backend web UI.

`vpn-web-ui:change-password` runs on the gateway host and rotates the password
used to administer the gateway VPN backend. It verifies backend authentication,
updates the backend credential, invalidates existing backend admin sessions when
the backend supports it, and updates the Orbit-managed credential storage on the
gateway.

The command does not rotate WireGuard client keys, node identities, gateway CA
material, or Orbit node access grants.

## Output

Your output confirms the password was rotated successfully.

Human output shows progress and confirms password rotation. Use `--json` for
machine-readable output.

## Requirements

All of the following must be true before the command runs.

- The caller is a gateway or authorized control node.
- Control callers can SSH to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the VPN backend when TOTP is required.
- The new password satisfies the gateway VPN backend password policy.
- Destructive consent is supplied interactively or with `--force`.

## Related Commands

Use these commands to list VPN clients or investigate node health.

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-web-ui-change-password.md)
