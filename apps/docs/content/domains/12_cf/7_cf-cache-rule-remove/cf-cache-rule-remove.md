# `orbit cf-cache-rule:remove`

Remove the Cloudflare cache rule that Orbit manages for an instance-owned domain.

## Usage

```bash
orbit cf-cache-rule:remove <project> [--force] [--json]
```

## Examples

```bash
orbit cf-cache-rule:remove docs
orbit cf-cache-rule:remove docs --force --json
```

## Arguments and options

- `project`: Resolves a concrete production instance whose instance-owned domain
  maps to a Cloudflare zone. Accept a dotted `project.instance` selector; bare
  project shorthand is valid only when exactly one instance is visible.
- `--force`: Confirm removal without an interactive prompt.
- `--json`: Return the removal result in the JSON output.

## What Happens

Run `orbit cf-cache-rule:remove <project>` to remove the Cloudflare cache rule
that Orbit manages for the resolved instance's zone.

`cf-cache-rule:remove` asks the gateway to remove Orbit's standard Cloudflare
cache rule for the instance-owned domain's Cloudflare zone. It does not remove
instance domains, DNS records, proxy routes, or instance deployment policy.
Projects store no domain; the public domain is instance placement state.

Removal is destructive. Interactive use asks for confirmation unless `--force`
is supplied. Non-interactive use, including `--json`, requires `--force`.

## Output

You will see a confirmation of the removed cache rule.

Human output confirms the removed cache rule. Use `--json` for machine-readable
output.

## Requirements

- The caller can reach the Orbit gateway.
- The caller has `cf:cache:rule:remove` on the gateway.
- The gateway has a Cloudflare API token configured.
- The resolved concrete instance exists and has an instance-owned domain in a
  Cloudflare zone.
- A Cloudflare cache rule managed by Orbit exists for that zone.

## Related Commands

Use these commands to add a cache rule back or flush the zone cache after removal.

- [`orbit cf-cache-rule:add`](../6_cf-cache-rule-add/cf-cache-rule-add.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [Technical contract](technical/1_cf-cache-rule-remove.md)
