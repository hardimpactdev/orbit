# Deploy Commands

Run the deployment pipeline for concrete production app instances. Policy,
ordered steps, runs, history, logs, and latest status are stored against one
instance on the gateway. Steps execute in order on that instance's `app-prod`
node through Agent push. Spec:
[`apps/docs/content/domains/10_deploy/`](../../../apps/docs/content/domains/10_deploy/).

Production-only  -  development apps use `workspace:setup` instead.

## `orbit deploy:run [app]`

Run the configured pipeline for one production app instance.

```bash
orbit deploy:run [<app>] [--detach] [--json|--stream-json]
```

| Option | Notes |
|---|---|
| `app` | Production app-instance selector or domain. A bare app slug is shorthand only when exactly one instance exists. |
| `--detach` | Start the run, return as soon as it's durable. Default streams progress until complete. |
| `--stream-json` | JSONL deployment progress for agents; mutually exclusive with `--json`. |

Examples:

```bash
orbit deploy:run myapp                # stream pipeline output
orbit deploy:run myapp --detach       # fire-and-return; check status with deploy:history
orbit deploy:run myapp.com --json
orbit deploy:run myapp.com --stream-json
```

## `orbit deploy:history [app]`

List deployment runs (most recent first).

```bash
orbit deploy:history [<app>] [--limit=50] [--json]
```

Selection follows the same exact-instance rule as `deploy:run`. Each entry has
run id, started/finished timestamps, status, and which step failed if any.

## `orbit deploy:log [app] [run]`

Show stored deployment output for a run.

```bash
orbit deploy:log [<app>] [<run>] [--step=<id>] [--lines=500] [--json]
```

`--step` scopes to one pipeline step. `--lines` per captured stream (stdout, stderr).
The run must belong to the selected app instance.

## `orbit deploy:step-add [app] [command]`

Add a step to the pipeline.

```bash
orbit deploy:step-add [<app>] [<command>] [--title='<text>']
                      [--order=<n>] [--timeout=600] [--retention=<meta>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `app` |  -  | Production app-instance selector or domain; bare app requires exactly one instance. |
| `command` |  -  | Command run through Agent push on the owning app-prod node, in the instance's release path. |
| `--title` | command | Display title in step lists / output. |
| `--order` | append | Positive integer insertion order. |
| `--timeout` | 600 | Seconds. |
| `--retention` |  -  | Optional release-retention metadata used by retention-aware steps. |

Examples:

```bash
orbit deploy:step-add myapp 'composer install --no-dev --optimize-autoloader' --title='install deps'
orbit deploy:step-add myapp 'php artisan migrate --force' --title='migrate'
orbit deploy:step-add myapp 'php artisan optimize' --title='cache'
orbit deploy:step-add myapp 'php artisan optimize' --title='optimize'
```

## `orbit deploy:step-list [app]`

```bash
orbit deploy:step-list [<app>] [--json]
```

## `orbit deploy:step-remove [app] [step]`

```bash
orbit deploy:step-remove [<app>] [<step>] [--force] [--json]
```

`<step>` accepts either the step id or the exact title.

Attached runs stream durable journal progress over the gateway operations
WebSocket; `--detach` returns after the operation and initial journal record are
durable.
