# `orbit process:add [name] [command]`

[Back to Process commands.](../README.md)

**Purpose:** Add an app-owned process definition.

**Description:** Defines a managed process for an application, including its command, restart policy, and crash-notification policy. Use it for long-running services, workers, and development servers that Orbit should render as runtime units.

**Technical contract:** [`technical/1_process-add.md`](technical/1_process-add.md)

## Usage

```bash
orbit process:add vite "npm run dev" --app=docs --crash-notification=agent_ide
orbit process:add queue "php artisan queue:work" --app=docs --restart-policy=always --start
orbit process:add vite "npm run dev" --app=docs --json
```

## Behavior

- Creates app-owned process configuration on the gateway.
- Renders one runtime unit for the main app instance and one for each existing workspace.
- Does not start rendered runtime units unless `--start` is supplied.
- Reports repairable runtime-unit apply drift without treating process configuration creation as failed after the configuration write has succeeded.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:edit`](../2_process-edit/process-edit.md)
- [`process-doctor.md`](../process-doctor.md)
