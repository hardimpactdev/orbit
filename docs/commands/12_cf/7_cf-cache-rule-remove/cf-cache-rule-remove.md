# `orbit cf-cache-rule:remove`

Remove the Orbit-managed Cloudflare cache rule for an app domain.

## Usage

```bash
orbit cf-cache-rule:remove <app> [--force] [--json]
```

## Examples

```bash
orbit cf-cache-rule:remove docs
orbit cf-cache-rule:remove docs --force --json
```

## Arguments And Options

- `app`: Orbit app name whose Cloudflare cache rule should be removed.
- `--force`: Confirm removal without an interactive prompt.
- `--json`: Return the removal result in the shared JSON command envelope.

## What Happens

`cf-cache-rule:remove` asks the gateway to remove Orbit's standard Cloudflare
cache rule for the app's Cloudflare zone. It does not remove app domains, DNS
records, proxy routes, or app deployment policy.

Removal is destructive. Interactive use asks for confirmation unless `--force`
is supplied. Non-interactive use, including `--json`, requires `--force`.

## Output

Human output confirms the removed cache rule. JSON output returns
`success.data.rule`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The app exists and has a real domain in a Cloudflare zone.
- The Orbit-managed Cloudflare cache rule exists.

## Related Commands

- [`orbit cf-cache-rule:add`](../6_cf-cache-rule-add/cf-cache-rule-add.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [Technical contract](technical/1_cf-cache-rule-remove.md)
