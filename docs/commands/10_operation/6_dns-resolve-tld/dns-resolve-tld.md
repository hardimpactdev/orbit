# `orbit dns:resolve-tld [tld] [target]`

[Back to Operation commands.](../README.md)

Configure or remove a caller-local development TLD resolver override.

`dns:resolve-tld` is a local control-node helper for machines that need browser
or CLI access to development hostnames through a local resolver override. It
writes only caller-machine resolver configuration. It does not create gateway
development DNS mappings, app routes, proxy routes, Cloudflare records, or
public DNS.

## Usage

```bash
orbit dns:resolve-tld [tld] [target] [--reset] [--force] [--json]
```

## Examples

```bash
orbit dns:resolve-tld test 10.6.0.7
orbit dns:resolve-tld test 10.6.0.7 --json
orbit dns:resolve-tld test --reset
orbit dns:resolve-tld test --reset --force --json
```

## Arguments And Options

- `tld`: Development TLD to configure, without a leading dot.
- `target`: IP address that local wildcard hostnames under the TLD should
  resolve to. Required unless `--reset` is present.
- `--reset`: Remove the local resolver override for the TLD.
- `--force`: Confirm destructive reset in non-interactive mode.
- `--json`: Output JSON.

## What Happens

For the resolve path, `dns:resolve-tld`:

1. Validates the TLD and target IP address.
2. Writes caller-local resolver configuration for `*.{tld}`.
3. Refreshes the local resolver backend when required by the platform.
4. Reports the active local resolver mapping.

For the reset path, it removes only the Orbit-managed local resolver override
for the selected TLD.

Gateway-owned development DNS mappings are created by node provisioning and
repaired by node doctor. This command is only for caller-local resolver state.

## Output

Human output shows a progress tree for local resolver writes and refreshes.

JSON output reports the TLD, target, action, status, resolver backend, and
whether local state changed.

## Requirements

- The command is running from a control-node caller.
- The caller machine uses a platform with an Orbit-supported local resolver
  backend.
- The process has the local OS privileges required to update resolver
  configuration and refresh the resolver backend.

## Related Commands

- [`dns:list`](../7_dns-list/dns-list.md) - inspect caller-local Orbit resolver
  overrides
- [`node:new`](../../1_node/1_node-new/node-new.md) - create gateway-owned
  development TLD mappings for app nodes
- [`doctor --family=node --self`](../../1_node/node-doctor.md) - verify
  node-family development TLD readiness

## Technical Contract

See [`dns:resolve-tld` technical contract](technical/1_dns-resolve-tld.md).
