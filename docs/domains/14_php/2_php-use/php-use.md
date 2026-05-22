# `orbit php:use [version]`

Change the PHP image version used by an app or workspace runtime container.

## Usage

```bash
orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--json]
```

## Examples

```bash
orbit php:use 8.5 --app=docs
orbit php:use 8.4 --app=docs --workspace=feature-docs
orbit php:use --app=docs --workspace=feature-docs --inherit
orbit php:use 8.5 --app=docs --json
```

## Arguments and options

- `version`: PHP version to select. Required unless `--inherit` is supplied.
- `--app=<app>`: Target app.
- `--workspace=<workspace>`: Target workspace override.
- `--inherit`: Clear a workspace override so the workspace inherits the parent
  app PHP version.
- `--node=<node>`: For app and workspace targets, this may only confirm the
  owning node. A node that does not own the resolved app or workspace fails
  validation with the stable `target_mismatch` reason before any gateway
  configuration is written. It is not a fallback target source and does not
  override which node supplies image-availability facts. See the
  [JSON renderer contract](technical/6.2_php-use_output-render_json.md) for
  the exact failure shape.
- `--json`: Return the selected runtime result in the shared JSON command
  envelope.

## What Happens

Run this command to select the PHP image version for an app or workspace.

`php:use` resolves exactly one target scope: app runtime, workspace runtime
override, or workspace inheritance. It validates that the requested version is
supported by Orbit and available as an image on the target node before
side effects begin.

For app and workspace targets, the command writes gateway configuration and re-applies
the derived runtime container and affected proxy backend artifacts through the gateway.
Proxy drift remains a `proxy` family concern.

The command does not install PHP, edit project files, read `.php-version`, or
mutate Composer constraints.

## Output

Your output shows the resolved target, the selected version, and any drift warnings.

Human output renders progress and a short result summary. Use `--json` for
machine-readable output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage the selected app or
  workspace.
- The requested PHP version is supported by Orbit and available as an image on
  the target node.
- The gateway can reach the target node over SSH when node artifacts must be
  applied.

## Related Commands

Use these commands to list versions or verify runtime health across families.

- [`orbit php:list`](../1_php-list/php-list.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [Technical contract](technical/1_php-use.md)
