# `orbit app:list`

[Back to App and instance commands.](../README.md)

List apps registered on the gateway.

`app:list` provides a compact inventory of apps.
Concrete placement belongs to instances and workspaces, so the list shows
only aggregate counts and leaves placement detail to
[`app:show`](../4_app-show/app-show.md). For live app health and runtime verification, use
[`doctor --family=instance`](../instance-doctor.md).
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

Run `app:list` to read visible apps from the gateway:

1. Connects to the gateway API.
2. Reads visible apps from concrete Orbit instance placement.
   Gateway callers can inspect every app.
3. Counts only concrete instances and workspaces whose placement is visible
   to the caller.
4. Returns each app as one compact row with its repository, aggregate
   dependency posture, and visible instance/workspace counts.
5. In interactive human mode, presents those rows in the Laravel Prompts data
   list and opens the selected app's `app:show` drill-down.

`app:list` does not:
- SSH into nodes.
- Probe app health or path existence (use [`doctor --family=instance`](../instance-doctor.md)).
- Mutate gateway configuration or node artifacts.
- Treat an app's default node metadata as runtime placement or list scope.

## Output

Both renderers use the same deterministic ordering: apps are sorted by
app name (alphabetical, case-insensitive).

Human output presents the Laravel Prompts data list with `Name`, `Repository`,
`Instances`, and `Workspaces` columns. Selecting a row opens the same instance
and workspace placement detail as `app:show <app>`.
Human output requires an interactive terminal. Scripts and other
non-interactive callers use `orbit app:list --json`.

JSON output returns a flat list of compact app summaries. Counts live
on the matching app row; there is no parallel inventory and no node, URL, path,
runtime, instance, or nested workspace placement. See the
[JSON renderer contract](technical/6.2_app-list_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller identity can read at least one concrete Orbit instance.
- An `app-dev` or `app-prod` self grant exposes apps with an instance
  on that node.
- Additional grants can expose more apps and increase the placement
  counts visible to the caller. Workspaces can contribute only through
  `app-dev` instances because production instances do not own workspaces.

## Related Commands

Use these commands alongside `app:list` to manage and inspect apps.

- [`app:new`](../1_app-new/app-new.md) — create or clone an app
- [`instance:list`](../19_instance-list/instance-list.md) — inspect concrete placements
- [`app:show`](../4_app-show/app-show.md) — inspect a single app's details
- [`doctor --family=instance`](../instance-doctor.md) — verify and repair app drift
- [`node:list`](../../1_node/3_node-list/node-list.md) — list registered nodes

## Technical Contract

See [`app:list` technical contract](technical/1_app-list.md).
