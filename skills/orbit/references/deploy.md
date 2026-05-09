# Deploy Commands

Run the deployment pipeline for production apps. Pipeline steps are stored on the gateway and executed in order on the app node. Spec: [`docs/commands/10_deploy/`](../../../docs/commands/10_deploy/).

Production-only — development apps use `workspace:setup` instead.

## `orbit deploy:run [app]`

Run the configured pipeline for one app.

```bash
orbit deploy:run [<app>] [--detach] [--json]
```

| Option | Notes |
|---|---|
| `app` | Production app slug or domain. |
| `--detach` | Start the run, return as soon as it's durable. Default streams progress until complete. |

Examples:

```bash
orbit deploy:run myapp                # stream pipeline output
orbit deploy:run myapp --detach       # fire-and-return; check status with deploy:history
orbit deploy:run myapp.com --json
```

## `orbit deploy:history [app]`

List deployment runs (most recent first).

```bash
orbit deploy:history [<app>] [--limit=50] [--json]
```

Each entry has run id, started/finished timestamps, status, and which step failed if any.

## `orbit deploy:log [app] [run]`

Show stored deployment output for a run.

```bash
orbit deploy:log [<app>] [<run>] [--step=<id>] [--lines=500] [--json]
```

`--step` scopes to one pipeline step. `--lines` per captured stream (stdout, stderr).

## `orbit deploy:step-add [app] [command]`

Add a step to the pipeline.

```bash
orbit deploy:step-add [<app>] [<command>] [--title='<text>']
                      [--order=<n>] [--timeout=600] [--retention=<meta>] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `app` | — | Production app slug or domain. |
| `command` | — | Shell command run on the app node, in the app's release path. |
| `--title` | command | Display title in step lists / output. |
| `--order` | append | Positive integer insertion order. |
| `--timeout` | 600 | Seconds. |
| `--retention` | — | Optional release-retention metadata used by retention-aware steps. |

Examples:

```bash
orbit deploy:step-add myapp 'composer install --no-dev --optimize-autoloader' --title='install deps'
orbit deploy:step-add myapp 'php artisan migrate --force' --title='migrate'
orbit deploy:step-add myapp 'php artisan optimize' --title='cache'
orbit deploy:step-add myapp 'sudo systemctl reload php8.5-fpm' --title='fpm reload'
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
