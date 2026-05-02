# `orbit php:use [version]`

Change the PHP version used by an app, workspace, or node CLI default.

## Usage

```bash
orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--inherit] [--cli] [--node=<node>] [--json]
```

## Examples

```bash
orbit php:use 8.5 --app=docs
orbit php:use 8.4 --app=docs --workspace=feature-docs
orbit php:use --app=docs --workspace=feature-docs --inherit
orbit php:use 8.5 --cli --node=app-1
orbit php:use 8.5 --app=docs --json
```

## Arguments And Options

- `version`: PHP version to select. Required unless `--inherit` is supplied.
- `--app=<app>`: Target app.
- `--workspace=<workspace>`: Target workspace override.
- `--inherit`: Clear a workspace override so the workspace inherits the parent
  app PHP version.
- `--cli`: Change the target node's default CLI PHP version.
- `--node=<node>`: Target node for `--cli`, or target-resolution fallback for
  app/workspace context.
- `--json`: Return the selected runtime result in the shared JSON command
  envelope.

## What Happens

`php:use` resolves exactly one target scope: app runtime, workspace runtime
override, workspace inheritance, or node CLI default. It validates that the
requested version is supported by Orbit and installed on the target node before
side effects begin.

For app and workspace targets, the command writes gateway intent and re-applies
the derived PHP-FPM and affected proxy backend artifacts through the gateway.
Proxy drift remains a `proxy` family concern. For node CLI targets, the command
writes node-level gateway intent and updates the default `php` binary on the
target node.

The command does not install PHP, edit project files, read `.php-version`, or
mutate Composer constraints.

## Output

Human output renders a progress tree and a short result summary. JSON output
returns `success.data.php` plus `success.data.result`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage the selected app,
  workspace, or node CLI default.
- The requested PHP version is supported by Orbit and installed on the target
  node.
- The gateway can reach the target node over SSH when node artifacts must be
  enacted.

## Related Commands

- [`orbit php:list`](../1_php-list/php-list.md)
- [`orbit tool:install php`](../../3_tool/3_tool-install/tool-install.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_php-use.md)
