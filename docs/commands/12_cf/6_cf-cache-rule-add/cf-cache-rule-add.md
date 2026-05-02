# `orbit cf-cache-rule:add`

Create the Orbit-managed Cloudflare cache rule for an app domain.

## Usage

```bash
orbit cf-cache-rule:add <app> [--json]
```

## Examples

```bash
orbit cf-cache-rule:add docs
orbit cf-cache-rule:add docs --json
```

## Arguments And Options

- `app`: Orbit app name whose primary domain resolves to a Cloudflare zone.
- `--json`: Return the cache rule result in the shared JSON command envelope.

## What Happens

`cf-cache-rule:add` asks the gateway to create or converge the standard
Cloudflare cache rule for an app's Cloudflare zone. The rule lets Cloudflare
cache public responses while respecting origin `Cache-Control` headers.

The command does not change app deployment policy, app process state, or proxy
routes.

## Output

Human output confirms the app cache rule outcome. JSON output returns
`success.data.rule`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The app exists and has a real domain in a Cloudflare zone.

## Related Commands

- [`orbit cf-cache-rule:remove`](../7_cf-cache-rule-remove/cf-cache-rule-remove.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [`orbit app:show`](../../5_app/4_app-show/app-show.md)
- [Technical contract](technical/1_cf-cache-rule-add.md)
