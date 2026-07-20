# `orbit instance-setup-step:add [instance]`

[Back to Project and instance commands.](../README.md)

Add one command to an instance setup pipeline.

## Usage

```bash
orbit instance-setup-step:add [project.instance] --command=<command> [--before=<id>|--after=<id>] [--timeout=600] [--json]
```

Use this command to record finite bootstrap work for one concrete instance.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `instance` | Dotted instance selector, or a bare project shorthand when exactly one instance exists. |
| `--command` | Shell command to run from the app path during `instance:setup`. |
| `--instance` | Instance selector for scripts where the positional argument is awkward. |
| `--before` | Insert before this setup step id. |
| `--after` | Insert after this setup step id. |
| `--timeout` | Per-step timeout in seconds. |
| `--json` | Render JSON. |

## Behavior Summary

The gateway stores the step without running it. Run `instance:setup` to execute the
pipeline.

## Requirements

The caller needs `instance:write` on the selected instance's serving node.

## Output Summary

Human output names the created step. JSON output returns the setup step entity.

## Examples

```bash
orbit instance-setup-step:add dlf-leden.production --command="composer install --no-interaction"
```

## Related

- [`orbit instance:setup`](../22_instance-setup/instance-setup.md)

## Technical Contract

See [`instance-setup-step:add` technical contract](technical/1_instance-setup-step-add.md).
