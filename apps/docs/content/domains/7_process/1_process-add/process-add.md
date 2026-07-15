# `orbit process:add [name] [command]`

[Back to Process commands.](../README.md)

Add a node-, app-instance-, or workspace-owned process definition.

`process:add` defines a managed process, including its command or service
definition, owner scope, runtime backend, optional tool dependency, restart
policy, and crash-notification policy. Use it for node-level services,
long-running app-instance or workspace workers, and development servers.

## Usage

```bash
orbit process:add vite "npm run dev" --app=docs.production --crash-notification=agent_ide
orbit process:add queue "php artisan queue:work" --app=docs.production --restart-policy=always
orbit process:add horizon "php artisan horizon" --app=docs.production --workspace=feature-docs --runtime=systemd
orbit process:add feedback "php artisan feedback:work" --app=feedback.development --runtime=launchd
orbit process:add opencode-server "opencode serve -a" --node=app-dev-1 --runtime=systemd --tool=opencode-cli
orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3
orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3 --image=docker.io/library/mysql:8.3
orbit process:add redis --node=database-1 --service=redis --runtime=docker --version=7
orbit process:add mailpit --node=beast --service=mailpit --runtime=docker
orbit process:add mailpit --node=beast --service=mailpit --runtime=docker --replace-container=dngdmt-mailpit-1 --force
orbit process:add file-watcher "watch.sh" --app=static-site.production --runtime=systemd
orbit process:add vite "npm run dev" --app=docs.production --json
```

## Behavior Summary

Use this command to define a managed process for a node, app instance, or workspace.

- **Gateway Configuration**: Creates process configuration on the gateway for the resolved owner scope.
- **Scope Resolution**: `--node` creates a node-owned process and cannot be combined with `--app` or `--workspace`; `--workspace` creates a workspace-owned process for that workspace's app instance; otherwise `--app` creates a process owned by the selected app instance. Prefer `<app.instance>`; a bare app slug is accepted only when that logical app has exactly one instance.
- **Runtime Unit Rendering**: Node-owned and workspace-owned definitions normally render one runtime unit. App-instance-owned definitions render one main-instance unit and one unit for each active workspace belonging to that same instance, all on the instance's serving node.
- **Runtime Boundary**: Host-command processes default to `systemd` on Linux
  nodes and `launchd` on macOS nodes.
- **Managed Runtime Boundary**: Managed services default to `docker` unless
  their catalog entry and node platform admit another service runtime.
  `docker-swarm` is Linux-only and valid only for selected node-owned managed
  service processes. `docker` is valid for managed services and Orbit-managed
  runtime processes.
- **Runtime Naming**: `systemctl` and `launchctl` are node command adapters, not runtime names.
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
create configuration and render units without starting them.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:update`](../2_process-update/process-update.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-add.md`](technical/1_process-add.md)
