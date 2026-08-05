# `orbit instance-setup-step:list [instance]`

[Back to Project and instance commands.](../README.md)

List the recorded setup steps for one instance.

## Usage

```bash
orbit instance-setup-step:list [app.instance] [--json]
```

Use this command before changing or running setup when you need the current
ordered step set.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `instance` | Dotted instance selector, or a bare project shorthand when exactly one instance exists. |
| `--instance` | Instance selector for scripts where the positional argument is awkward. |
| `--json` | Render JSON. |

## Behavior Summary

The command reads gateway state only. It does not inspect the app host or run
setup commands.

## Requirements

The caller needs `instance:read` on the selected instance's serving node.

## Output Summary

Human output lists setup steps in run order. JSON output returns the
machine-readable step list.

## Examples

```bash
orbit instance-setup-step:list dlf-leden.production
```

## Related

- [`orbit instance:setup`](../22_instance-setup/instance-setup.md)

## Technical Contract

See [`instance-setup-step:list` technical contract](technical/1_instance-setup-step-list.md).
