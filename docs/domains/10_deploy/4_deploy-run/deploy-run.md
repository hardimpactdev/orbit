# `orbit deploy:run [app]`

[Back to Deploy commands.](../README.md)

Run the deployment pipeline for a production app.

Use `deploy:run` when the app already has deployment steps configured through
[`deploy:step-add`](../1_deploy-step-add/deploy-step-add.md). The command
creates durable deployment history on the gateway, executes each step on the
app's owning node through the gateway, and records captured output.

## Usage

```bash
orbit deploy:run [app] [--detach] [--json]
```

## Examples

```bash
orbit deploy:run docs
orbit deploy:run docs --detach
orbit deploy:run docs --json
```

## Arguments and options

- `app`: production app name or domain.
- `--detach`: start the deployment under gateway control and return after the
  run has been created.
- `--json`: Output JSON.

## What Happens

Use `deploy:run` when you want to execute the configured deployment pipeline for a production app. `deploy:run` resolves the production app, reads its ordered deployment steps,
creates a gateway deployment run with a reusable run context, renders
`{{ ... }}` placeholders in each step, and executes the configured shell
scripts on the app's owning node through the gateway. It stops at the first
failed step, captures output for every executed step, and updates the app's
latest deployment status.

Available run context includes `release`, `app_path`, `releases_path`,
`release_path`, `live_path`, `env_path`, `storage_path`, `database_path`,
`app_user`, `app_name`, `domain`, and `repository`. The same scalar values are
also exported as `ORBIT_DEPLOY_*` environment variables.

Detached runs return the run identifier without streaming step output.

## Output

Run without `--json` to see progress, streamed deployment output, and a final run summary.

JSON output returns the deployment run entity and captured output for bounded
foreground runs. Detached JSON output returns the created run with
`status=running`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to deploy the production app.
- The gateway can reach the app's owning node.
- App-role callers are denied before prompts or side effects.

## Related

- [`orbit deploy:step-list`](../2_deploy-step-list/deploy-step-list.md)
- [`orbit deploy:history`](../5_deploy-history/deploy-history.md)
- [`orbit deploy:log`](../6_deploy-log/deploy-log.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:run` technical contract](technical/1_deploy-run.md).
