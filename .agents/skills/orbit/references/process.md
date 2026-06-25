# Process Commands

Long-running app-owned processes (queue workers, websocket servers, vite dev server, ...). On Linux, each app/workspace host-command process definition renders as one systemd unit per target. Runtime unit name: `orbit_<app>_<workspace|main>_<process>`. Spec: [`apps/docs/content/domains/7_process/`](../../../apps/docs/content/domains/7_process/).

## `orbit process:add [name] [command]`

Add a process definition for an app, workspace, or node.

```bash
orbit process:add [<name>] [<command>] [--app=<name>] [--node=<node>]
                  [--service=<mysql|redis>] [--version=<version>] [--image=<image>]
                  [--restart-policy=never|on_failure|always]
                  [--crash-notification=none|agent_ide]
                  [--replace-container=<name>] [--force]
                  [--no-start] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | Process slug (<=64 chars). Independent of `--service`. |
| `command` |  -  | Shell command (run inside the app/workspace path). Omit when `--service` is present. |
| `--app` |  -  | Parent app slug. |
| `--node` |  -  | Owning node for node-owned processes and managed services. |
| `--service` |  -  | Managed service identifier (`mysql`, `redis`, ...). Node-owned only. |
| `--version` | service default | Service version selector. Public CLI flag; normalized internally because Symfony reserves global `--version`. |
| `--image` | resolved catalog image | Explicit Docker image override for managed services. |
| `--replace-container` |  -  | Explicit Docker container to remove before adding a node-owned Docker managed service. Repeat for multiple known blockers. Requires `--force` in non-interactive mode. |
| `--force` | off | Confirm destructive replacement-container cleanup. |
| `--restart-policy` | `never` | Runtime restart behavior. |
| `--crash-notification` | `none` | `agent_ide` posts crash notes to the effective Agent IDE adapter. |
| `--no-start` | off | Skip starting rendered runtime units after apply. |
| `--start` | redundant | Accepted for backward compatibility; processes start by default. |

Examples:

```bash
orbit process:add queue 'php artisan queue:work --tries=3' --app=myapp \
  --restart-policy=always --crash-notification=agent_ide

orbit process:add reverb 'php artisan reverb:start' --app=myapp \
  --restart-policy=on_failure

orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3

orbit process:add mysql8 --node=beast --service=mysql --runtime=docker \
  --version=8.3 --image=docker.io/library/mysql:8.3 --no-start

orbit process:add mailpit --node=beast --service=mailpit --runtime=docker \
  --replace-container=dngdmt-mailpit-1 --replace-container=orbit-mailpit --force
```

## `orbit process:update [name]`

Update a process definition. Only the supplied fields change.

```bash
orbit process:update [<name>] [--app=<name>] [--command='<shell>']
                     [--name=<new-slug>] [--restart-policy=<p>]
                     [--crash-notification=<n>] [--runtime=<backend>]
                     [--restart] [--json]
```

`--restart` restarts affected runtime units after the update lands.

## `orbit process:remove [name]`

Remove a process definition and its runtime units.

```bash
orbit process:remove [<name>] [--app=<name>] [--force] [--json]
```

## `orbit process:list`

```bash
orbit process:list [--app=<name>] [--workspace=<name>] [--json]
```

Without `--workspace`, lists app-scoped definitions and the runtime units they render across the app's main path and active workspaces.

## `orbit process:start | stop | restart [name]`

Control runtime units.

```bash
orbit process:start   [<name>] [--app=<name>] [--workspace=<name>] [--json]
orbit process:stop    [<name>] [--app=<name>] [--workspace=<name>] [--json]
orbit process:restart [<name>] [--app=<name>] [--workspace=<name>] [--json]
```

Without `--workspace`, the command targets the main app instance. Use `--workspace=<slug>` to target a specific workspace's rendered unit.

## `orbit process:logs [name]`

Read runtime logs.

```bash
orbit process:logs [<name>] [--app=<name>] [--workspace=<name>]
                   [--follow] [--lines=100] [--json]
```

`--follow` streams new lines as they arrive (Ctrl-C to stop).
