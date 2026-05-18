# Process Commands

Long-running app-owned processes (queue workers, websocket servers, vite dev server, …). Each process definition renders as one Supervisor program per app/workspace target. Runtime unit name: `orbit_<app>_<workspace|main>_<process>`. Spec: [`docs/domains/7_process/`](../../../docs/domains/7_process/).

## `orbit process:add [name] [command]`

Add a process definition for an app.

```bash
orbit process:add [<name>] [<command>] [--app=<name>]
                  [--restart-policy=never|on_failure|always]
                  [--crash-notification=none|agent_ide]
                  [--start] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | Process slug (≤64 chars). |
| `command` | — | Shell command (run inside the app/workspace path). |
| `--app` | — | Parent app slug. |
| `--restart-policy` | `never` | Supervisor restart behavior. |
| `--crash-notification` | `none` | `agent_ide` posts crash notes to the effective Agent IDE adapter. |
| `--start` | off | Start the rendered runtime units immediately. |

Examples:

```bash
orbit process:add queue 'php artisan queue:work --tries=3' --app=myapp \
  --restart-policy=always --crash-notification=agent_ide --start

orbit process:add reverb 'php artisan reverb:start' --app=myapp \
  --restart-policy=on_failure --start
```

## `orbit process:edit [name]`

Edit a process definition. Only the supplied fields change.

```bash
orbit process:edit [<name>] [--app=<name>] [--command='<shell>']
                   [--restart-policy=<p>] [--crash-notification=<n>]
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
