# `orbit app-setup-step:remove [app]`

[Back to App commands.](../README.md)

Remove one setup step from an app-instance setup pipeline.

## Usage

```bash
orbit app-setup-step:remove [app] --step=<id> [--app=<app>] [--force] [--json]
```

Use this command to delete a bootstrap command from the app setup pipeline.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `app` | Dotted app-instance selector, or a bare app shorthand when exactly one instance exists. |
| `--step` | Setup step id. |
| `--app` | App-instance selector for scripts where the positional argument is awkward. |
| `--force` | Skip destructive confirmation. |
| `--json` | Render JSON. |

## Behavior Summary

The command deletes the setup step record. Existing setup runs and captured
history remain available.

## Requirements

The caller needs `app:write` on the selected instance's serving node.

## Output Summary

Human output names the removed step. JSON output returns the removed step and
metadata.

## Examples

```bash
orbit app-setup-step:remove dlf-leden.production --step=12 --force
```

## Related

- [`orbit app:setup`](../22_app-setup/app-setup.md)

## Technical Contract

See [`app-setup-step:remove` technical contract](technical/1_app-setup-step-remove.md).
