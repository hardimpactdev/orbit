# `orbit project:list`

[Back to Project and instance commands.](../README.md)

List projects registered on the gateway.

`project:list` provides a compact inventory of projects.
Concrete placement belongs to instances and workspaces, so the list shows
only aggregate counts and leaves placement detail to
[`project:show`](../4_project-show/project-show.md). For live project health and runtime verification, use
[`doctor --family=instance`](../instance-doctor.md).
There is intentionally no `project:list --doctor` flag; app list output stays a
fast registry read.

## Usage

```bash
orbit project:list [--json]
```

## Examples

```bash
orbit project:list
orbit project:list --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

Run `project:list` to read visible projects from the gateway:

1. Connects to the gateway API.
2. Reads visible projects from concrete Orbit instance placement.
   Gateway callers can inspect every project.
3. Counts only concrete instances and workspaces whose placement is visible
   to the caller.
4. Returns each project as one compact row with its repository, aggregate
   dependency posture, and visible instance/workspace counts.
5. In interactive human mode, presents those rows in the Laravel Prompts data
   list and opens the selected app's `project:show` drill-down.

`project:list` does not:
- SSH into nodes.
- Probe project health or path existence (use [`doctor --family=instance`](../instance-doctor.md)).
- Mutate gateway configuration or node artifacts.
- Treat an app's default node metadata as runtime placement or list scope.

## Output

Both renderers use the same deterministic ordering: projects are sorted by
project name (alphabetical, case-insensitive).

Human output presents the Laravel Prompts data list with `Name`, `Repository`,
`Instances`, and `Workspaces` columns. Selecting a row opens the same instance
and workspace placement detail as `project:show <project>`.
Human output requires an interactive terminal. Scripts and other
non-interactive callers use `orbit project:list --json`.

JSON output returns a flat list of compact project summaries. Counts live
on the matching app row; there is no parallel inventory and no node, URL, path,
runtime, instance, or nested workspace placement. See the
[JSON renderer contract](technical/6.2_project-list_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller identity can read at least one concrete Orbit instance.
- An `app-dev` or `app-prod` self grant exposes projects with an instance
  on that node.
- Additional grants can expose more projects and increase the placement
  counts visible to the caller. Workspaces can contribute only through
  `app-dev` instances because production instances do not own workspaces.

## Related Commands

Use these commands alongside `project:list` to manage and inspect projects.

- [`project:new`](../1_project-new/project-new.md) — create or clone an app
- [`instance:list`](../19_instance-list/instance-list.md) — inspect concrete placements
- [`project:show`](../4_project-show/project-show.md) — inspect a single app's details
- [`doctor --family=instance`](../instance-doctor.md) — verify and repair app drift
- [`node:list`](../../1_node/3_node-list/node-list.md) — list registered nodes

## Technical Contract

See [`project:list` technical contract](technical/1_project-list.md).
