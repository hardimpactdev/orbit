# Technical Contract: `orbit instance:env list|set|render [instance]`

[Back to public `instance:env` documentation.](../instance-env.md)

**Owner:** `instance`.

**Effects:** `read` for `list` and `render`; `write` for `set`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target project and instance exist.
- The authenticated peer has `instance:read` or `instance:write` on the selected
  instance's serving node for the selected action.

## Signature

```bash
orbit instance:env [action] [instance] [--key=<KEY>] [--value=<value>] [--apply] [--secret] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | None. | Must be `list`, `set`, or `render`. |
| `instance` | `[instance]` | Always. | None. | Must be a dotted `project.instance` selector for an existing instance. |
| `key` | `--key` | `set`. | None. | Uppercase env key pattern. |
| `value` | `--value` | `set`. | None. | Stored as a non-secret string. |
| `apply` | `--apply` | `set` when applying to runtime. | `false`. | Fails with `validation_failed` outside `set`. |
| `secret` | `--secret` | Never in this slice. | `false`. | Fails with `validation_failed`. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## State Model

Gateway-owned `instance_env_variables` rows belong to one instance and
store non-secret key/value pairs. The unique key is `(instance_id, key)`.

Gateway-owned `database_connection_targets` rows connect one database
connection to either one instance or one workspace and one env prefix.
Instance rows are rendered by `instance:env render`.

Orbit-derived runtime defaults are rendered from the selected instance's project and
serving node. These defaults are not stored as explicit instance env rows.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/projects/{project}/instances/{instance}/env` | `instance:read` | List explicit env values. |
| `POST` | `/api/projects/{project}/instances/{instance}/env` | `instance:write` | Set a non-secret env value. Optional `apply=true` writes the selected instance's live `.env`, clears Laravel caches at that instance path, and reapplies that instance's runtime container. |
| `GET` | `/api/projects/{project}/instances/{instance}/env/render` | `instance:read` | Render effective env. |

## Behavior Contract

### Env Rules

1. **Instance ownership.** Env values belong to an instance, not the logical
   project.
2. **Non-secret only.** Explicit secret values are rejected until secret storage
   is designed.
3. **Orbit defaults.** Rendered env includes non-secret Orbit-derived
   `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and
   `VITE_DEV_SERVER_CERT` values for the selected instance.
4. **Database merge.** Rendered env includes supported database keys for database
   connections attached to the same instance.
5. **Secret redaction.** Rendered database password values are marked
   `secret=true` and redacted from responses.
6. **Gateway-only by default.** `set` persists gateway intent only. `set --apply`
   writes the selected instance's live `.env` on its serving node through
   authenticated Agent push over WireGuard. It cannot write the project
   default path or a sibling instance path. Workspace CWD never supplies an
   implicit instance target; project and instance selection remains explicit.
7. **Runtime apply.** When `apply` is requested for a PHP app, Orbit clears
   Laravel config/bootstrap cache at the selected instance path on the host PHP
   toolchain, writes a production `.env` as the instance's isolated runtime
   user, and reapplies the selected instance's FrankenPHP runtime container
   through `AppRuntimeContainerManager`.
8. **Explicit scope output.** Every success response carries
   `scope=instance`, `project`, `instance`, `workspace=null`, the concrete
   `.env` `path`, and `stored`, `applied`, and `runtime_restarted` booleans.

## Renderer Contracts

- [Human renderer](6.1_instance-env_output-render_human.md)
- [JSON renderer](6.2_instance-env_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No concrete instance matches `instance`. | `error.code=instance.not_found`. |
| Instance not found | No instance record matches `instance` for the project. | `error.code=instance.not_found`. |
| Instance driver cannot apply env | The selected instance does not expose an Orbit-managed node and path. | Gateway state remains saved; `error.code=instance.env_apply_failed`. |
| Runtime apply failed | Gateway state saved but the selected instance's remote `.env`, cache clear, or runtime reapply failed. | `error.code=instance.env_apply_failed`. |

## Doctor Relationship

[`instance-doctor.md`](../../instance-doctor.md) does not write instance env files in
this slice. Instance env rendering is gateway state. Database connection
drift and restore for instance/workspace `.env` files remain owned by
[`doctor --family=database_connection`](../../../18_database/database-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppEnvCommandTest.php` | CLI validation, instance selector behavior, gateway forwarding, render, and secret rejection. |
| `apps/gateway/tests/Feature/AppInstanceEnvControllerTest.php` | API env persistence, apply behavior, database attachment rendering, redaction, and secret rejection. |
| `apps/gateway/tests/Unit/Services/Apps/AppInstanceEnvApplierTest.php` | Remote `.env` writes, Laravel cache clearing, and runtime container reapply. |
