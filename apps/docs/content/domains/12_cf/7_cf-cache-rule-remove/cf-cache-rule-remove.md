# `orbit cf-cache-rule:remove`

Remove the Cloudflare cache rule that Orbit manages for the selected concrete
instance's Cloudflare-backed domain.

## Usage

```bash
orbit cf-cache-rule:remove <app> [--force] [--json]
```

## Examples

```bash
orbit cf-cache-rule:remove docs.production
orbit cf-cache-rule:remove docs --force --json
```

## Arguments and options

- `app`: Dotted `app.instance` selector, or a bare app name when the app has
  exactly one instance. The selected instance's domain determines the
  Cloudflare zone.
- `--force`: Confirm removal without an interactive prompt.
- `--json`: Return the removal result in the JSON output.

## What Happens

Run `orbit cf-cache-rule:remove <app.instance>` to remove the Cloudflare cache
rule that Orbit manages for the selected instance's Cloudflare zone. A bare App
selector is accepted only when it resolves to exactly one Instance.

`cf-cache-rule:remove` asks the gateway to remove Orbit's standard Cloudflare
cache rule for the zone resolved from the selected instance's domain. It does
not remove instance domains, DNS records, proxy routes, or deployment policy.

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
- The selected instance exists and has a Cloudflare-backed domain.
- A bare app selector resolves to exactly one instance.
- A Cloudflare cache rule managed by Orbit exists for that zone.

## Related Commands

Use these commands to add a cache rule back or flush the zone cache after removal.

- [`orbit cf-cache-rule:add`](../6_cf-cache-rule-add/cf-cache-rule-add.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [Technical contract](technical/1_cf-cache-rule-remove.md)
