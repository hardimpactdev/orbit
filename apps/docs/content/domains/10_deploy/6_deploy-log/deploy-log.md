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
orbit deploy:log docs.production 42
orbit deploy:log docs.production 42 --step=13
orbit deploy:log docs.production 42 --lines=200 --json
```

## Arguments and options

- `app`: dotted production app-instance selector. A bare app name or domain is
  valid only when the app has exactly one instance.
- `run`: deployment run id from [`deploy:history`](../5_deploy-history/deploy-history.md).
- `--step`: limit output to one step id.
- `--lines`: maximum number of captured output lines per stream. Defaults to
  `500`.
- `--json`: Output JSON.

## What Happens

Run `deploy:log` when you need to inspect the captured output from a specific
deployment run. It resolves the production app instance and run id, verifies
the run belongs to that instance, reads captured per-step output from gateway
history, applies optional step and line filters, and renders the selected output.

## Output

Pass `--json` to receive machine-readable output; omit it to see the run summary and captured output grouped by deployment step.

JSON output returns the run entity and per-step captured output.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `deploy:read` on the instance's owning Orbit node, or on the
  gateway when the instance is external.

## Related

- [`orbit deploy:history`](../5_deploy-history/deploy-history.md)
- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:log` technical contract](technical/1_deploy-log.md).
