# `orbit instance:show`

[Back to project and instance commands.](../README.md)

Show one concrete instance selected by `project.instance`.

## Usage

```bash
orbit instance:show [instance] [--json]
```

## Examples

```bash
orbit instance:show billing.development
orbit instance:show billing.production --json
```

## Arguments and options

- `instance`: Required dotted `project.instance` selector.
- `--json`: Emit the shared machine-readable envelope.

## What Happens

Orbit resolves exactly one project and instance, authorizes `instance:read`,
and returns its placement, runtime metadata, and driver compatibility details.

## Output

Human output prints the selected instance summary. The
[JSON renderer contract](technical/6.2_instance-show_output-render_json.md)
defines placement and Cloud compatibility fields.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `instance:read` on the selected instance.

## Related Commands

- [`instance:list`](../19_instance-list/instance-list.md)
- [`instance:add`](../27_instance-add/instance-add.md)
- [`doctor --family=instance`](../instance-doctor.md)

## Technical Contract

See [`instance:show` technical contract](technical/1_instance-show.md).
