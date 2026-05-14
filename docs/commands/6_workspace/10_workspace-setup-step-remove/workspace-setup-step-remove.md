# `orbit workspace-setup-step:remove`

Remove a workspace setup step from an app.

## Usage

```bash
orbit workspace-setup-step:remove --step=<id> [--app=<app>] [--force] [--json]
```

## Description

The `workspace-setup-step:remove` command deletes a setup step definition from
an app's workspace lifecycle policy. Once removed, the step will no longer
execute during new workspace creation or setup.

Removing a step definition does not undo side effects (such as installed files
or database migrations) on existing app workspaces. It only updates the policy
used for future executions.

## Arguments

- `--step=<id>`: The ID of the setup step to remove. Required.
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
  steps for the app to maintain a continuous, gap-free execution order.
- **History Preservation**: Removal does not mutate existing workspace run
  history. Past executions of the removed step remain visible in
  [`workspace:history`](../6_workspace-history/workspace-history.md).
- **Artifact Preservation**: This command does not remove files, artifacts, or
  databases produced by previous executions of the step on app nodes.

## Examples

### Remove a step with confirmation

This prompts before removing the step.

```bash
orbit workspace-setup-step:remove --step=12
```

### Force remove a step without prompting

Use `--force` to skip the confirmation prompt.

```bash
orbit workspace-setup-step:remove --step=12 --force
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy for the target app.
- The target app exists.

## Related

- [`orbit workspace-setup-step:list`](../9_workspace-setup-step-list/workspace-setup-step-list.md)
- [`orbit workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md)
- [`orbit workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-setup-step-remove.md)
