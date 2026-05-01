# `orbit workspace-setup-step:add`

Add a workspace setup step for an app.

## Usage

```bash
orbit workspace-setup-step:add --command="composer install" [--app=<app>] [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

## Description

The `workspace-setup-step:add` command registers a shell command that runs
whenever Orbit creates or sets up a workspace for the app. These steps are
used for app-specific preparation such as installing dependencies, copying
environment files, or running project setup commands.

Steps are stored as gateway-owned workspace policy and are executed in the
workspace path on the owning app node.

## Arguments

- `--command=<command>`: The shell command to execute. Required.
- `--app=<app>`: The parent app slug. When omitted, Orbit infers the app
  using the same precedence chain as
  [`workspace:new`](../1_workspace-new/workspace-new.md): explicit flag →
  `.orbit/config` marker on the caller filesystem → gateway path-ownership
  lookup keyed on `(caller node identity, absolute cwd)` → interactive
  prompt or non-interactive failure. Project files such as `composer.json`,
  `package.json`, and `.php-version` are never inspected.
- `--before=<id>`: Insert the new step before the step with the given ID.
- `--after=<id>`: Insert the new step after the step with the given ID.
- `--timeout=<seconds>`: Step timeout in seconds. Strict positive integer
  (`>= 1`). Default: `600`. `0` is rejected.
- `--json`: Output structured JSON payload.

## Behavior

- **Deferred Execution**: Adding a step does not execute it immediately.
  Steps run during [`workspace:new`](../1_workspace-new/workspace-new.md) and
  [`workspace:setup`](../2_workspace-setup/workspace-setup.md).
- **Positional Insertion**: Use `--before` or `--after` to place the step at
  a specific position in the execution order. If both are omitted, the step
  is appended at the end of the list with `order = max(order) + 1` for
  `(app, phase=setup)`. Providing both `--before` and `--after` is a
  validation error.
- **Lifecycle Environment**: Setup steps receive a standard lifecycle
  environment (e.g., `ORBIT_APP_PATH`, `ORBIT_WORKSPACE_PATH`). See the
  [Workspace family README](../README.md#lifecycle-step-environment) for
  the full list. Per-step working-directory and environment overrides are
  not supported.
- **Gateway Owned**: Step definitions are managed by the gateway.
  [Workspace Doctor](../workspace-doctor.md) verifies runtime reality but
  does not edit these definitions.
- **Additive (No Convergence by Command Text)**: Running the same `add`
  twice creates two separate step records. Steps are identified by the
  numeric `id` used by
  [`workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md).
- **No Runtime Lock**: The command never blocks on, or aborts because of,
  in-flight `workspace:new` / `workspace:setup` runs.

## Examples

### Add a basic setup step
```bash
orbit workspace-setup-step:add --command="php artisan migrate"
```

### Insert a step before an existing one
```bash
orbit workspace-setup-step:add --command="npm install" --before=12
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy for the target app.
- The target app exists.

## Related

- [`orbit workspace-setup-step:list`](../9_workspace-setup-step-list/workspace-setup-step-list.md)
- [`orbit workspace-setup-step:remove`](../10_workspace-setup-step-remove/workspace-setup-step-remove.md)
- [`orbit workspace-teardown-step:add`](../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-setup-step-add.md)
