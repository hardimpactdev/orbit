# Schedule Commands

Recurring tasks evaluated by the Orbit Scheduler daemon. Minute-resolution. Spec: [`apps/docs/content/domains/9_schedule/`](../../../../apps/docs/content/domains/9_schedule/).

Schedule scopes:

- **Instance-scoped** (`--instance=<app.instance>`): runs on the instance's serving node.
- **Node-scoped** (`--node=<name>`): runs on the named node, no app context.
- **Orbit-scoped** (no `--instance` / `--node`): runs on the gateway for Orbit-owned maintenance.

## `orbit schedule:add [name]`

Create a recurring schedule.

```bash
orbit schedule:add [<name>] [--command='<shell>' | --script=<path>]
                   --interval='<expr>' [--instance=<name>] [--node=<name>]
                   [--timezone=UTC] [--timeout=900] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | Schedule slug. |
| `--command` |  -  | Inline shell command. |
| `--script` |  -  | Managed script path (alternative to `--command`). One of the two is required. |
| `--interval` | required | Portable interval expression (e.g. `every 5 minutes`, `daily at 03:00`, `cron(*/15 * * * *)`). See [`apps/docs/content/domains/9_schedule/schedule-concepts.md`](../../../../apps/docs/content/domains/9_schedule/schedule-concepts.md). |
| `--instance` |  -  | Instance scope. |
| `--node` |  -  | Node scope. |
| `--timezone` | `UTC` | IANA timezone. |
| `--timeout` | `900` | Execution timeout in seconds (`1` through `86400`). |

Examples:

```bash
orbit schedule:add nightly-backup --command='./scripts/backup.sh' \
  --interval='daily at 02:30' --timezone='Europe/Amsterdam' --node=prod-1

orbit schedule:add prune-cache --command='php artisan cache:prune-stale-tags' \
  --interval='every 15 minutes' --instance=myapp.development
```

## `orbit schedule:list`

```bash
orbit schedule:list [--instance=<name>] [--node=<name>] [--json]
```

## `orbit schedule:show <name>`

```bash
orbit schedule:show <name> [--instance=<name>] [--node=<name>] [--json]
```

The scope filters disambiguate when the same name exists in multiple scopes.

## `orbit schedule:remove <name>`

```bash
orbit schedule:remove <name> [--instance=<name>] [--node=<name>] [--force] [--json]
```

## `orbit schedule:run <name>`

Run a configured schedule once, immediately (bypasses the interval). Useful for verifying behavior or recovering after the daemon was down.

```bash
orbit schedule:run <name> [--instance=<name>] [--node=<name>] [--json]
```

## `orbit schedule:logs <name>`

Show captured stdout/stderr for a schedule run.

```bash
orbit schedule:logs <name> [--instance=<name>] [--node=<name>]
                   [--run=<id>] [--lines=100] [--json]
```

Without `--run`, shows the most recent run.
