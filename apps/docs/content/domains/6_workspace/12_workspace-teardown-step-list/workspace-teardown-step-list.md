# `orbit workspace-teardown-step:list`

[Back to Workspace commands.](../README.md)

List the ordered teardown steps Orbit will execute during workspace removal
for a specific app.

## Usage

```bash
orbit workspace-teardown-step:list [--app=<app>] [--json]
```

## Examples

```bash
orbit workspace-teardown-step:list
orbit workspace-teardown-step:list --app=my-app
orbit workspace-teardown-step:list --json
```

## Arguments and options

- `--app=<app>`: The parent app slug. When omitted, Orbit infers the app
  using the same precedence chain as
  [`workspace:new`](../1_workspace-new/workspace-new.md): explicit flag →
  `.orbit/config` marker on the caller filesystem → gateway path-ownership
  lookup keyed on `(caller node identity, absolute cwd)`. Project files
  such as `composer.json`, `package.json`, and `.php-version` are never
  inspected. If the app still cannot be resolved, the command fails validation;
  it does not prompt for app selection.
- `--json`: Output JSON.

## What Happens

Run `workspace-teardown-step:list` to view the teardown steps that will run during workspace removal.

`workspace-teardown-step:list` reads the teardown-step policy owned by the gateway
for the resolved app:

1. Resolves the parent app from `--app` or the shared cwd-inference chain.
2. Validates that the resolved app exists in gateway configuration.
3. Reads the ordered teardown-step policy for `(app_instance, phase=teardown)` from
   the gateway database.
4. Returns the steps in execution order with their durable IDs, commands,
   and timeouts.

`workspace-teardown-step:list` does not:
- SSH into nodes.
- Probe step artifact health (use [`doctor --family=workspace`](../workspace-doctor.md)).
- Mutate gateway configuration or execute teardown steps.

## Output

Both renderers return the same ordered set of steps, sorted by `order`
ascending.

Human output presents the steps as a table with `ID`, `ORDER`, `COMMAND`,
and `TIMEOUT` columns. The `ID` column surfaces the durable identifier
required by
[`workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md),
matching the resolved list-exemplar precedent of `node:list`, `app:list`,
and `workspace:list`.

JSON output returns a flat machine-readable step list. Each step record uses the
shared step shape published by
[`workspace-teardown-step:add`](../11_workspace-teardown-step-add/workspace-teardown-step-add.md):
`{ id, app, app_instance, phase, order, command, timeout_seconds }`. See the
[JSON renderer contract](technical/6.2_workspace-teardown-step-list_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to read teardown-step policy for the resolved app.
- The resolved app exists in gateway configuration.

## Related Commands

These commands work alongside `workspace-teardown-step:list` when managing or running the teardown pipeline.

- [`workspace-teardown-step:add`](../11_workspace-teardown-step-add/workspace-teardown-step-add.md) — append or insert a step
- [`workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md) — remove a step by ID
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md) — execute the teardown pipeline before removing the workspace
- [`doctor --family=workspace`](../workspace-doctor.md) — verify workspace runtime reality

## Technical Contract

See [`workspace-teardown-step:list` technical contract](technical/1_workspace-teardown-step-list.md).
