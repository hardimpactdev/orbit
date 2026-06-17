# `orbit app:env list|set|render [app]`

[Back to App commands.](../README.md)

List, set, or render non-secret env values for one app instance.

## Usage

```bash
orbit app:env list [app] [--app=<app>] --instance=<name> [--json]
orbit app:env set [app] [--app=<app>] --instance=<name> --key=<KEY> --value=<value> [--json]
orbit app:env render [app] [--app=<app>] --instance=<name> [--json]
```

## Examples

```bash
orbit app:env set billing --instance=development --key=APP_DEBUG --value=false
orbit database:attach billing-db --app=billing --instance=development --env-prefix=DB
orbit app:env render billing --instance=development --json
```

## Arguments and options

- `action`: one of `list`, `set`, `render`. Required.
- `app`: app name or hostname. May also be supplied as `--app`.
- `--instance`: instance name. Required.
- `--key`: env key. Required for `set`.
- `--value`: env value. Required for `set`.
- `--secret`: rejected in this slice; secret storage is not designed yet.
- `--json`: output JSON.

When both `[app]` and `--app` are supplied, they must match.

## What Happens

Use `app:env` to store app-instance env intent on the gateway. `render` merges explicit
instance env values with database connections attached to that same instance.
Secret database values are redacted in API and CLI responses.

The command does not write a remote `.env` file yet. It provides the stable
rendered payload that convergence and future deploy drivers can consume.

## Output

Use `list` for explicit non-secret env variables. Use `render` for the effective
env map for the instance, including database-derived keys.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `app:read` for `list` and `render`.
- The caller has `app:write` for `set`.

## Related Commands

Use these commands when you need to manage the instance or attach generated env
values.

- [`app:instance`](../19_app-instance/app-instance.md) - manage concrete app
  instances.
- [`database:attach`](../../18_database/6_database-attach/database-attach.md) -
  attach database connections to an app instance.

## Technical Contract

See [`app:env` technical contract](technical/1_app-env.md).
