# `orbit app:list`

[Back to Apps commands.](../README.md)

List apps registered on the gateway.

`app:list` provides a high-level summary of logical application records and
their visible workspaces. Concrete placement belongs to app instances and
workspaces, not to the logical app list. For live app health and runtime
verification, use
[`doctor --family=app`](../app-doctor.md).
There is intentionally no `app:list --doctor` flag; app list output stays a
fast registry read.

## Usage

```bash
orbit app:list [--json]
```

## Examples

```bash
orbit app:list
orbit app:list --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

Run `app:list` to read visible logical apps from the gateway:

1. Connects to the gateway API.
2. Reads visible logical apps from concrete Orbit instance placement.
   Gateway callers can inspect every logical app.
3. Attaches only workspaces whose concrete app-instance placement is visible to
   the caller.
4. Returns the logical apps with their names, primary URLs, and visible
   registered workspaces.

`app:list` does not:
- SSH into nodes.
- Probe app health or path existence (use [`doctor --family=app`](../app-doctor.md)).
- Mutate gateway configuration or node artifacts.
- Treat an app's default node metadata as runtime placement or list scope.

## Output

Both renderers use the same deterministic ordering: logical apps are sorted by
app name (alphabetical, case-insensitive).

Human output presents one table. Any visible workspaces registered to an app
are shown immediately below that app as indented child rows, using the
workspace URL and lifecycle status.

JSON output returns a flat list of apps in the same order under the standard
machine-readable result. Each app item includes its registered workspaces as a nested
`workspaces` array. See the
[JSON renderer contract](technical/6.2_app-list_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller identity can read at least one concrete Orbit app instance.
- An `app-dev` or `app-prod` self grant exposes logical apps with an instance
  on that node.
- Additional grants can expose more logical apps and their placement-scoped
  workspaces.

## Related Commands

Use these commands alongside `app:list` to manage and inspect apps.

- [`app:new`](../1_app-new/app-new.md) — create or clone an app
- [`app:instance list`](../19_app-instance/app-instance.md) — inspect concrete app placements
- [`app:show`](../4_app-show/app-show.md) — inspect a single app's details
- [`doctor --family=app`](../app-doctor.md) — verify and repair app drift
- [`node:list`](../../1_node/3_node-list/node-list.md) — list registered nodes

## Technical Contract

See [`app:list` technical contract](technical/1_app-list.md).
