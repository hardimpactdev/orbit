# `orbit app-setup-step:list [app]`

[Back to App commands.](../README.md)

List the recorded setup steps for an app.

## Usage

```bash
orbit app-setup-step:list {app} [--app=<app>] [--json]
```

Use this command before changing or running setup when you need the current
ordered step set.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `app` | Existing app slug or hostname. |
| `--app` | App selector for scripts where the positional argument is awkward. |
| `--json` | Render JSON. |

## Behavior Summary

The command reads gateway state only. It does not inspect the app host or run
setup commands.

## Requirements

The caller needs `app:read` on the app's owning node.

## Output Summary

Human output lists setup steps in run order. JSON output returns the
machine-readable step list.

## Examples

```bash
orbit app-setup-step:list dlf-leden
```

## Related

- [`orbit app:setup`](../22_app-setup/app-setup.md)

## Technical Contract

See [`app-setup-step:list` technical contract](technical/1_app-setup-step-list.md).
