# `orbit workspace-teardown-step:remove`

Remove a workspace teardown step from a concrete instance.

## Usage

```bash
orbit workspace-teardown-step:remove --step=<id> --instance=<app.instance> [--force] [--json]
```

## Description

The `workspace-teardown-step:remove` command deletes a teardown step definition
from an instance's workspace lifecycle policy. Removing the step excludes it from
future workspace removal and app prune teardown runs.

Removing a step definition does not undo side effects (such as removed files or
database cleanups) from previous executions of the step. It only updates the
policy used for future executions.

## Arguments

- `--step=<id>`: The ID of the teardown step to remove. Required.
- `--instance=<app.instance>`: Concrete dotted instance selector, such as
  `my-app.development`. A caller context may supply the same concrete instance,
  but a bare project slug is rejected with an instance-required
  validation error before side effects. The exact error shape is defined by the
  [JSON renderer contract](technical/6.2_workspace-teardown-step-remove_output-render_json.md).
- `--force`: Skip interactive confirmation.
- `--json`: Output structured JSON payload.

## Behavior

The following rules govern how the step is removed.

- **Destructive Consent**: This command requires explicit confirmation because
  it permanently deletes a policy definition. Use `--force` to bypass the
  confirmation prompt.
- **Order Compaction**: After a step is removed, Orbit renumbers the remaining
  teardown steps for the selected instance to maintain a continuous,
  gap-free execution order.
- **Future Runs Only**: In-flight teardown runs continue using the ordered step
  list they snapshotted at teardown-phase entry. Removal affects later runs.
- **History Preservation**: Removal does not mutate existing workspace run
  history. Past executions of the removed step remain visible in
  [`workspace:history`](../6_workspace-history/workspace-history.md).
- **Artifact Preservation**: This command does not remove files, artifacts, or
  databases affected by previous executions of the step on nodes.

## Examples

### Remove a step with confirmation

This prompts before removing the step.

```bash
orbit workspace-teardown-step:remove --step=18 --instance=my-app.development
```

### Force remove a step without prompting

Use `--force` to skip the confirmation prompt.

```bash
orbit workspace-teardown-step:remove --step=18 --instance=my-app.development --force
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy on the selected app
  instance's serving node.
- The target instance exists.

## Related

- [`orbit workspace-teardown-step:list`](../12_workspace-teardown-step-list/workspace-teardown-step-list.md)
- [`orbit workspace-teardown-step:add`](../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
- [`orbit workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-teardown-step-remove.md)
