# `orbit deploy:step-add [app] [command]`

[Back to Deploy commands.](../README.md)

Add a deployment pipeline step to a production app.

Use `deploy:step-add` to define one shell command or multiline script that runs during
[`deploy:run`](../4_deploy-run/deploy-run.md). The command records deployment
policy on the gateway; it does not execute the step.

## Usage

```bash
orbit deploy:step-add [app] [command] [--title=<title>] [--order=<number>] [--timeout=<seconds>] [--retention=<count>] [--json]
```

## Examples

```bash
orbit deploy:step-add docs "git pull origin main" --title="Pull latest"
orbit deploy:step-add docs "php artisan migrate --force" --order=20 --timeout=120 --title="Run migrations"
orbit deploy:step-add docs "./release.sh" --retention=5
orbit deploy:step-add docs $'cd {{ release_path }}\ncomposer install --no-dev --optimize-autoloader --no-interaction' --title="Install dependencies"
```

## Arguments And Options

- `app`: production app name or domain.
- `command`: shell command or multiline shell script to run during deployment.
- `--title`: display label. Defaults to a concise command-derived title.
- `--order`: insertion order. Defaults to the next position.
- `--timeout`: maximum step runtime in seconds. Defaults to `600`.
- `--retention`: optional release-retention value for this step. Use only for
  steps that create or prune versioned releases.
- `--json`: Output JSON.

## What Happens

`deploy:step-add` validates the production app, command, timeout, and optional
metadata, then writes one gateway-owned deployment step definition for the app.
It does not execute the step, inspect node state, create app process
definitions, or apply global deployment retention.

Step commands may reference deploy-run context values such as
`{{ release }}`, `{{ app_path }}`, `{{ release_path }}`, and `{{ app_user }}`.
The values are rendered when `deploy:run` starts, not when the step is added.

## Output

Human output confirms the added step and its order.

JSON output returns the created deploy step entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to manage deployment policy for the production app.
- App-node callers are denied before prompts or side effects.

## Related

- [`orbit deploy:step-list`](../2_deploy-step-list/deploy-step-list.md)
- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:step-add` technical contract](technical/1_deploy-step-add.md).
