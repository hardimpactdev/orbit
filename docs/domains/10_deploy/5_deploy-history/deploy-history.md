# `orbit deploy:history [app]`

[Back to Deploy commands.](../README.md)

List deployment runs for a production app.

Use `deploy:history` to inspect durable deployment attempts recorded on the
gateway. The command reads stored deployment history; it does not probe the
owning node or re-check application health.

## Usage

```bash
orbit deploy:history [app] [--limit=<count>] [--json]
```

## Examples

```bash
orbit deploy:history docs
orbit deploy:history docs --limit=10
orbit deploy:history docs --json
```

## Arguments and options

- `app`: production app name or domain.
- `--limit`: Maximum number of runs to return. Defaults to `50`; hard cap
  `500`.
- `--json`: Output JSON.

## What Happens

Run `deploy:history` when you want to see past deployment attempts for a production app. `deploy:history` resolves the production app, reads deployment run history from gateway app state, sorts newest runs first, and renders the selected output.

## Output

Pass `--json` to receive machine-readable output; omit it to see a run list with run id, status, start time, duration, and failed step summary when available.

Use `--json` for machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller has `deploy:read` on the production app's owning node.

## Related

- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)
- [`orbit deploy:log`](../6_deploy-log/deploy-log.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:history` technical contract](technical/1_deploy-history.md).
