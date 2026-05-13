# `orbit app:prune [app]`

[Back to Apps commands.](../README.md)

**Purpose:** Remove stale workspaces for an app.

## Usage

```bash
orbit app:prune [app] [--dry-run] [--force] [--json]
```

## Examples

```bash
# Preview stale workspaces for the "docs" app
orbit app:prune docs --dry-run

# Remove stale workspaces with confirmation
orbit app:prune docs

# Force-remove stale workspaces without confirmation (e.g. in a cron job)
orbit app:prune docs --force

# Get machine-readable cleanup results
orbit app:prune docs --json --force
```

## Arguments And Options

- `app`: The name or hostname of the app to prune.
- `--dry-run`: Shows which workspaces would be removed without performing any side effects.
- `--force`: Skips the interactive confirmation prompt. Required for
  non-interactive execution only when `--dry-run` is absent.
- `--json`: Outputs structured JSON data instead of human-readable text.

## What Happens

`app:prune` compares Orbit's workspace registry for an app with the workspaces reported by its configured agent IDE adapters. If Orbit tracks a workspace that no longer exists in any of those source-of-truth adapters, it is considered "stale" and is removed.

Pruning is app-scoped even when the effective adapter is inherited from the
owning node. Changing a node default with
[`node:agent-ide`](../../1_node/10_node-agent-ide/node-agent-ide.md) does not
automatically prune every inheriting app; run `app:prune` for the affected apps
when stale workspace cleanup is wanted.

`--dry-run` exists for `app:prune` because the command discovers its destructive
target set from external adapter state before removing anything. The preview
returns that computed stale-workspace set without deleting workspace configuration or
node artifacts. Other destructive commands that act on an explicit named target
do not inherit `--dry-run` from this command.

When removing a stale workspace, Orbit applies the same removal semantics as
[`workspace:remove`](../../6_workspace/5_workspace-remove/workspace-remove.md):
gateway workspace configuration is removed first, then node-side cleanup runs through
the normal workspace removal order, including teardown steps.

**Current limitation: databases.** Database cleanup requires Orbit to explicitly
track a database as workspace-owned. No such tracking mechanism exists in
gateway configuration today, so `app:prune` always reports databases as **skipped** for
manual cleanup. Orbit does not infer database ownership from names, environment
files, or conventions. User-authored database removal can be expressed as a
workspace teardown step.

## Output

- **Human output:** A step tree grouped by stale workspace, showing the cleanup progress for each artifact.
- **JSON output:** A `success` or `error` envelope containing the app details and a list of processed workspaces.

## Requirements

- The caller must be a `control` or `gateway` node.
- The target app must be resolved and authorized.
- The app must have at least one agent IDE adapter configured (directly or inherited).
- The gateway must be able to query the effective agent IDE adapter(s).
  App-node SSH cleanup reachability is not a pre-prune prerequisite; cleanup
  failures after workspace configuration removal are reported as warnings with repair
  commands.

## Related Commands

- [`orbit app:remove`](../6_app-remove/app-remove.md) — Remove the entire app and all its workspaces.
- [`orbit workspace:remove`](../../6_workspace/5_workspace-remove/workspace-remove.md) — Manually remove a specific workspace.
- [`doctor --family=app`](../app-doctor.md) — Diagnose and fix app-level drift.

[View Technical Contract](technical/1_app-prune.md)
