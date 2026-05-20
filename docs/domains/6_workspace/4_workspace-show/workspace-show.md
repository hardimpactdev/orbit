# `orbit workspace:show [name]`

[Back to Workspaces commands.](../README.md)

Show workspace registry configuration and runtime expectations.

`workspace:show` provides a detailed view of a workspace's gateway-tracked
configuration. It reports the parent app and owning node, branch, path, and
canonical URL.

It also returns runtime expectations (effective PHP version and inheritance
source, FPM pool, derived hostname), effective agent IDE resolution, inherited
process definitions, the workspace-owned proxy route, and a summary of the most
recent setup run.

This command is a registry-only read; it does not perform live probes on app
nodes.

## Usage

```bash
orbit workspace:show [name] [--app=<app>] [--json]
```

## Arguments and options

- `name`: The workspace name. Optional when the current working directory
  resolves to a known workspace path.
- `--app=<app>`: The parent app slug. Required only when `name` matches more
  than one workspace record. Workspace names are unique within an app but not
  globally unique.
- `--json`: Output structured JSON. Forces non-interactive input mode.

## Behavior Summary

Run `workspace:show` to inspect a workspace's gateway configuration without connecting to the node.

### Registry Read

Reads workspace configuration and related gateway history from the gateway database.

### Display

Shows the workspace identity (name, parent app, branch, path, URL), owning node, runtime expectations with PHP version inheritance source, effective agent IDE resolution, inherited processes, the workspace-owned proxy route, and the latest setup run summary.

### Ambiguous Name

Fails with `workspace.ambiguous_name` when `name` matches multiple workspaces and `--app` is missing in non-interactive mode.

### Registry-Only

Does not SSH to nodes, probe filesystems, check live process status, or verify live proxy routes.

## Examples

### Show a workspace by name

Use this form when the workspace name is unique across all apps.

```bash
orbit workspace:show feature-docs
```

### Show a workspace with app disambiguation

Pass `--app` when the workspace name exists under more than one app.

```bash
orbit workspace:show feature-docs --app=docs
```

### Show a workspace as JSON

Add `--json` to receive a machine-readable payload suitable for scripting.

```bash
orbit workspace:show feature-docs --app=docs --json
```

## Related

- [`orbit workspace:list`](../3_workspace-list/workspace-list.md): List all workspaces.
- [`orbit workspace:history [name]`](../6_workspace-history/workspace-history.md): View full setup/teardown history.
- [`orbit workspace:log [run]`](../7_workspace-log/workspace-log.md): View logs for a specific lifecycle run.
- [`doctor --family=workspace`](../workspace-doctor.md): Verify and repair live workspace reality.

***

**Technical Contract:** [technical/1_workspace-show.md](technical/1_workspace-show.md)
