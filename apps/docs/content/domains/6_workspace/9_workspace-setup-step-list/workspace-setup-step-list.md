# `orbit workspace-setup-step:list`

[Back to Workspace commands.](../README.md)

List the ordered setup steps Orbit will execute during workspace creation or
setup for one concrete app instance.

## Usage

```bash
orbit workspace-setup-step:list [--app=<app.instance>] [--json]
```

## Examples

```bash
orbit workspace-setup-step:list
orbit workspace-setup-step:list --app=my-app.production
orbit workspace-setup-step:list --json
```

## Arguments and options

- `--app=<app.instance>`: Concrete dotted app-instance selector, such as
  `my-app.production`. When omitted, Orbit may infer exactly one concrete
  instance
  using the same precedence chain as
  [`workspace:new`](../1_workspace-new/workspace-new.md): explicit flag →
  `.orbit/config` marker on the caller filesystem → gateway path-ownership
  lookup keyed on `(caller node identity, absolute cwd)`. Project files
  such as `composer.json`, `package.json`, and `.php-version` are never
  inspected. If one concrete instance still cannot be resolved, the command
  returns an app-instance-required validation error; it does not widen the read
  across every instance of a logical app. The exact error shape is defined by
  the [JSON renderer contract](technical/6.2_workspace-setup-step-list_output-render_json.md).
- `--json`: Output JSON.

## What Happens

Run `workspace-setup-step:list` to view the setup steps that will run during workspace creation.

`workspace-setup-step:list` reads the setup-step policy owned by the gateway for
the resolved app instance:

1. Resolves one concrete app instance from `--app` or the shared cwd-inference
   chain.
2. Validates that the resolved app instance exists in gateway configuration.
3. Reads the ordered setup-step policy for `(app_instance, phase=setup)` from the
   gateway database.
4. Returns the steps in execution order with their durable IDs, commands,
   and timeouts.

`workspace-setup-step:list` does not:
- SSH into nodes.
- Probe step artifact health (use [`doctor --family=workspace`](../workspace-doctor.md)).
- Mutate gateway configuration or execute setup steps.

## Output

Both renderers return the same ordered set of steps, sorted by `order`
ascending.

Human output presents the steps as a table with `ID`, `ORDER`, `COMMAND`,
and `TIMEOUT` columns. The `ID` column surfaces the durable identifier
required by
[`workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md),
matching the resolved list-exemplar precedent of `node:list`, `app:list`,
and `workspace:list`.

JSON output returns a flat machine-readable step list. Each step record uses the
shared step shape published by
[`workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md):
`{ id, app, app_instance, phase, order, command, timeout_seconds }`. See the
[JSON renderer contract](technical/6.2_workspace-setup-step-list_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to read setup-step policy on the resolved app
  instance's serving node.
- The resolved app instance exists in gateway configuration.

## Related Commands

These commands work alongside `workspace-setup-step:list` when managing or running the setup pipeline.

- [`workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md) — append or insert a step
- [`workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md) — remove a step by ID
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md) — execute the setup pipeline
- [`doctor --family=workspace`](../workspace-doctor.md) — verify workspace runtime reality

## Technical Contract

See [`workspace-setup-step:list` technical contract](technical/1_workspace-setup-step-list.md).
