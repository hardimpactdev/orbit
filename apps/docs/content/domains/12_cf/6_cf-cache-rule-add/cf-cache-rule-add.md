# `orbit cf-cache-rule:add`

Create the Cloudflare cache rule that Orbit manages for an app's Cloudflare-backed domain.

## Usage

```bash
orbit cf-cache-rule:add <app> [--json]
```

## Examples

```bash
orbit cf-cache-rule:add docs
orbit cf-cache-rule:add docs --json
```

## Arguments and options

- `app`: Bare app name. Current gateway resolution uses
  `App.domain` to find the Cloudflare zone. Dotted `app.instance`
  selectors are not implemented.
- `--json`: Return the cache rule result in the JSON output.

## What Happens

Run `orbit cf-cache-rule:add <app>` to create or converge the standard
Cloudflare cache rule for the app's Cloudflare zone.

`cf-cache-rule:add` asks the gateway to create or converge the standard
Cloudflare cache rule for the zone resolved from the app's domain. The rule
lets Cloudflare cache public responses while respecting origin `Cache-Control`
headers.

The command does not change instance deployment policy, process state, or proxy
routes. Direction (pending): zone resolution from instance-owned domains.

## Output

You will see a confirmation of the cache rule outcome for the resolved app.

Human output confirms the app cache rule outcome. Use `--json` for
machine-readable output.

## Requirements

- The caller can reach the Orbit gateway.
- The caller has `cf:cache:rule:add` on the gateway.
- The gateway has a Cloudflare API token configured.
- The named app exists and has a Cloudflare-backed `App.domain`.

## Related Commands

Use these commands to remove a cache rule, flush cache, or inspect the app
or instance.

- [`orbit cf-cache-rule:remove`](../7_cf-cache-rule-remove/cf-cache-rule-remove.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [`orbit app:show`](../../5_app/4_app-show/app-show.md)
- [Technical contract](technical/1_cf-cache-rule-add.md)
