# `orbit workspace-setup-step:list`

[Back to Workspace commands.](../README.md)

List the ordered setup steps Orbit will execute during workspace creation or
setup for a specific app.

## Usage

```bash
orbit workspace-setup-step:list [--app=<app>] [--json]
```

## Examples

```bash
orbit workspace-setup-step:list
orbit workspace-setup-step:list --app=my-app
orbit workspace-setup-step:list --json
```

## Arguments and options

- `--app=<app>`: The parent app slug. When omitted, Orbit infers the app
  using the same precedence chain as
  [`workspace:new`](../1_workspace-new/workspace-new.md): explicit flag →
  `.orbit/config` marker on the caller filesystem → gateway path-ownership
  lookup keyed on `(caller node identity, absolute cwd)`. Project files
  such as `composer.json`, `package.json`, and `.php-version` are never
  inspected. If the app still cannot be resolved, the command fails with
  `error.code=validation_failed`; it does not prompt for app selection.
- `--json`: Output JSON.

## What Happens

Run `workspace-setup-step:list` to view the setup steps that will run during workspace creation.

`workspace-setup-step:list` reads the setup-step policy owned by the gateway for
the resolved app:

1. Resolves the parent app from `--app` or the shared cwd-inference chain.
2. Validates that the resolved app exists in gateway configuration.
3. Reads the ordered setup-step policy for `(app, phase=setup)` from the
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

JSON output returns a flat array under `success.data.steps`. Each step
record uses the shared step shape published by
[`workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md):
`{ id, app, phase, order, command, timeout_seconds }`. See the
[JSON renderer contract](technical/6.2_workspace-setup-step-list_output-render_json.md)
for the exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to read setup-step policy for the resolved app.
- The resolved app exists in gateway configuration.

## Related Commands

- [`workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md) — append or insert a step
- [`workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md) — remove a step by ID
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md) — execute the setup pipeline
- [`doctor --family=workspace`](../workspace-doctor.md) — verify workspace runtime reality

## Technical Contract

See [`workspace-setup-step:list` technical contract](technical/1_workspace-setup-step-list.md).
