# `orbit instance:remove`

[Back to project and instance commands.](../README.md)

Remove one concrete instance while retaining its project and sibling instances.

## Usage

```bash
orbit instance:remove [instance] [--force] [--json]
```

## Examples

```bash
orbit instance:remove billing.production-cloud --force
orbit instance:remove billing.development --force --json
```

## Arguments and options

- `instance`: Required dotted `project.instance` selector.
- `--force`: Required destructive consent.
- `--json`: Emit the shared machine-readable envelope; it never implies consent.

## What Happens

Orbit resolves and authorizes one concrete instance, requires explicit
`--force`, and deletes that instance with its instance-owned dependents. It
does not remove the project or sibling instances.

## Output

Human output names the removed instance. The
[JSON renderer contract](technical/6.2_instance-remove_output-render_json.md)
defines the removal result with project and instance identity.

## Requirements

- The selected instance exists.
- The caller has `instance:write` on an Orbit serving node, or gateway authority for an external driver.
- `--force` is present in every mode.

## Related Commands

- [`instance:list`](../19_instance-list/instance-list.md)
- [`instance:add`](../27_instance-add/instance-add.md)
- [`project:remove`](../6_project-remove/project-remove.md)

## Technical Contract

See [`instance:remove` technical contract](technical/1_instance-remove.md).
