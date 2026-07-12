# `orbit deploy:run [app]`

[Back to Deploy commands.](../README.md)

Run the deployment pipeline for a concrete production app instance.

Use `deploy:run` when the app instance already has deployment steps configured through
[`deploy:step-add`](../1_deploy-step-add/deploy-step-add.md). The command
creates durable deployment history on the gateway, executes each step on the
instance's owning node through Agent push, and records captured output.

## Usage

```bash
orbit deploy:run [app] [--detach] [--json|--stream-json]
```

## Examples

```bash
orbit deploy:run docs.production
orbit deploy:run docs.production --detach
orbit deploy:run docs.production --json
orbit deploy:run docs.production --stream-json
```

## Arguments and options

- `app`: dotted production app-instance selector. A bare app name or domain is
  valid only when the app has exactly one instance.
- `--detach`: start the deployment under gateway control and return after the
  durable operation has been created.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON. Mutually exclusive
  with `--json`.

## What Happens

Use `deploy:run` when you want to execute the configured deployment pipeline
for one production app instance. It resolves the concrete instance, reads its ordered deployment steps,
creates a gateway deployment run with a reusable run context, renders
`{{ ... }}` placeholders in each step, and executes the configured shell
scripts on the instance's owning node through the gateway. It stops at the first
failed step, captures output for every executed step, and updates the instance's
latest deployment status.

The gateway sends each rendered command as a typed internal Agent-push request
with a structured binary and argument vector. It persists progress to the
operation journal before publishing it over the private operations
WebSocket/Reverb channel. The CLI replays any journal gap by cursor. Deployment
has no Orbit-managed SSH fallback and does not use direct SSE.

Available run context includes `release`, `app_path`, `releases_path`,
`release_path`, `live_path`, `env_path`, `storage_path`, `database_path`,
`app_user`, `app_name`, `domain`, and `repository`. The same scalar values are
also exported as `ORBIT_DEPLOY_*` environment variables.

Release-aware steps may create or prune versioned release directories and move
`live_path` within the app-instance-owned release boundary. The production runtime
service may bind mount only the app source or active release path plus
explicitly managed shared paths; `live_path`, document root, storage, and
database symlinks must not escape that boundary.

Detached runs return the durable operation identifier without subscribing to
step output.

## Output

Run without `--json` or `--stream-json` to see progress, streamed deployment
output, and a final run summary.

Foreground `--json` returns only the terminal operation frame. `--stream-json`
emits newline-delimited operation progress frames. Detached JSON output returns
the queued operation descriptor.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `deploy:run` on the production app instance's owning node.
- The app instance uses the Orbit driver with a concrete source path and an
  active Agent-eligible owning node.

## Related

- [`orbit deploy:step-list`](../2_deploy-step-list/deploy-step-list.md)
- [`orbit deploy:history`](../5_deploy-history/deploy-history.md)
- [`orbit deploy:log`](../6_deploy-log/deploy-log.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:run` technical contract](technical/1_deploy-run.md).
