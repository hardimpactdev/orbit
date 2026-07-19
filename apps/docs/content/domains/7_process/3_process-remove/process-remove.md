# `orbit process:remove [name]`

[Back to Process commands.](../README.md)

Remove a node-, app-instance-, or workspace-owned process definition and its rendered
runtime units.

`process:remove` deletes process configuration from the gateway, then stops and
removes the derived runtime units for the resolved owner scope.

## Usage

```bash
orbit process:remove vite --app=docs.production
orbit process:remove queue --app=docs.production --force
orbit process:remove horizon --app=docs.development --workspace=feature-docs --force
orbit process:remove opencode-server --node=app-dev-1 --force
orbit process:remove vite --app=docs.production --force --json
```

## Behavior Summary

Use this command to remove a process definition and its runtime units.

- **Destructive Consent**: Requires an interactive confirmation prompt or `--force` before side effects.
- **Gateway Removal**: Removes process configuration from the gateway for the resolved owner scope.
- **Scope Resolution**: `--node` removes a node-owned process and cannot be combined with `--app` or `--workspace`; `--workspace` removes a workspace-owned process for that workspace's app instance; otherwise `--app` removes an app-instance-owned process. Prefer `<app.instance>`; a bare app slug is accepted only when that logical app has exactly one instance.
- **Runtime Unit Cleanup**: Stops and removes runtime units derived from the selected process definition.
- **Log Preservation**: Retains process logs; they are not removed.
- **Drift Reporting**: Reports repairable cleanup drift when runtime-unit cleanup does not fully converge.

See also: [`process:add`](../1_process-add/process-add.md), [`process:list`](../4_process-list/process-list.md), [`process-doctor.md`](../process-doctor.md).

***

**Technical Contract:** [`technical/1_process-remove.md`](technical/1_process-remove.md)
