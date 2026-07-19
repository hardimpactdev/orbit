# `orbit app:remove [app]`

[Back to App commands.](../README.md)

Remove one logical app and its complete instance cascade.

`app:remove` accepts a logical app slug only. It never accepts a hostname,
dotted instance selector, or parent-only removal mode. To remove one concrete
placement while keeping the logical app, use
[`app:instance remove`](../19_app-instance/app-instance.md).

## Usage

```bash
orbit app:remove <app> [--force] [--json]
```

## Arguments and options

- `app`: Exact logical app slug. Required.
- `--force`: Approve removal of the logical app, every instance, and every
  dependent artifact without an interactive prompt.
- `--json`: Output JSON and use non-interactive input. JSON never implies
  destructive consent, so `--force` is also required.

## Examples

```bash
orbit app:remove my-app
orbit app:remove my-app --force --json
```

## What Happens

Use this command when you want to remove a logical app and every instance it
owns as one confirmed cascade.

Before prompting or changing state, Orbit:

1. Resolves the exact logical app slug.
2. Enumerates every app instance, serving node, and dependent proxy route,
   schedule, workspace, process, runtime, and eligible managed path.
3. Authorizes `app:remove` across the complete set of serving nodes. One denial
   stops the entire command; removal is never partially authorized.
4. In interactive mode, renders `app_remove.confirm` with the names of every
   instance and the complete dependent counts. Declining cancels all removal.

After consent, Orbit removes the logical app, every instance, and all
gateway-owned dependents in one transaction. It then cleans each instance's
serving-node artifacts through Agent push. Progress and cleanup results stay
per-instance, with aggregate totals in JSON.
Cleanup that cannot finish after gateway configuration removal is reported as
drift with the responsible family doctor command.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The logical app exists.
- The caller has `app:remove` on every affected Orbit instance's serving node.
- Non-interactive execution, including `--json`, includes `--force`.

## Related Commands

Use these commands to inspect apps, remove one instance, or repair remaining
drift.

- [`app:instance`](../19_app-instance/app-instance.md) - remove one placement
  while retaining the logical app and its other instances.
- [`app:list`](../3_app-list/app-list.md) - list logical apps.
- [`doctor --family=app`](../app-doctor.md) - inspect remaining app drift.

## Technical Contract

See [`app:remove` technical contract](technical/1_app-remove.md).
