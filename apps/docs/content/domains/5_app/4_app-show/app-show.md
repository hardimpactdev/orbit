# `orbit app:show [app]`

[Back to App and instance commands.](../README.md)

Show one app's gateway registry details.

Use `app:show` when you need an app's gateway-owned registry record and
placement breakdown: repository, PHP version template used by new instances,
visible instances, nested workspaces, and app-level process and route summaries. Live
runtime drift, readiness, and repair belong to
[`doctor --family=instance`](../instance-doctor.md).

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

1. Resolves the target app from input, the nearest `.orbit/config` instance
   marker in the current directory ancestry, or
   interactive prompt.
2. Validates that the current caller can inspect at least one concrete Orbit app
   instance, unless the caller is the gateway.
3. Reads the app record from gateway-owned configuration: name,
   repository, shared runtime policy, and the PHP creation template new
   instances copy.
4. Aggregates caller-visible instances. Each instance nests only its own
   workspaces, processes, and WebSocket, analytics, and
   route bindings.
5. Returns the app detail view backed by the registry.

Workspace rows are exposed only below their owning instance and only for
`app-dev`. Production placements never contribute workspaces, and an
`app-prod` caller receives app and instance details without workspace facts.

`app:show` does not:

- Mutate gateway configuration or node state.
- Fix drift or adopt node reality (use [`doctor --family=instance`](../instance-doctor.md)).
- Create new release or deployment artifacts.
- SSH into any instance serving node directly from the caller.
- Run live readiness, runtime container, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Output

Human output is a logical registry summary followed by one placement table.
The table contains one row per visible instance and indents each workspace
below its owner. Instance and workspace URLs are shown when the registry can
derive them. App-level process and route summaries stay in the logical
summary. Apps have no server, path, root, URL, domain, or environment fields.
The `APP DEPS` column repeats the logical app's aggregate dependency posture on
instance rows; workspace rows render `—`.

JSON output returns the app record under a machine-readable output. See the
[JSON renderer contract](technical/6.2_app-show_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The target app is visible to the current node identity through at least one
  concrete Orbit instance on a serving node where the caller has
  `app:read`. Gateway callers can inspect every instance.

## Related Commands

Use these commands to take action after inspecting an app with `app:show`.

- [`app:new`](../1_app-new/app-new.md) — create or clone an app
- [`app:list`](../3_app-list/app-list.md) — list registered apps
- [`app:remove`](../6_app-remove/app-remove.md) — remove an app
- [`doctor --family=instance`](../instance-doctor.md) — verify and repair app drift
- [`node:show`](../../1_node/4_node-show/node-show.md) — inspect a selected instance's serving node

## Technical Contract

See [`app:show` technical contract](technical/1_app-show.md).
