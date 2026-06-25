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
orbit process:add queue "php artisan queue:work" --app=docs --restart-policy=always
orbit process:add horizon "php artisan horizon" --app=docs --workspace=feature-docs --runtime=systemd
orbit process:add opencode-server "opencode serve -a" --node=app-dev-1 --runtime=systemd --tool=opencode
orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3
orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3 --image=docker.io/library/mysql:8.3
orbit process:add redis --node=database-1 --service=redis --runtime=docker --version=7
orbit process:add mailpit --node=beast --service=mailpit --runtime=docker
orbit process:add mailpit --node=beast --service=mailpit --runtime=docker --replace-container=dngdmt-mailpit-1 --force
orbit process:add file-watcher "watch.sh" --app=static-site --runtime=systemd
orbit process:add vite "npm run dev" --app=docs --json
```

## Behavior Summary

Use this command to define a managed process for a node, app, or workspace.

- **Gateway Configuration**: Creates process configuration on the gateway for the resolved owner scope.
- **Scope Resolution**: `--node` creates a node-owned process and cannot be combined with `--app` or `--workspace`; `--workspace` creates a workspace-owned process; otherwise `--app` creates an app-owned process.
- **Runtime Unit Rendering**: Node-owned and workspace-owned definitions normally render one runtime unit. App-owned definitions render one main-app unit and one unit for each existing workspace.
- **Runtime Boundary**: App/workspace host-command processes use `systemd` on Linux. `docker-swarm` is only valid for node-owned managed service processes. `docker` is valid for managed services and Orbit-managed runtime processes.
- **Runtime Naming**: `systemctl` is the node command adapter, not the runtime name.
- **Tool Dependency**: `--tool=<tool>` records the installed node capability the process uses. The process still owns start, stop, restart, and logs.
- **Managed Service**: `--service=<service>` materializes a node-owned
  runnable service process. The process name is independent of the service
  identifier; `mysql8` is only a process name and never implies MySQL.
  Mailpit publishes SMTP on the owning node. Its Web UI stays private on the
  Docker network and should be exposed through a proxy route to
  `http://mailpit:8025` when browser access is needed.
  `--version` selects the service version. For Docker services, service +
  runtime + version resolve the default official image; `--image` overrides that
  image explicitly. Managed services cannot use `--tool` and are not valid for
  app or workspace scopes.
- **Replacement Containers**: `--replace-container=<name>` is an explicit
  migration escape hatch for node-owned Docker managed services. It removes the
  named Docker container on the target node before creating the Orbit-managed
  process. Repeat the option for multiple known blockers and pass `--force` in
  non-interactive mode. Orbit never discovers or removes arbitrary Docker
  containers on its own.
- **Drift Reporting**: Reports repairable runtime-unit apply drift. Configuration creation is not treated as failed once the configuration write succeeds.

### Render and start dispatch

`process:add` starts rendered runtime units by default. Use `--no-start` to
create configuration and render units without starting them. `--start` remains
accepted as a backward-compatible redundant flag.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:update`](../2_process-update/process-update.md)
- [`process:edit`](../2_process-edit/process-edit.md) compatibility alias
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-add.md`](technical/1_process-add.md)
