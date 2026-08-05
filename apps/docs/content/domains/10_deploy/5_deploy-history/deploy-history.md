# `orbit deploy:history [instance]`

[Back to Deploy commands.](../README.md)

List deployment runs for a concrete production instance.

Use `deploy:history` to inspect durable deployment attempts recorded on the
gateway. The command reads stored deployment history; it does not probe the
owning node or re-check application health.

## Usage

```bash
orbit deploy:history [instance] [--limit=<count>] [--json]
```

## Examples

```bash
orbit deploy:history docs.production
orbit deploy:history docs.production --limit=10
orbit deploy:history docs.production --json
```

## Arguments and options

- `instance`: dotted production instance selector. A bare app name or domain is
  valid only when the app has exactly one instance.
- `--limit`: Maximum number of runs to return. Defaults to `50`; hard cap
  `500`.
- `--json`: Output JSON.

## What Happens

Run `deploy:history` when you want to see past deployment attempts for one
production instance. It resolves the concrete instance, reads that
instance's run history from gateway state, sorts newest runs first, and renders
the selected output.

## Output

Pass `--json` to receive machine-readable output; omit it to see a run list with run id, status, start time, duration, and failed step summary when available.

Use `--json` for machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `deploy:read` on the instance's owning Orbit node, or on the
  gateway when the instance is external.

## Related

- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)
- [`orbit deploy:log`](../6_deploy-log/deploy-log.md)
- [`doctor --family=instance`](../../5_app/instance-doctor.md)

## Technical Contract

See [`deploy:history` technical contract](technical/1_deploy-history.md).
