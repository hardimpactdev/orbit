# `orbit app:instance list|show|add|remove [app]`

[Back to App commands.](../README.md)

List or change concrete runtime/deploy instances for a logical app.

## Usage

```bash
orbit app:instance list [app] [--app=<app>] [--json]
orbit app:instance show [app] [--app=<app>] --instance=<name> [--json]
orbit app:instance add [app] [--app=<app>] --instance=<name> [--driver=orbit|laravel-cloud] [--json]
orbit app:instance remove [app] [--app=<app>] --instance=<name> --force [--json]
```

## Examples

```bash
orbit app:instance list billing --json
orbit app:instance add billing --instance=development --driver=orbit --node=app-dev-1
orbit app:instance add billing --instance=production-cloud --driver=laravel-cloud --cloud-app=app_123 --cloud-environment=env_123 --php-extension=redis --php-extension=intl
orbit app:instance remove billing --instance=production-cloud --force
```

## Arguments and options

- `action`: one of `list`, `show`, `add`, `remove`. Required.
- `app`: app name or hostname. May also be supplied as `--app`.
- `--instance`: instance name. Required for `show`, `add`, and `remove`.
- `--driver`: `orbit` or `laravel-cloud`. Defaults to `orbit` for `add`.
- `--node`, `--path`, `--root`, `--domain`: Orbit driver placement fields.
- `--cloud-app`, `--cloud-environment`: Laravel Cloud application/environment
  id or name shortcuts.
- `--cloud-application-id`, `--cloud-application-name`,
  `--cloud-environment-id`, `--cloud-environment-name`,
  `--cloud-organization-id`, `--cloud-organization-name`: explicit Laravel
  Cloud driver fields.
- `--php-extension`: repeatable required PHP extension for this instance.
- `--force`: required for `remove` in non-interactive mode.
- `--json`: output JSON.

When both `[app]` and `--app` are supplied, they must match.

## What Happens

Use `app:instance` to manage the concrete targets that belong to a logical app.
Instance driver configuration is stored as a Spatie Laravel Data object under
`driver_config`.

`orbit` instances describe Orbit-managed app-role placement. `laravel-cloud`
instances describe the Laravel Cloud app/environment relationship. The current
slice records Laravel Cloud placement and compatibility data; it does not run
Laravel Cloud deployments.

## Output

Use `--json` when another tool needs the machine-readable envelope. Instance
payloads use the [App Instance JSON Entity](../README.md#app-instance-json-entity).

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `app:read` for `list` and `show`.
- The caller has `app:write` for `add` and `remove`.

## Related Commands

Use these commands when you need to manage values or services attached to an
instance.

- [`app:env`](../20_app-env/app-env.md) - manage and render instance env values.
- [`database:attach`](../../18_database/6_database-attach/database-attach.md) -
  attach database connections to an app instance.
- [`doctor --family=app`](../app-doctor.md) - verify app/runtime drift.

## Technical Contract

See [`app:instance` technical contract](technical/1_app-instance.md).
