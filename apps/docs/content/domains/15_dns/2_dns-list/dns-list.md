# `orbit dns:list`

[Back to DNS commands.](../README.md)

List the DNS resolver overrides that Orbit manages on the caller machine.

`dns:list` reads the resolver configuration that Orbit manages for development
TLDs on the calling client. It is a troubleshooting command for local
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

## Arguments and options

- `--json`: Output JSON.

## What Happens

Run `dns:list` to see which development TLDs have local resolver entries on your machine. `dns:list`:

1. Reads the local resolver overrides that Orbit manages on the caller machine.
2. Reports configured development TLD targets and resolver backend status when
   available.

The command is read-only. It does not create, update, or remove local resolver
configuration and does not inspect private gateway DNS projections.

## Output

Use `--json` for machine-readable output. Human output is a local DNS summary.

Use `--json` for machine-readable resolver entries.

## Requirements

- The command is running from a client caller.
- The caller machine uses Linux or macOS with Orbit-managed local resolver
  configuration available under Orbit's local resolver storage path.

## Related Commands

Use these commands to configure or verify the DNS entries that `dns:list` reports.

- [`dns:resolve-tld`](../1_dns-resolve-tld/dns-resolve-tld.md) - configure or
  remove a local resolver override for a development TLD on the caller machine
- [`doctor --family=node --self`](../../1_node/node-doctor.md) - verify
  development TLD readiness for the node family

## Technical Contract

See [`dns:list` technical contract](technical/1_dns-list.md).
