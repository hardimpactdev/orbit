# `orbit workspace-teardown-step:add`

Add a workspace teardown step for an app.

## Usage

```bash
orbit workspace-teardown-step:add --command="dropdb my_app_feature" [--app=<app>] [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

## Description

The `workspace-teardown-step:add` command registers a shell command that
runs whenever Orbit removes a workspace for the app
([`workspace:remove`](../5_workspace-remove/workspace-remove.md)) or prunes
stale workspaces for the app
([`app:prune`](../../5_app/7_app-prune/app-prune.md)). These steps are used
for app-specific cleanup that needs the workspace path intact, such as
dropping feature-specific databases or notifying external services.

Steps are stored as gateway-owned workspace policy and are executed in the
workspace path on the owning node, before destructive workspace
artifact removal.

## Arguments

- `--command=<command>`: The shell command to execute. Required.
- `--app=<app>`: The parent app slug. When omitted, Orbit infers the app
  using the same precedence chain as
  [`workspace:new`](../1_workspace-new/workspace-new.md): explicit flag →
  `.orbit/config` marker on the caller filesystem → gateway path-ownership
  lookup keyed on `(caller node identity, absolute cwd)` → interactive
  prompt or non-interactive failure. Project files such as
  `composer.json`, `package.json`, and `.php-version` are never inspected.
- `--before=<id>`: Insert the new step before the teardown step with the
  given ID.
- `--after=<id>`: Insert the new step after the teardown step with the
  given ID.
- `--timeout=<seconds>`: Step timeout in seconds. Strict positive integer
  (`>= 1`). Default: `600`. `0` is rejected.
- `--json`: Output structured JSON payload.

## Behavior

The following rules govern how a step is added and when it runs.

### Deferred Execution

Adding a step does not execute it immediately. Steps run during
[`workspace:remove`](../5_workspace-remove/workspace-remove.md) and
[`app:prune`](../../5_app/7_app-prune/app-prune.md).

### Execution Timing

Teardown steps run **before** destructive workspace cleanup (runtime container removal and
worktree removal). Public traffic to the workspace URL has already been cut
earlier in the removal flow, so teardown scripts that need the workspace's
HTTP surface must target `127.0.0.1` or runtime container directly.

### Positional Insertion

Use `--before` or `--after` to place the step at a specific position in the
execution order. If both are omitted, the step is appended at the end of the
list with `order = max(order) + 1` for `(app_instance, phase=teardown)`. Providing
both `--before` and `--after` is a validation error.

### Lifecycle Environment

Teardown steps receive a standard lifecycle environment (e.g.,
`ORBIT_APP_PATH`, `ORBIT_WORKSPACE_PATH`). See the
[Workspace family README](../README.md#lifecycle-step-environment) for the
full list. Per-step working-directory and environment overrides are not
supported.

### Gateway Owned

Step definitions are managed by the gateway.
[Workspace Doctor](../workspace-doctor.md) verifies runtime reality but does
not edit these definitions.

### Additive (No Convergence by Command Text)

Running the same `add` twice creates two separate step records. Steps are
identified by the numeric `id` used by
[`workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md).

### Failure Handling at Execution

A teardown step that fails during `workspace:remove` or `app:prune` is
reported as a non-fatal structured warning on the consumer command.
Subsequent teardown steps still run, and the workspace removal continues
with runtime container removal and worktree removal.

### No Runtime Lock

The command never blocks on, or aborts because of, in-flight
`workspace:remove` / `app:prune` runs. The new step takes effect on the next
teardown pipeline run that begins after the gateway commit.

## Examples

### Add a basic teardown step

Run this to append a step to the end of the teardown list.

```bash
orbit workspace-teardown-step:add --command="dropdb docs_feature"
```

### Insert a step before an existing one

Use `--before` to position the step relative to an existing step ID.

```bash
orbit workspace-teardown-step:add --command="pkill -f 'my-app-worker'" --before=18
```

## Requirements

These conditions must hold before `workspace-teardown-step:add` will succeed.

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to manage workspace policy for the target app.
- The target app exists.

## Related

These commands work alongside `workspace-teardown-step:add` to manage and execute the teardown pipeline.

- [`orbit workspace-teardown-step:list`](../12_workspace-teardown-step-list/workspace-teardown-step-list.md)
- [`orbit workspace-teardown-step:remove`](../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md)
- [`orbit workspace-setup-step:add`](../8_workspace-setup-step-add/workspace-setup-step-add.md)
- [`orbit workspace:remove`](../5_workspace-remove/workspace-remove.md)
- [`orbit app:prune`](../../5_app/7_app-prune/app-prune.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-teardown-step-add.md)
