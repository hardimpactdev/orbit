# `orbit project:remove [project]`

[Back to Project and instance commands.](../README.md)

Remove one project and its complete instance cascade.

`project:remove` accepts a project slug only. It never accepts a hostname,
dotted instance selector, or parent-only removal mode. To remove one concrete
placement while keeping the project, use
[`instance:remove`](../28_instance-remove/instance-remove.md).

## Usage

```bash
orbit project:remove <project> [--force] [--json]
```

## Arguments and options

- `project`: Exact project slug. Required.
- `--force`: Approve removal of the project, every instance, and every
  dependent artifact without an interactive prompt.
- `--json`: Output JSON and use non-interactive input. JSON never implies
  destructive consent, so `--force` is also required.

## Examples

```bash
orbit project:remove my-app
orbit project:remove my-app --force --json
```

## What Happens

Use this command when you want to remove a project and every instance it
owns as one confirmed cascade.

Before prompting or changing state, Orbit:

1. Resolves the exact project slug.
2. Enumerates every instance, serving node, and dependent proxy route,
   schedule, workspace, process, runtime, and eligible managed path.
3. Authorizes `project:remove` across the complete set of serving nodes. One denial
   stops the entire command; removal is never partially authorized.
4. In interactive mode, renders `app_remove.confirm` with the names of every
   instance and the complete dependent counts. Declining cancels all removal.

After consent, Orbit removes the project, every instance, and all
gateway-owned dependents in one transaction. It then cleans each instance's
serving-node artifacts through Agent push. Progress and cleanup results stay
per-instance, with aggregate totals in JSON.
Cleanup that cannot finish after gateway configuration removal is reported as
drift with the responsible family doctor command.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The project exists.
- The caller has `project:remove` on every affected Orbit instance's serving node.
- Non-interactive execution, including `--json`, includes `--force`.

## Related Commands

Use these commands to inspect projects, remove one instance, or repair remaining
drift.

- [`instance:remove`](../28_instance-remove/instance-remove.md) - remove one placement
  while retaining the project and its other instances.
- [`project:list`](../3_project-list/project-list.md) - list projects.
- [`doctor --family=instance`](../instance-doctor.md) - inspect remaining app drift.

## Technical Contract

See [`project:remove` technical contract](technical/1_project-remove.md).
