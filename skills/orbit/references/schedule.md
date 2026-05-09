# Schedule Commands

Recurring tasks evaluated by the Orbit Scheduler daemon (one Supervisor program per gateway / app node). Minute-resolution. Spec: [`docs/commands/9_schedule/`](../../../docs/commands/9_schedule/).

Schedule scopes:

- **App-scoped** (`--app=<name>`): runs on the app's owning node.
- **Node-scoped** (`--node=<name>`): runs on the named node, no app context.
- **Orbit-scoped** (no `--app` / `--node`): runs on the gateway for Orbit-owned maintenance.

## `orbit schedule:add [name]`

Create a recurring schedule.

```bash
orbit schedule:add [<name>] [--command='<shell>' | --script=<path>]
                   --interval='<expr>' [--app=<name>] [--node=<name>]
                   [--timezone=UTC] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` | — | Schedule slug. |
| `--command` | — | Inline shell command. |
| `--script` | — | Managed script path (alternative to `--command`). One of the two is required. |
| `--interval` | required | Portable interval expression (e.g. `every 5 minutes`, `daily at 03:00`, `cron(*/15 * * * *)`). See [`docs/commands/9_schedule/schedule-concepts.md`](../../../docs/commands/9_schedule/schedule-concepts.md). |
| `--app` | — | App scope. |
| `--node` | — | Node scope. |
| `--timezone` | `UTC` | IANA timezone. |

Examples:

```bash
orbit schedule:add nightly-backup --command='./scripts/backup.sh' \
  --interval='daily at 02:30' --timezone='Europe/Amsterdam' --node=prod-1

orbit schedule:add prune-cache --command='php artisan cache:prune-stale-tags' \
  --interval='every 15 minutes' --app=myapp
```

## `orbit schedule:list`

```bash
orbit schedule:list [--app=<name>] [--node=<name>] [--json]
```

## `orbit schedule:show <name>`

```bash
orbit schedule:show <name> [--app=<name>] [--node=<name>] [--json]
```

The scope filters disambiguate when the same name exists in multiple scopes.

## `orbit schedule:remove <name>`

```bash
orbit schedule:remove <name> [--app=<name>] [--node=<name>] [--force] [--json]
```

## `orbit schedule:run <name>`

Run a configured schedule once, immediately (bypasses the interval). Useful for verifying behavior or recovering after the daemon was down.

```bash
orbit schedule:run <name> [--app=<name>] [--node=<name>] [--json]
```

## `orbit schedule:logs <name>`

Show captured stdout/stderr for a schedule run.

```bash
orbit schedule:logs <name> [--app=<name>] [--node=<name>]
                   [--run=<id>] [--lines=100] [--json]
```

Without `--run`, shows the most recent run.
