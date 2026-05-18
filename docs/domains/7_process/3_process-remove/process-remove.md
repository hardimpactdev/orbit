# `orbit process:remove [name]`

[Back to Process commands.](../README.md)

Remove an app-owned process definition and its rendered runtime units.

`process:remove` deletes process configuration from the gateway, then stops and
removes the derived runtime units for the main app instance and every workspace.

## Usage

```bash
orbit process:remove vite --app=docs
orbit process:remove queue --app=docs --force
orbit process:remove vite --app=docs --force --json
```

## Behavior Summary

Use this command to remove a process definition and its runtime units.

- **Destructive Consent**: Requires an interactive confirmation prompt or `--force` before side effects.
- **Gateway Removal**: Removes app-owned process configuration from the gateway.
- **Runtime Unit Cleanup**: Stops and removes derived runtime units for the main app instance and all workspaces.
- **Log Preservation**: Does not remove historical logs.
- **Drift Reporting**: Reports repairable cleanup drift when runtime-unit cleanup does not fully converge.

## Related

- [`process:add`](../1_process-add/process-add.md)
- [`process:list`](../4_process-list/process-list.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-remove.md`](technical/1_process-remove.md)
