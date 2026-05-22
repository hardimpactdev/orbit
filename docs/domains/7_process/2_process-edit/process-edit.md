# `orbit process:edit [name]`

[Back to Process commands.](../README.md)

Update an app-owned process definition.

`process:edit` changes a process command, restart policy, crash notification
policy, or process runtime. It re-renders every runtime unit derived from that
process definition.

## Usage

```bash
orbit process:edit vite --app=docs --command="npm run dev"
orbit process:edit queue --app=docs --restart-policy=on_failure --restart
orbit process:edit watcher --app=docs --runtime=supervisor
orbit process:edit vite --app=docs --command="npm run dev" --json
```

## Behavior Summary

Use this command to update a process definition and re-render its runtime units.

- **Gateway Update**: Updates the gateway-owned process definition.
- **Runtime Unit Re-rendering**: Re-renders the main app runtime unit and every workspace runtime unit derived from the process definition.
- **Restart Behavior**: Does not restart running runtime units unless `--restart` is supplied.
- **Drift Reporting**: Reports repairable runtime-unit apply drift after successful configuration changes.

## Related

- [`process:add`](../1_process-add/process-add.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-edit.md`](technical/1_process-edit.md)
