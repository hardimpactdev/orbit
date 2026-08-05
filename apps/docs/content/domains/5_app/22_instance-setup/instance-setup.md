# `orbit instance:setup [instance]`

[Back to Project and instance commands.](../README.md)

Run the recorded setup pipeline for one concrete instance.

## Usage

```bash
orbit instance:setup {app.instance} [--json|--stream-json]
```

Use this command after registering or updating an instance that has setup
steps. `instance:setup` runs ordered setup steps on that instance's serving node and
source path.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `instance` | Dotted instance selector. A bare project slug or hostname is shorthand only when exactly one instance exists. |
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

The instance must exist and must resolve an Orbit serving node. Running
setup requires `instance:write` on that node. A bare project selector with zero
or multiple instances fails before authorization or setup with
a validation error that requires a concrete instance selector.

## Output Summary

Human output shows progress and a final app URL. JSON output returns the setup
run, per-step status, and captured command output.

## Examples

```bash
orbit instance:setup dlf-leden.production --stream-json
```

## Related

- [`orbit instance-setup-step:add`](../23_instance-setup-step-add/instance-setup-step-add.md)
- [`orbit instance-setup-step:list`](../24_instance-setup-step-list/instance-setup-step-list.md)
- [`orbit instance-setup-step:remove`](../25_instance-setup-step-remove/instance-setup-step-remove.md)

## Technical Contract

See [`instance:setup` technical contract](technical/1_instance-setup.md).
Use `--stream-json` when an agent needs progress events for a long-running setup
run. Use `--json` for a final machine-readable response.
