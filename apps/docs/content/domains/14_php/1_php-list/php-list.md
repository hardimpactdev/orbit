# `orbit php:list`

List PHP image versions Orbit supports, images available on a target node, and
the current app or workspace PHP selection.

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

- `--node=<node>`: Target node for available-image inspection.
- `--app=<app>`: App context for app PHP version reporting.
- `--workspace=<workspace>`: Workspace context for effective PHP version
  reporting. Requires `--app` unless the current directory resolves the parent
  app.
- `--live`: Inspect the target node for available PHP images during this
  command instead of using gateway-tracked runtime facts.
- `--json`: Return the PHP runtime view in the JSON output.

## What Happens

Run this command to inspect PHP image support and selection for a node, app, or workspace.

`php:list` resolves a node, app, or workspace context from explicit options,
caller context, concrete app-instance placement, workspace-instance placement,
or local `node:default`. It reads gateway configuration and the PHP image facts
tracked by the gateway for the resolved node. With `--live`, it also asks the
gateway to inspect the target node through its Docker-compatible provider and
records the approved image inventory. A failed live inventory probe is reported
as unavailable. On an eligible node without a PHP inventory fact, Orbit
registers that fact before probing. The probe through the Docker-compatible
provider still has to succeed before the inventory is confirmed.

The command does not install PHP, remove PHP, change app configuration, change
workspace overrides, or edit project files.

## Output

Your output shows the resolved PHP versions and selections for each available scope.

Human output renders supported versions, available images, app selection, and
workspace effective selection when those contexts are
available. Use `--json` for machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity has `php:read` granted on the resolved serving
  node. Gateway identity remains implicit.
- `--live` image inspection requires an Agent-eligible, reachable target node.

## Related Commands

Use these commands to change PHP versions or inspect tool and runtime health.

- [`orbit php:use`](../2_php-use/php-use.md)
- [`orbit tool:show php`](../../3_tool/2_tool-show/tool-show.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)
- [Technical contract](technical/1_php-list.md)
