# `orbit app-setup-step:add [app]`

[Back to App commands.](../README.md)

Add one command to an app setup pipeline.

## Usage

```bash
orbit app-setup-step:add [app] --command=<command> [--app=<app>] [--before=<id>|--after=<id>] [--timeout=600] [--json]
```

Use this command to record finite bootstrap work for an app.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `app` | Existing app slug or hostname. |
| `--command` | Shell command to run from the app path during `app:setup`. |
| `--app` | App selector for scripts where the positional argument is awkward. |
| `--before` | Insert before this setup step id. |
| `--after` | Insert after this setup step id. |
| `--timeout` | Per-step timeout in seconds. |
| `--json` | Render JSON. |

## Behavior Summary

The gateway stores the step without running it. Run `app:setup` to execute the
pipeline.

## Requirements

The caller needs `app:write` on the app's owning node.

## Output Summary

Human output names the created step. JSON output returns the setup step entity.

## Examples

```bash
orbit app-setup-step:add dlf-leden --command="composer install --no-interaction"
```

## Related

- [`orbit app:setup`](../22_app-setup/app-setup.md)

## Technical Contract

See [`app-setup-step:add` technical contract](technical/1_app-setup-step-add.md).
