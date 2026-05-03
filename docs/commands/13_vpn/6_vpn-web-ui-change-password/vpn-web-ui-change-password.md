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

## Arguments And Options

- `password`: New gateway VPN backend web UI password. Interactive input mode
  prompts when omitted.
- `--force`: Confirm the destructive credential rotation without an interactive
  confirmation prompt.
- `--totp=<code>`: One-time code for the gateway VPN backend when required.
- `--json`: Return the password rotation result in the shared JSON command
  envelope.

## What Happens

`vpn-web-ui:change-password` runs on the gateway host and rotates the password
used to administer the gateway VPN backend. It verifies backend authentication,
updates the backend credential, invalidates existing backend admin sessions when
the backend supports it, and updates Orbit-managed gateway-local credential
storage.

The command does not rotate WireGuard client keys, node identities, gateway CA
material, or Orbit node access grants.

## Output

Human output renders a progress tree and confirms password rotation. JSON
output returns `success.data.vpn.password_changed=true`.

## Requirements

- The caller is a gateway or authorized control node.
- Control callers can SSH to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the VPN backend when TOTP is required.
- The new password satisfies the gateway VPN backend password policy.
- Destructive consent is supplied interactively or with `--force`.

## Related Commands

- [`orbit vpn-client:list`](../1_vpn-client-list/vpn-client-list.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_vpn-web-ui-change-password.md)
