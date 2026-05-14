# `orbit process:add [name] [command]`

[Back to Process commands.](../README.md)

Add an app-owned process definition.

`process:add` defines a managed process for an application, including its
command, restart policy, and crash-notification policy. Use it for long-running
services, workers, and development servers.

## Usage

```bash
orbit process:add vite "npm run dev" --app=docs --crash-notification=agent_ide
orbit process:add queue "php artisan queue:work" --app=docs --restart-policy=always --start
orbit process:add vite "npm run dev" --app=docs --json
```

## Behavior Summary

Use this command to define a managed process for an app.

- **Gateway Configuration**: Creates app-owned process configuration on the gateway.
- **Runtime Unit Rendering**: Renders one runtime unit for the main app instance and one for each existing workspace.
- **Start Behavior**: Does not start rendered runtime units unless `--start` is supplied.
- **Drift Reporting**: Reports repairable runtime-unit apply drift. Configuration creation is not treated as failed once the configuration write succeeds.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:edit`](../2_process-edit/process-edit.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-add.md`](technical/1_process-add.md)
