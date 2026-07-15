# `orbit workspace-setup-step:remove`

Remove a workspace setup step from a concrete app instance.

## Usage

```bash
orbit workspace-setup-step:remove --step=<id> --app=<app.instance> [--force] [--json]
```

## Description

The `workspace-setup-step:remove` command deletes a setup step definition from
an app instance's workspace lifecycle policy. Removing the step excludes it from
future workspace creation and setup runs.

Removing a step definition does not undo side effects (such as installed files
or database migrations) on existing app workspaces. It only updates the policy
used for future executions.

## Arguments

- `--step=<id>`: The ID of the setup step to remove. Required.
- `--app=<app.instance>`: Concrete dotted app-instance selector, such as
  `my-app.production`. A caller context may supply the same concrete instance,
  but a bare logical-app slug is rejected with an app-instance-required
  validation error before side effects. The exact error shape is defined by the
  [JSON renderer contract](technical/6.2_workspace-setup-step-remove_output-render_json.md).
- `--force`: Skip interactive confirmation.
- `--json`: Output structured JSON payload.

## Behavior

The following rules govern how the step is removed.

- **Destructive Consent**: This command requires explicit confirmation because
  it permanently deletes a policy definition. Use `--force` to bypass the
  confirmation prompt.
- **Order Compaction**: After a step is removed, Orbit renumbers the remaining
  steps for the selected app instance to maintain a continuous, gap-free
  execution order.
- **History Preservation**: Removal does not mutate existing workspace run
  history. Past executions of the removed step remain visible in
  [`workspace:history`](../6_workspace-history/workspace-history.md).
- **Artifact Preservation**: This command does not remove files, artifacts, or
  databases produced by previous executions of the step on nodes.

## Examples

### Remove a step with confirmation

This prompts before removing the step.

```bash
orbit workspace-setup-step:remove --step=12 --app=my-app.production
```

### Force remove a step without prompting

Use `--force` to skip the confirmation prompt.

```bash
orbit workspace-setup-step:remove --step=12 --app=my-app.production --force
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy on the selected app
  instance's serving node.
- The target app instance exists.

## Related

- [`orbit workspace-setup-step:list`](../9_workspace-setup-step-list/workspace-setup-step-list.md)
- [`orbit workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md)
- [`orbit workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-setup-step-remove.md)
