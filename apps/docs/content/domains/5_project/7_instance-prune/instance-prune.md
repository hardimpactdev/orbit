# `orbit instance:prune [project.instance]`

[Back to Project and instance commands.](../README.md)

Remove stale workspaces owned by one concrete `app-dev` instance.

`instance:prune` compares one instance's workspace registry with the workspaces
reported by its effective agent IDE sources and removes only workspaces owned
by that instance that are absent from every source.

This is an `app-dev`-only workspace operation. `app-prod` callers and target
apps are rejected before adapter discovery, including in dry-run mode.

## Usage

```bash
# Preview stale workspaces for the "docs" app
orbit instance:prune docs.development --dry-run

# Remove stale workspaces with confirmation
orbit instance:prune docs.development

# Force-remove stale workspaces without confirmation (e.g. in a cron job)
orbit instance:prune docs.development --force

# Get machine-readable cleanup results
orbit instance:prune docs.development --json --force
```

## Arguments and options

- `instance`: Dotted instance selector. A bare logical slug is shorthand only
  when exactly one eligible visible instance exists. Hostnames are invalid.
- `--dry-run`: Shows which workspaces would be removed without performing any side effects.
- `--force`: Skips the interactive confirmation prompt. Required for
  non-interactive execution only when `--dry-run` is absent.
- `--json`: Outputs structured JSON data instead of human-readable text.

## Behavior Summary

Run `instance:prune` to compare Orbit's workspace registry against configured agent IDE adapters and remove stale entries.

### Stale Detection

Identifies workspaces tracked in Orbit's registry that have no match in any configured agent IDE adapter.

### Instance-Scoped

Pruning is instance-scoped even when the effective adapter is inherited from
that instance's serving node. Changing a node default with
[`node:agent-ide`](../../1_node/10_node-agent-ide/node-agent-ide.md) does not
automatically prune every inheriting instance. Run `instance:prune` for each affected
instance when cleanup is wanted.

### Dry Run

`--dry-run` returns the computed stale-workspace set without deleting workspace configuration or node artifacts. Other destructive commands that act on an explicit named target do not inherit `--dry-run` from this command.

### Removal Semantics

When removing a stale workspace, Orbit applies the same removal semantics as [`workspace:remove`](../../6_workspace/5_workspace-remove/workspace-remove.md). Gateway workspace configuration is removed first, then node-side cleanup runs through the normal workspace removal order, including teardown steps.

### Database Limitation

Database cleanup requires Orbit to explicitly track a database as workspace-owned. No such tracking mechanism exists in gateway configuration today, so `instance:prune` always reports databases as **skipped** for manual cleanup. Orbit does not infer database ownership from names, environment files, or conventions. User-authored database removal can be expressed as a workspace teardown step.

## Requirements

- The caller must have `instance:prune` on the selected instance's serving node. A gateway-role
  caller has the architecture's documented implicit authority.
- The caller must not be `app-prod`, and the selected instance must be served by an
  active `app-dev` node.
- The target instance must be resolved and authorized.
- The instance must have an effective agent IDE adapter configured directly or inherited from its serving node.
- The gateway must be able to query the effective agent IDE adapter(s).
  Agent reachability is not a pre-prune prerequisite. Node cleanup uses Agent
  push; cleanup
  failures after workspace configuration removal are reported as warnings with repair
  commands.

## Output Summary

Use `--json` to receive structured output; omit it for human-readable progress.

### Human

Progress grouped by stale workspace, showing the cleanup status for each artifact.

### JSON

A machine-readable result containing the project slug, dotted instance
selector, serving node, and only that instance's processed workspaces.

## Related

- [`orbit project:remove`](../6_project-remove/project-remove.md): Remove the entire project and all its workspaces.
- [`orbit workspace:remove`](../../6_workspace/5_workspace-remove/workspace-remove.md): Manually remove a specific workspace.
- [`doctor --family=instance`](../instance-doctor.md): Diagnose and fix instance-level drift.

***

**Technical Contract:** [technical/1_instance-prune.md](technical/1_instance-prune.md)
