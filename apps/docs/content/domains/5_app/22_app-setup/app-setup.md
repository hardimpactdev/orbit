# `orbit app:setup [app]`

[Back to App commands.](../README.md)

Run the recorded setup pipeline for one concrete app instance.

## Usage

```bash
orbit app:setup {app.instance} [--json|--stream-json]
```

Use this command after registering or updating an app instance that has setup
steps. `app:setup` runs ordered setup steps on that instance's serving node and
source path.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `app` | Dotted app-instance selector. A bare app slug or hostname is shorthand only when exactly one instance exists. |
| `--json` | Render one final JSON response. |
| `--stream-json` | Render progress events as JSON lines. |

## Behavior Summary

Setup steps run through the selected instance's app user host tool path from
that instance's source path. PHP, Composer, and Artisan commands include the
instance serving node's host PHP toolchain selected by the app's configured PHP
version.

Setup steps receive the app URL and Laravel Vite development-server environment
fields: `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`,
`VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`.

Setup skips when the latest completed setup run used the same ordered step set.

## Requirements

The app instance must exist and must resolve an Orbit serving node. Running
setup requires `app:write` on that node. A bare logical-app selector with zero
or multiple instances fails before authorization or setup with
a validation error that requires a concrete app-instance selector.

## Output Summary

Human output shows progress and a final app URL. JSON output returns the setup
run, per-step status, and captured command output.

## Examples

```bash
orbit app:setup dlf-leden.production --stream-json
```

## Related

- [`orbit app-setup-step:add`](../23_app-setup-step-add/app-setup-step-add.md)
- [`orbit app-setup-step:list`](../24_app-setup-step-list/app-setup-step-list.md)
- [`orbit app-setup-step:remove`](../25_app-setup-step-remove/app-setup-step-remove.md)

## Technical Contract

See [`app:setup` technical contract](technical/1_app-setup.md).
Use `--stream-json` when an agent needs progress events for a long-running setup
run. Use `--json` for a final machine-readable response.
