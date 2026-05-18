# `orbit workspace-teardown-step:remove`

Remove a workspace teardown step from an app.

## Usage

```bash
orbit workspace-teardown-step:remove --step=<id> [--app=<app>] [--force] [--json]
```

## Description

The `workspace-teardown-step:remove` command deletes a teardown step definition
from an app's workspace lifecycle policy. Once removed, the step will no longer
execute during future workspace removal or app prune teardown runs.

Removing a step definition does not undo side effects (such as removed files or
database cleanups) from previous executions of the step. It only updates the
policy used for future executions.

## Arguments

- `--step=<id>`: The ID of the teardown step to remove. Required.
- `--app=<app>`: The parent app slug. When omitted, Orbit infers the app from
  the current directory.
- `--force`: Skip interactive confirmation.
- `--json`: Output structured JSON payload.

## Behavior

The following rules govern how the step is removed.

- **Destructive Consent**: This command requires explicit confirmation because
  it permanently deletes a policy definition. Use `--force` to bypass the
  confirmation prompt.
- **Order Compaction**: After a step is removed, Orbit renumbers the remaining
  teardown steps for the app to maintain a continuous, gap-free execution order.
- **Future Runs Only**: In-flight teardown runs continue using the ordered step
  list they snapshotted at teardown-phase entry. Removal affects later runs.
- **History Preservation**: Removal does not mutate existing workspace run
  history. Past executions of the removed step remain visible in
  [`workspace:history`](../6_workspace-history/workspace-history.md).
- **Artifact Preservation**: This command does not remove files, artifacts, or
  databases affected by previous executions of the step on app nodes.

## Examples

### Remove a step with confirmation

This prompts before removing the step.

```bash
orbit workspace-teardown-step:remove --step=18
```

### Force remove a step without prompting

Use `--force` to skip the confirmation prompt.

```bash
orbit workspace-teardown-step:remove --step=18 --force
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy for the target app.
- The target app exists.

## Related

- [`orbit workspace-teardown-step:list`](../12_workspace-teardown-step-list/workspace-teardown-step-list.md)
- [`orbit workspace-teardown-step:add`](../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
- [`orbit workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-teardown-step-remove.md)
