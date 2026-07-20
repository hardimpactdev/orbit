# `orbit cf-cache-rule:add`

Create the Cloudflare cache rule that Orbit manages for an app domain.

## Usage

```bash
orbit cf-cache-rule:add <project> [--json]
```

## Examples

```bash
orbit cf-cache-rule:add docs
orbit cf-cache-rule:add docs --json
```

## Arguments and options

- `app`: Orbit project name whose primary domain resolves to a Cloudflare zone.
- `--json`: Return the cache rule result in the JSON output.

## What Happens

Run `orbit cf-cache-rule:add <project>` to create or converge the standard Cloudflare cache rule for the project.

`cf-cache-rule:add` asks the gateway to create or converge the standard
Cloudflare cache rule for an app's Cloudflare zone. The rule lets Cloudflare
cache public responses while respecting origin `Cache-Control` headers.

The command does not change app deployment policy, app process state, or proxy
routes.

## Output

You will see a confirmation of the cache rule outcome for the app.

Human output confirms the app cache rule outcome. Use `--json` for
machine-readable output.

## Requirements

- The caller can reach the Orbit gateway.
- The caller has `cf:cache:rule:add` on the gateway.
- The gateway has a Cloudflare API token configured.
- The app exists and has a real domain in a Cloudflare zone.

## Related Commands

Use these commands to remove a cache rule, flush cache, or inspect the app.

- [`orbit cf-cache-rule:remove`](../7_cf-cache-rule-remove/cf-cache-rule-remove.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [`orbit project:show`](../../5_project/4_project-show/project-show.md)
- [Technical contract](technical/1_cf-cache-rule-add.md)
