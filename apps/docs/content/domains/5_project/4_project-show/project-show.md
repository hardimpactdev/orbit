# `orbit project:show [project]`

[Back to Project and instance commands.](../README.md)

Show one app's gateway registry details.

Use `project:show` when you need an app's gateway-owned registry record and
placement breakdown: repository, shared PHP version, visible instances,
and each instance's path, effective agent IDE, workspaces, processes, and
WebSocket, analytics, or route bindings. Live
runtime drift, readiness, and repair belong to
[`doctor --family=instance`](../instance-doctor.md).

## Usage

```bash
orbit project:show [project] [--json]
```

Run without the `project` argument to let the command resolve the target app from
the current local directory context.

## Examples

```bash
orbit project:show
orbit project:show docs
orbit project:show docs.test
orbit project:show docs --json
```

## Arguments and options

- `project`: project name or app hostname to inspect. Optional. Defaults to the app
  resolved from the current working directory context. See
  [Default resolution](technical/5.1_project-show_input-mode_interactive.md#default-resolution)
  for interactive behavior and
  [Non-interactive default resolution](technical/5.2_project-show_input-mode_non-interactive.md#default-resolution)
  for non-interactive behavior.
- `--json`: Output JSON.

Project slugs are globally unique in the gateway project registry. The positional
already addresses an app uniquely; `project:show` does not accept a `--node` flag.

## What Happens

Run `project:show` to inspect a single project's gateway configuration without triggering any writes.

`project:show` performs a read-only registry inspection of a single project:

1. Resolves the target app from input, current directory context, or
   interactive prompt.
2. Validates that the current caller can inspect at least one concrete Orbit app
   instance, unless the caller is the gateway.
3. Reads the project record from gateway-owned configuration: name,
   repository, shared runtime policy, and PHP version.
4. Aggregates caller-visible instances. Each instance nests only its own
   effective agent IDE, workspaces, processes, and WebSocket, analytics, and
   route bindings.
5. Returns the app detail view backed by the registry.

Workspace rows are exposed only below their owning instance and only for
`app-dev`. Production placements never contribute workspaces, and an
`app-prod` caller receives project and instance details without workspace facts.

`project:show` does not:

- Mutate gateway configuration or node state.
- Fix drift or adopt node reality (use [`doctor --family=instance`](../instance-doctor.md)).
- Create new release or deployment artifacts.
- SSH into any instance serving node directly from the caller.
- Run live readiness, runtime container, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Output

Human output is a logical registry summary followed by one section per visible
instance with its effective IDE, processes, bindings, and nested workspace rows.
Instance and workspace URLs are shown when the
registry can derive them. Projects have no server, path, root, URL, domain,
or environment fields. The `APP DEPS` column is the project's aggregate
dependency posture; workspace rows render `—`.

JSON output returns the project record under a machine-readable output. See the
[JSON renderer contract](technical/6.2_project-show_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The target app is visible to the current node identity through at least one
  concrete Orbit instance on a serving node where the caller has
  `project:read`. Gateway callers can inspect every instance.

## Related Commands

Use these commands to take action after inspecting an app with `project:show`.

- [`project:new`](../1_project-new/project-new.md) — create or clone an app
- [`project:list`](../3_project-list/project-list.md) — list registered projects
- [`project:remove`](../6_project-remove/project-remove.md) — remove an app
- [`doctor --family=instance`](../instance-doctor.md) — verify and repair app drift
- [`node:show`](../../1_node/4_node-show/node-show.md) — inspect a selected instance's serving node

## Technical Contract

See [`project:show` technical contract](technical/1_project-show.md).
