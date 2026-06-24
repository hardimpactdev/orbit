# VPN Commands

Manage **non-node** gateway VPN clients (e.g. a phone, a personal device) and the gateway VPN web UI. Orbit nodes get their own WireGuard identities through `node:new`  -  those don't go through `vpn-client:*`. Spec: [`apps/docs/content/domains/13_vpn/`](../../../apps/docs/content/domains/13_vpn/).

VPN administration is the gateway-local exception: when invoked from a client,
Orbit reaches the active gateway-coupled VPN role over the Orbit/WireGuard SSH
path and runs the gateway-local command there.

All `vpn-client:*` and `vpn-web-ui:*` commands accept `--totp=<code>` because they touch the underlying VPN backend's admin surface.

## `orbit vpn-client:list`

List gateway VPN backend clients.

```bash
orbit vpn-client:list [--totp=<code>] [--json]
```

## `orbit vpn-client:new <name>`

Create a non-node VPN client and return its credentials.

```bash
orbit vpn-client:new <name> [--config] [--totp=<code>] [--json]
```

`--config` includes the generated WireGuard config in the output (otherwise just the client metadata is returned and the config can be retrieved later from the VPN backend / web UI).

## `orbit vpn-client:enable <name>` / `disable <name>`

Toggle a non-node VPN client without removing it.

```bash
orbit vpn-client:enable  <name> [--totp=<code>] [--json]
orbit vpn-client:disable <name> [--totp=<code>] [--json]
```

## `orbit vpn-client:remove <name>`

```bash
orbit vpn-client:remove <name> [--force] [--totp=<code>] [--json]
```

## `orbit vpn-web-ui:change-password [password]`

Rotate the gateway VPN web UI password.

```bash
orbit vpn-web-ui:change-password [<password>] [--force] [--totp=<code>] [--json]
```

If `<password>` is omitted, Orbit generates one and prints it. `--force` skips the rotation confirmation prompt.
