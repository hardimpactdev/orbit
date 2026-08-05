# `orbit cf-cache-rule:add`

Create the Cloudflare cache rule that Orbit manages for an instance-owned domain.

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

- `project`: Resolves a concrete production instance whose instance-owned domain
  maps to a Cloudflare zone. Accept a dotted `project.instance` selector; bare
  project shorthand is valid only when exactly one instance is visible.
- `--json`: Return the cache rule result in the JSON output.

## What Happens

Run `orbit cf-cache-rule:add <project>` to create or converge the standard
Cloudflare cache rule for the resolved instance's zone.

`cf-cache-rule:add` asks the gateway to create or converge the standard
Cloudflare cache rule for the zone that owns the instance public domain. The rule
lets Cloudflare cache public responses while respecting origin `Cache-Control`
headers.

The command does not change instance deployment policy, process state, or proxy
routes. Projects store no domain; the public domain is instance placement state.

## Output

You will see a confirmation of the cache rule outcome for the resolved
instance.

Human output confirms the instance cache rule outcome. Use `--json` for
machine-readable output.

## Requirements

- The caller can reach the Orbit gateway.
- The caller has `cf:cache:rule:add` on the gateway.
- The gateway has a Cloudflare API token configured.
- The resolved concrete instance exists and has an instance-owned domain in a
  Cloudflare zone.

## Related Commands

Use these commands to remove a cache rule, flush cache, or inspect the project
or instance.

- [`orbit cf-cache-rule:remove`](../7_cf-cache-rule-remove/cf-cache-rule-remove.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [`orbit project:show`](../../5_project/4_project-show/project-show.md)
- [Technical contract](technical/1_cf-cache-rule-add.md)
