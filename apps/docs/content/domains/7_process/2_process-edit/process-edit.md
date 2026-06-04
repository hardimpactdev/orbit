# `orbit process:edit [name]`

[Back to Process commands.](../README.md)

Update a node-, app-, or workspace-owned process definition.

`process:edit` changes a process command, restart policy, crash notification
policy, or process runtime for the resolved owner scope. It re-renders every
runtime unit derived from that process definition.

## Usage

```bash
orbit process:edit vite --app=docs --command="npm run dev"
orbit process:edit queue --app=docs --restart-policy=on_failure --restart
orbit process:edit horizon --app=docs --workspace=feature-docs --command="php artisan horizon"
orbit process:edit opencode-server --node=app-dev-1 --command="opencode serve -a" --runtime=systemd
orbit process:edit watcher --app=docs --runtime=supervisor
orbit process:edit vite --app=docs --command="npm run dev" --json
```

## Behavior Summary

Use this command to update a process definition and re-render its runtime units.

- **Gateway Update**: Updates the gateway-owned process definition.
- **Scope Resolution**: `--node` edits a node-owned process and cannot be combined with `--app` or `--workspace`; `--workspace` edits a workspace-owned process; otherwise `--app` edits an app-owned process.
- **Runtime Unit Re-rendering**: Re-renders the runtime units derived from the selected process definition.
- **Runtime Boundary**: `systemd` is only valid for node-owned Linux service processes. `docker-swarm` is only valid for node-owned managed service processes.
- **Restart Behavior**: Does not restart running runtime units unless `--restart` is supplied.
- **Drift Reporting**: Reports repairable runtime-unit apply drift after successful configuration changes.

## Related

- [`process:add`](../1_process-add/process-add.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-edit.md`](technical/1_process-edit.md)
