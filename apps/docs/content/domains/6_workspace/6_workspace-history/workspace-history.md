# `workspace:history`

Show workspace setup and lifecycle history.

## Usage

`orbit workspace:history [name] [--instance=<app.instance>] [--limit=<int>] [--since=<date>] [--until=<date>] [--json]`

## Arguments and options

- `name`: Workspace slug. Optional when the current working directory resolves
  to a known workspace path.
- `--instance=<app.instance>`: Parent app slug or instance selector. Use dot notation
  such as `happie.nmbp` to target one concrete instance. Required only
  when `name` matches multiple visible workspaces.
- `--json`: Output structured JSON.
- `--limit=<int>`: Maximum number of runs to return. Default `50`; hard cap
  `500`.
- `--since=<date>` / `--until=<date>`: ISO 8601 date filters for the run
  `started_at` timestamp.

## Examples

### View history for a specific workspace
`orbit workspace:history feature-docs`

### View history for a workspace in a specific app
`orbit workspace:history feature-docs --instance=ohdear`

### View history for an instance workspace
`orbit workspace:history recipes --instance=happie.nmbp`

### Get history as JSON
`orbit workspace:history feature-docs --json`

## Description

Retrieves a chronological audit log of lifecycle events and setup attempts for a workspace. This command reads durable history from the gateway; it does not perform live reality probes on nodes.

Developers use this to:
- track when a workspace was created or removed;
- diagnose previous setup failures by identifying which run failed;
- audit lifecycle changes such as PHP version overrides.

History is retained for the lifetime of the workspace row on the gateway and is
removed atomically when the workspace is removed via `workspace:remove`,
`app:remove`, or instance removal. The command returns at most 500 runs; the
default limit is 50, and walking further back through the timeline is done by
re-querying with `--until=<oldest started_at returned>`.

## Output Summary

### Human Output
A table of runs including run ID, lifecycle phase, status, and timestamps.

### JSON Output
A machine-readable result containing a `runs` array.

## Requirements

- Authorized access to the workspace registry.
- Network reachability to the Orbit gateway.

## Related

- [`orbit workspace:run:log [run]`](../7_workspace-run-log/workspace-run-log.md): Inspect logs for a specific run.
- [`orbit workspace:show [name]`](../4_workspace-show/workspace-show.md): View current workspace registry details.
- [`doctor --family=workspace`](../workspace-doctor.md): Verify current workspace reality.

---

[Technical Contract](technical/1_workspace-history.md)
