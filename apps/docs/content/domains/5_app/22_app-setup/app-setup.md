# `orbit app:setup [app]`

[Back to App commands.](../README.md)

Run the recorded setup pipeline for an app.

## Usage

```bash
orbit app:setup {app} [--json|--stream-json]
```

Use this command after registering or updating an app that has setup steps.
`app:setup` runs ordered setup steps on the app's owning node.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `app` | Existing app slug or hostname. |
| `--json` | Render one final JSON response. |
| `--stream-json` | Render progress events as JSON lines. |

## Behavior Summary

Setup steps run through the selected app user's host tool path from the app
source path. PHP, Composer, and Artisan commands include the app node host PHP
toolchain selected by the app's configured PHP version.

Setup steps receive the app URL and Laravel Vite development-server environment
fields: `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`,
`VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`.

Setup skips when the latest completed setup run used the same ordered step set.

## Requirements

The app must exist and must have an owning node. Running setup requires
`app:write` on that node.

## Output Summary

Human output shows progress and a final app URL. JSON output returns the setup
run, per-step status, and captured command output.

## Examples

```bash
orbit app:setup dlf-leden --stream-json
```

## Related

- [`orbit app-setup-step:add`](../23_app-setup-step-add/app-setup-step-add.md)
- [`orbit app-setup-step:list`](../24_app-setup-step-list/app-setup-step-list.md)
- [`orbit app-setup-step:remove`](../25_app-setup-step-remove/app-setup-step-remove.md)

## Technical Contract

See [`app:setup` technical contract](technical/1_app-setup.md).
Use `--stream-json` when an agent needs progress events for a long-running setup
run. Use `--json` for a final machine-readable response.
