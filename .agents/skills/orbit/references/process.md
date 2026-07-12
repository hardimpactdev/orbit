# Process Commands

Long-running app-owned processes (queue workers, websocket servers, vite dev server, ...). App/workspace host-command process definitions render as one systemd unit per target on Linux and one Orbit-owned launchd user LaunchAgent per target on macOS. Runtime unit name: `orbit_<app>_<workspace|main>_<process>`; launchd labels are `dev.hardimpact.orbit.<runtimeUnit>`. Spec: [`apps/docs/content/domains/7_process/`](../../../apps/docs/content/domains/7_process/).

App/workspace runtime units receive Laravel Vite-compatible URL/TLS env fields:
`APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and
`VITE_DEV_SERVER_CERT`. Docker-backed runtime units mount the corresponding
Orbit cert/key files at the in-container paths exposed through those variables.

## `orbit process:add [name] [command]`

Add a process definition for an app, workspace, or node.

```bash
orbit process:add [<name>] [<command>] [--app=<name>] [--node=<node>]
                  [--service=<mysql|redis>] [--version=<version>] [--image=<image>]
                  [--restart-policy=never|on_failure|always]
                  [--crash-notification=none|agent_ide]
                  [--runtime=docker|docker-swarm|systemd|launchd]
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
| `--crash-notification` | `none` | `agent_ide` posts crash notes to the effective Agent IDE adapter; rejected for `launchd` until the macOS crash wrapper exists. |
| `--runtime` | platform/service default | Host commands default to `systemd` on Linux and `launchd` on macOS. Managed services default to `docker`; `docker-swarm` is Linux-only and service-catalog gated. |
| `--no-start` | off | Skip starting rendered runtime units after apply. |

Examples:

```bash
orbit process:add queue 'php artisan queue:work --tries=3' --app=myapp \
  --restart-policy=always --crash-notification=agent_ide

orbit process:add reverb 'php artisan reverb:start' --app=myapp \
  --restart-policy=on_failure

orbit process:add feedback 'php artisan feedback:work' --app=feedback \
  --runtime=launchd

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

`--restart` restarts affected runtime units after the update lands. Omitting
`--runtime` preserves the current runtime; the same platform rules as
`process:add` apply when changing it.

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

`--follow` streams new lines as they arrive (Ctrl-C to stop). Launchd-backed
processes read Orbit-owned stdout/stderr files under
`~/Library/Logs/Orbit/processes`. On agent-capable nodes, bounded reads and
follow streams use the gateway-to-Agent command transport; explicit
exact-marked `transitional-ssh` fallback is reserved for the remaining migration
seam until it is ported.
