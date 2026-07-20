# `orbit instance:env list|set|render [instance]`

[Back to Project and instance commands.](../README.md)

List, set, or render non-secret env values for one instance.

## Usage

```bash
orbit instance:env list [project.instance] [--json]
orbit instance:env set [project.instance] --key=<KEY> --value=<value> [--apply] [--json]
orbit instance:env render [project.instance] [--json]
```

## Examples

```bash
orbit instance:env set billing --instance=development --key=APP_DEBUG --value=false
orbit instance:env set billing --instance=development --key=MAIL_MAILER --value=smtp --apply
orbit database:attach billing-db --instance=billing.development --env-prefix=DB
orbit instance:env render billing --instance=development --json
```

## Arguments and options

- `action`: one of `list`, `set`, `render`. Required.
- `app`: project name or hostname. May also be supplied as `--instance`.
- `--instance`: instance name. Required.
- `--key`: env key. Required for `set`.
- `--value`: env value. Required for `set`.
- `--apply`: for `set` only. Persist the value in gateway state and apply it to
  the selected instance's live `.env`, clear Laravel config/bootstrap
  cache at that instance path, and reapply that instance's runtime container.
- `--secret`: rejected in this slice; secret storage is not designed yet.
- `--json`: output JSON.

When both `[instance]` and `--instance` are supplied, they must match.

## What Happens

Use `instance:env` to store instance env intent on the gateway. `render` merges
Orbit-derived app URL and Laravel Vite development-server fields, explicit
instance env values, and database connections attached to that same instance.
Secret database values are redacted in API and CLI responses.

`set` without `--apply` stores gateway intent only. `set --apply` also writes
the selected instance's live `.env` on its serving node, clears Laravel
config/bootstrap cache at that instance path, and reapplies that instance's
FrankenPHP runtime container for PHP apps. It never targets the project's
default path or a sibling instance. Running from inside a workspace does not
infer its parent project: `instance:env` still requires explicit project and instance
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
- The caller has `instance:read` for `list` and `render`.
- The caller has `instance:write` for `set`.

## Related Commands

Use these commands when you need to manage the instance or attach generated env
values.

- [`instance:show`](../26_instance-show/instance-show.md) - inspect the concrete instance
  instances.
- [`database:attach`](../../18_database/6_database-attach/database-attach.md) -
  attach database connections to an instance.

## Technical Contract

See [`instance:env` technical contract](technical/1_instance-env.md).
