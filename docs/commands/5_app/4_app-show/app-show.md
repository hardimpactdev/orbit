# `orbit app:show [app]`

[Back to Apps commands.](../README.md)

Show one app's gateway registry details.

Use `app:show` when you need an app's gateway-owned registry record: owning
node, repository, paths, PHP version, agent IDE configuration, and the durable
configuration it owns in related families (`workspace`, `process`, and app-owned
`proxy`). Live runtime drift, readiness, and repair belong to
[`doctor --family=app`](../app-doctor.md).

## Usage

```bash
orbit app:show [app] [--json]
```

Run without the `app` argument to let the command resolve the target app from
the current local directory context.

## Examples

```bash
orbit app:show
orbit app:show docs
orbit app:show docs.test
orbit app:show docs --json
```

## Arguments and options

- `app`: app name or app hostname to inspect. Optional. Defaults to the app
  resolved from the current working directory context. See
  [Default resolution](technical/5.1_app-show_input-mode_interactive.md#default-resolution)
  for interactive behavior and
  [Non-interactive default resolution](technical/5.2_app-show_input-mode_non-interactive.md#default-resolution)
  for non-interactive behavior.
- `--json`: Output JSON.

App slugs are globally unique in the gateway app registry. The positional
already addresses an app uniquely; `app:show` does not accept a `--node` flag.

## What Happens

Run `app:show` to inspect a single app's gateway configuration without triggering any writes.

`app:show` performs a read-only registry inspection of a single app:

1. Resolves the target app from input, current directory context, or
   interactive prompt.
2. Reads the app record from gateway-owned app configuration: name, owning node,
   repository, paths, PHP version, agent IDE configuration.
3. Validates that the current caller is authorized to inspect the target app
   through gateway-owned access policy.
4. Aggregates the durable gateway configuration the app owns: workspaces, processes,
   and app-owned proxy routes.
5. Returns the app detail view backed by the registry.

`app:show` does not:

- Mutate gateway configuration or node state.
- Fix drift or adopt node reality (use [`doctor --family=app`](../app-doctor.md)).
- Create new release or deployment artifacts.
- SSH into the owning app node directly from the caller.
- Run live readiness, PHP-FPM, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Output

Human output is a registry detail view.

JSON output returns the app record under a machine-readable output. See the
[JSON renderer contract](technical/6.2_app-show_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The target app is visible to the current node identity through gateway-owned
  access policy.

## Related Commands

Use these commands to take action after inspecting an app with `app:show`.

- [`app:new`](../1_app-new/app-new.md) — create or clone an app
- [`app:list`](../3_app-list/app-list.md) — list registered apps
- [`app:remove`](../6_app-remove/app-remove.md) — remove an app
- [`doctor --family=app`](../app-doctor.md) — verify and repair app drift
- [`node:show`](../../1_node/4_node-show/node-show.md) — inspect the owning node

## Technical Contract

See [`app:show` technical contract](technical/1_app-show.md).
