# Process Commands

Long-running app-owned processes (queue workers, websocket servers, vite dev server, ...). App/workspace host-command process definitions render as one systemd unit per target on Linux and one Orbit-owned launchd user LaunchAgent per target on macOS. Runtime unit name: `orbit_<app>_<workspace|main>_<process>`; launchd labels are `dev.hardimpact.orbit.<runtimeUnit>`. Spec: [`apps/docs/content/domains/7_process/`](../../../apps/docs/content/domains/7_process/).

App/workspace runtime units receive Laravel Vite-compatible URL/TLS env fields:
`APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and
`VITE_DEV_SERVER_CERT`. Docker-backed runtime units mount the corresponding
Orbit cert/key files at the in-container paths exposed through those variables.

## `orbit process:add [name] [command]`

Add a process definition for an app, workspace, or node.

```bash
orbit process:add [<name>] [<command>] [--instance=<name>] [--node=<node>]
                  [--service=<mysql|valkey>] [--version=<version>] [--image=<image>]
                  [--bind=wireguard|loopback]
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
| `--instance` |  -  | Parent `project.instance` selector. |
| `--node` |  -  | Owning node for node-owned processes and managed services. |
| `--service` |  -  | Managed service identifier (`mysql`, `valkey`, ...). Node-owned only. |
| `--version` | service default | Service version selector. Public CLI flag; normalized internally because Symfony reserves global `--version`. |
| `--image` | resolved catalog image | Explicit Docker image override for managed services. |
| `--bind` | `wireguard` | Repeatable. Node-owned Docker managed services only. `wireguard` publishes on the node WireGuard service address; `loopback` publishes on host-local `127.0.0.1` (not reachable as `127.0.0.1` from another container). Both publish every target port on both hosts. Rejects host commands, instance/workspace, Docker Swarm, empty/unsupported values, and arbitrary IPs. |
| `--replace-container` |  -  | Explicit Docker container to remove before adding a node-owned Docker managed service. Repeat for multiple known blockers. Requires `--force` in non-interactive mode. |
| `--force` | off | Confirm destructive replacement-container cleanup. |
| `--restart-policy` | `never` | Runtime restart behavior. |
| `--crash-notification` | `none` | `agent_ide` posts crash notes to the effective Agent IDE adapter; rejected for `launchd` until the macOS crash wrapper exists. |
| `--runtime` | platform/service default | Host commands default to `systemd` on Linux and `launchd` on macOS. Managed services default to `docker`; `docker-swarm` is Linux-only and service-catalog gated. |
| `--no-start` | off | Skip starting rendered runtime units after apply. |

Examples:

```bash
orbit process:add queue 'php artisan queue:work --tries=3' --instance=myapp.development \
  --restart-policy=always --crash-notification=agent_ide

orbit process:add reverb 'php artisan reverb:start' --instance=myapp.development \
  --restart-policy=on_failure

orbit process:add feedback 'php artisan feedback:work' --instance=feedback.development \
  --runtime=launchd

orbit process:add mysql8 --node=beast --service=mysql --runtime=docker --version=8.3

orbit process:add mysql8 --node=beast --service=mysql --runtime=docker \
  --version=8.3 --image=docker.io/library/mysql:8.3 --no-start

orbit process:add mailpit --node=beast --service=mailpit --runtime=docker \
  --replace-container=dngdmt-mailpit-1 --replace-container=orbit-mailpit --force

orbit process:add valkey --node=database-1 --service=valkey --runtime=docker \
  --bind=wireguard --bind=loopback
```

## `orbit process:update [name]`

Update a process definition. Only the supplied fields change.

```bash
orbit process:update [<name>] [--instance=<name>] [--command='<shell>']
                     [--name=<new-slug>] [--restart-policy=<p>]
                     [--crash-notification=<n>] [--runtime=<backend>]
                     [--bind=wireguard|loopback]
                     [--restart] [--json]
```

`--restart` restarts affected runtime units after the update lands. Omitting
`--runtime` preserves the current runtime; the same platform rules as
`process:add` apply when changing it. Omitting `--bind` preserves existing
publish binds for managed Docker services; supplying `--bind` replaces the
entire bind list and re-renders while preserving unrelated service config.

## `orbit process:remove [name]`

Remove a process definition and its runtime units.

```bash
orbit process:remove [<name>] [--instance=<name>] [--force] [--json]
```

## `orbit process:list`

```bash
orbit process:list [--instance=<name>] [--workspace=<name>] [--json]
```

Without `--workspace`, lists instance-scoped definitions and the runtime units
they render across the instance's main path and active workspaces.

## `orbit process:start | stop | restart [name]`

Control runtime units.

```bash
orbit process:start   [<name>] [--instance=<name>] [--workspace=<name>] [--json]
orbit process:stop    [<name>] [--instance=<name>] [--workspace=<name>] [--json]
orbit process:restart [<name>] [--instance=<name>] [--workspace=<name>] [--json]
```

Without `--workspace`, the command targets the main app instance. Use `--workspace=<slug>` to target a specific workspace's rendered unit.

## `orbit process:logs [name]`

Read runtime logs.

```bash
orbit process:logs [<name>] [--instance=<name>] [--workspace=<name>]
                   [--follow] [--lines=100] [--json]
```

`--follow` streams new lines as they arrive (Ctrl-C to stop). Launchd-backed
processes read Orbit-owned stdout/stderr files under
`~/Library/Logs/Orbit/processes`. On agent-capable nodes, bounded reads and
follow streams use gateway-to-Agent command transport and fail clearly when
Agent push is unavailable. They never fall back to SSH.
