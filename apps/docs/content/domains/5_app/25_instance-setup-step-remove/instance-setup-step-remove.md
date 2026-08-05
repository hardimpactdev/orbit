# `orbit instance-setup-step:remove [instance]`

[Back to Project and instance commands.](../README.md)

Remove one setup step from an instance setup pipeline.

## Usage

```bash
orbit instance-setup-step:remove [app.instance] --step=<id> [--force] [--json]
```

Use this command to delete a bootstrap command from the app setup pipeline.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `instance` | Dotted instance selector, or a bare project shorthand when exactly one instance exists. |
| `--step` | Setup step id. |
| `--instance` | Instance selector for scripts where the positional argument is awkward. |
| `--force` | Skip destructive confirmation. |
| `--json` | Render JSON. |

## Behavior Summary

The command deletes the setup step record. Existing setup runs and captured
history remain available.

## Requirements

The caller needs `instance:write` on the selected instance's serving node.

## Output Summary

Human output names the removed step. JSON output returns the removed step and
metadata.

## Examples

```bash
orbit instance-setup-step:remove dlf-leden.production --step=12 --force
```

## Related

- [`orbit instance:setup`](../22_instance-setup/instance-setup.md)

## Technical Contract

See [`instance-setup-step:remove` technical contract](technical/1_instance-setup-step-remove.md).
