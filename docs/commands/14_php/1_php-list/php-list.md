# `orbit php:list`

List PHP versions Orbit supports, versions installed on a target node, and the
current app, workspace, or node CLI PHP selection.

## Usage

```bash
orbit php:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]
```

## Examples

```bash
orbit php:list
orbit php:list --app=docs
orbit php:list --app=docs --workspace=feature-docs
orbit php:list --node=app-1 --live
orbit php:list --node=app-1 --json
```

## Arguments and options

- `--node=<node>`: Target node for installed-version and CLI-default
  inspection.
- `--app=<app>`: App context for app PHP version reporting.
- `--workspace=<workspace>`: Workspace context for effective PHP version
  reporting. Requires `--app` unless the current directory resolves the parent
  app.
- `--live`: Inspect the target node for installed PHP versions during this
  command instead of using gateway-tracked tool facts.
- `--json`: Return the PHP runtime view in the JSON output.

## What Happens

Run this command to inspect PHP version support and selection for a node, app, or workspace.

`php:list` resolves a node, app, or workspace context from explicit options,
caller context, app ownership, workspace ownership, or local `node:default`.
It reads gateway configuration and the PHP runtime facts tracked by the gateway
for the resolved node. With `--live`, it also asks the gateway to inspect the target node for
installed PHP versions.

The command does not install PHP, remove PHP, change app configuration, change
workspace overrides, change node CLI defaults, or edit project files.

## Output

Your output shows the resolved PHP versions and selections for each available scope.

Human output renders supported versions, installed versions, node CLI default,
app selection, and workspace effective selection when those contexts are
available. Use `--json` for machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect the resolved app,
  workspace, or node.
- `--live` installed-version inspection requires the gateway to reach the
  target node over SSH.

## Related Commands

Use these commands to change PHP versions or inspect tool and runtime health.

- [`orbit php:use`](../2_php-use/php-use.md)
- [`orbit tool:show php`](../../3_tool/2_tool-show/tool-show.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)
- [Technical contract](technical/1_php-list.md)
