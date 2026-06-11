# `orbit process:add [name] [command]`

[Back to Process commands.](../README.md)

Add a node-, app-, or workspace-owned process definition.

`process:add` defines a managed process, including its command or service
definition, owner scope, runtime backend, optional tool dependency, restart
policy, and crash-notification policy. Use it for node-level services,
long-running app or workspace workers, and development servers.

## Usage

```bash
orbit process:add vite "npm run dev" --app=docs --crash-notification=agent_ide
orbit process:add queue "php artisan queue:work" --app=docs --restart-policy=always --start
orbit process:add horizon "php artisan horizon" --app=docs --workspace=feature-docs --runtime=supervisor
orbit process:add opencode-server "opencode serve -a" --node=app-dev-1 --runtime=systemd --tool=opencode
orbit process:add mysql8 --node=database-1 --definition=mysql --definition-version=8 --runtime=docker-swarm
orbit process:add redis --node=database-1 --definition=redis --definition-version=7
orbit process:add file-watcher "watch.sh" --app=static-site --runtime=supervisor
orbit process:add vite "npm run dev" --app=docs --json
```

## Behavior Summary

Use this command to define a managed process for a node, app, or workspace.

- **Gateway Configuration**: Creates process configuration on the gateway for the resolved owner scope.
- **Scope Resolution**: `--node` creates a node-owned process and cannot be combined with `--app` or `--workspace`; `--workspace` creates a workspace-owned process; otherwise `--app` creates an app-owned process.
- **Runtime Unit Rendering**: Node-owned and workspace-owned definitions normally render one runtime unit. App-owned definitions render one main-app unit and one unit for each existing workspace.
- **Runtime Boundary**: App/workspace host-command processes use `supervisor`. `systemd` is only valid for node-owned Linux service processes. `docker-swarm` is only valid for node-owned managed service processes. `docker` is valid for service definitions and Orbit-managed runtime processes.
- **Runtime Naming**: `systemctl` is the node command adapter, not the runtime name.
- **Tool Dependency**: `--tool=<tool>` records the installed node capability the process uses. The process still owns start, stop, restart, and logs.
- **Service Definition**: `--definition=<mysql|redis>` materializes a
  node-owned runnable service process. The definition supplies command,
  runtime configuration, endpoints, credentials, ports, volumes, labels, and
  version defaults. Service definitions cannot use `--tool` and are not valid
  for app or workspace scopes.
- **Drift Reporting**: Reports repairable runtime-unit apply drift. Configuration creation is not treated as failed once the configuration write succeeds.

### Idle render and start dispatch

Rendering does not start the runtime unit. Supervisor units render with
`autostart=false`; systemd units are enabled but not started. The `--start` flag
is required to actually run the unit through the selected runtime backend.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:edit`](../2_process-edit/process-edit.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-add.md`](technical/1_process-add.md)
