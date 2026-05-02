# `orbit deploy:log [app] [run]`

[Back to Deploy commands.](../README.md)

Show captured output for one deployment run.

Use `deploy:log` to inspect stored stdout, stderr, exit codes, and step timing
for a previous deployment. The command reads durable gateway history; it does
not stream a live deployment.

## Usage

```bash
orbit deploy:log [app] [run] [--step=<id>] [--lines=<count>] [--json]
```

## Examples

```bash
orbit deploy:log docs 42
orbit deploy:log docs 42 --step=13
orbit deploy:log docs 42 --lines=200 --json
```

## Arguments And Options

- `app`: production app name or domain.
- `run`: deployment run id from [`deploy:history`](../5_deploy-history/deploy-history.md).
- `--step`: limit output to one step id.
- `--lines`: maximum number of captured output lines per stream. Defaults to
  `500`.
- `--json`: Output JSON.

## What Happens

`deploy:log` resolves the production app and run id, verifies the run belongs
to that app, reads captured per-step output from gateway deployment history,
applies optional step and line filters, and renders the selected output.

## Output

Human output shows the run summary and captured output grouped by deployment
step.

JSON output returns the run entity and per-step captured output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to inspect deployment history for the production
  app.

## Related

- [`orbit deploy:history`](../5_deploy-history/deploy-history.md)
- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:log` technical contract](technical/1_deploy-log.md).
