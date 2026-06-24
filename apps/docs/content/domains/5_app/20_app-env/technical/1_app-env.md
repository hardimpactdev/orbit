# Technical Contract: `orbit app:env list|set|render [app]`

[Back to public `app:env` documentation.](../app-env.md)

**Owner:** `app`.

**Effects:** `read` for `list` and `render`; `write` for `set`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app and instance exist.
- The authenticated peer has `app:read` or `app:write` on the app's default
  owning node for the selected action.

## Signature

```bash
orbit app:env [action] [app] --instance=<name> [--app=<app>] [--key=<KEY>] [--value=<value>] [--apply] [--secret] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | None. | Must be `list`, `set`, or `render`. |
| `app` | `[app]` or `--app` | Always. | None. | Selects one existing app. If both are supplied they must match. |
| `instance` | `--instance` | Always. | None. | Must select an instance belonging to the app. |
| `key` | `--key` | `set`. | None. | Uppercase env key pattern. |
| `value` | `--value` | `set`. | None. | Stored as a non-secret string. |
| `apply` | `--apply` | `set` when applying to runtime. | `false`. | Fails with `validation_failed` outside `set`. |
| `secret` | `--secret` | Never in this slice. | `false`. | Fails with `validation_failed`. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## State Model

Gateway-owned `app_instance_env_variables` rows belong to one app instance and
store non-secret key/value pairs. The unique key is `(app_instance_id, key)`.

Gateway-owned `app_instance_database_connection_targets` rows connect one
database connection to one app instance and env prefix. They are rendered by
`app:env render`.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/instances/{instance}/env` | `app:read` | List explicit env values. |
| `POST` | `/api/apps/{app}/instances/{instance}/env` | `app:write` | Set a non-secret env value. Optional `apply=true` writes the live `.env`, clears Laravel caches, and reapplies the runtime container. |
| `GET` | `/api/apps/{app}/instances/{instance}/env/render` | `app:read` | Render effective env. |

## Behavior Contract

### Env Rules

1. **Instance ownership.** Env values belong to an app instance, not the logical
   app.
2. **Non-secret only.** Explicit secret values are rejected until secret storage
   is designed.
3. **Database merge.** Rendered env includes supported database keys for database
   connections attached to the same instance.
4. **Secret redaction.** Rendered database password values are marked
   `secret=true` and redacted from responses.
5. **Gateway-only by default.** `set` persists gateway intent only. `set --apply`
   writes the app's live `.env` on the owning node through `RemoteShell`.
6. **Runtime apply.** When `apply` is requested for a PHP app, Orbit clears
   Laravel config/bootstrap cache on the host PHP toolchain and reapplies the
   FrankenPHP runtime container through `AppRuntimeContainerManager`.

## Renderer Contracts

- [Human renderer](6.1_app-env_output-render_human.md)
- [JSON renderer](6.2_app-env_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found`. |
| Instance not found | No instance record matches `instance` for the app. | `error.code=app_instance.not_found`. |
| Runtime apply failed | Gateway state saved but remote `.env`, cache clear, or runtime reapply failed. | `error.code=app_instance.env_apply_failed`. |

## Doctor Relationship

[`app-doctor.md`](../../app-doctor.md) does not write app-instance env files in
this slice. App-instance env rendering is gateway state. Database connection
drift and restore for app/workspace `.env` files remain owned by
[`doctor --family=database_connection`](../../../18_database/database-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppEnvCommandTest.php` | CLI validation, app selector behavior, gateway forwarding, render, and secret rejection. |
| `apps/gateway/tests/Feature/AppInstanceEnvControllerTest.php` | API env persistence, apply behavior, database attachment rendering, redaction, and secret rejection. |
| `apps/gateway/tests/Unit/Services/Apps/AppInstanceEnvApplierTest.php` | Remote `.env` writes, Laravel cache clearing, and runtime container reapply. |
