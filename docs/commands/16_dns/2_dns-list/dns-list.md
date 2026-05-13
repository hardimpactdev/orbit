# `orbit dns:list`

[Back to DNS commands.](../README.md)

List caller-local Orbit DNS resolver overrides.

`dns:list` reads Orbit-managed local resolver configuration for development
TLDs on the calling control node. It is a troubleshooting command for local
hostname resolution and does not query or mutate gateway configuration.

## Usage

```bash
orbit dns:list [--json]
```

## Examples

```bash
orbit dns:list
orbit dns:list --json
```

## Arguments And Options

- `--json`: Output JSON.

## What Happens

`dns:list`:

1. Reads Orbit-managed local resolver overrides on the caller machine.
2. Reports configured development TLD targets and resolver backend status when
   available.

The command is read-only. It does not create, update, or remove local resolver
configuration and does not inspect gateway-owned development DNS mappings.

## Output

Human output is a local DNS summary.

JSON output returns local resolver entries under `success.data.dns`.

## Requirements

- The command is running from a control-node caller.
- The caller machine uses Linux or macOS with Orbit-managed local resolver
  configuration available under Orbit's local resolver storage path.

## Related Commands

- [`dns:resolve-tld`](../1_dns-resolve-tld/dns-resolve-tld.md) - configure or
  remove a caller-local development TLD resolver override
- [`doctor --family=node --self`](../../1_node/node-doctor.md) - verify
  node-family development TLD readiness

## Technical Contract

See [`dns:list` technical contract](technical/1_dns-list.md).
