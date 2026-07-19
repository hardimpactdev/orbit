# `orbit app:env list|set|render [app]`

[Back to App commands.](../README.md)

List, set, or render non-secret env values for one app instance.

## Usage

```bash
orbit app:env list [app] [--app=<app>] --instance=<name> [--json]
orbit app:env set [app] [--app=<app>] --instance=<name> --key=<KEY> --value=<value> [--apply] [--json]
orbit app:env render [app] [--app=<app>] --instance=<name> [--json]
```

## Examples

```bash
orbit app:env set billing --instance=development --key=APP_DEBUG --value=false
orbit app:env set billing --instance=development --key=MAIL_MAILER --value=smtp --apply
orbit database:attach billing-db --app=billing --instance=development --env-prefix=DB
orbit app:env render billing --instance=development --json
```

## Arguments and options

- `action`: one of `list`, `set`, `render`. Required.
- `app`: app name or hostname. May also be supplied as `--app`.
- `--instance`: instance name. Required.
- `--key`: env key. Required for `set`.
- `--value`: env value. Required for `set`.
- `--apply`: for `set` only. Persist the value in gateway state and apply it to
  the selected app instance's live `.env`, clear Laravel config/bootstrap
  cache at that instance path, and reapply that instance's runtime container.
- `--secret`: rejected in this slice; secret storage is not designed yet.
- `--json`: output JSON.

When both `[app]` and `--app` are supplied, they must match.

## What Happens

Use `app:env` to store app-instance env intent on the gateway. `render` merges
Orbit-derived app URL and Laravel Vite development-server fields, explicit
instance env values, and database connections attached to that same instance.
Secret database values are redacted in API and CLI responses.

`set` without `--apply` stores gateway intent only. `set --apply` also writes
the selected instance's live `.env` on its serving node, clears Laravel
config/bootstrap cache at that instance path, and reapplies that instance's
FrankenPHP runtime container for PHP apps. It never targets the logical app's
default path or a sibling instance. Running from inside a workspace does not
infer its parent app: `app:env` still requires explicit app and instance
selectors; use `workspace:env` for the active workspace.

## Output

Use `list` for explicit non-secret env variables. Use `render` for the effective
env map for the instance, including Orbit-derived `APP_URL`, `VITE_APP_URL`,
`VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, `VITE_DEV_SERVER_CERT`, and
database-derived keys.

Every response identifies the concrete target with `scope`, `app`, `instance`,
`workspace`, and `path`, plus `stored`, `applied`, and `runtime_restarted`
outcome booleans.

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
