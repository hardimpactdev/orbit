# Technical Contract: `orbit database:attach`

[Back to public `database:attach` documentation.](../database-attach.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the selected app,
  app instance, or workspace.

## Signature

```bash
orbit database:attach [connection] [--app=<app>] [--workspace=<workspace>] [--instance=<name>] [--env-prefix=DB] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `connection` | `argument` | Always. | n/a | Visible database connection slug. |
| `app` | `--app` | Required when `workspace` is absent. | None. | Visible active app the caller may manage. |
| `instance` | `--instance` | Optional with `app`. | None. | App instance belonging to the selected app. |
| `workspace` | `--workspace` | Required when `app` is absent. | None. | Visible workspace the caller may manage. |
| `env_prefix` | `--env-prefix` | Optional. | `DB`. | Stored on the target mapping, not on the connection record. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

Exactly one of `--app` or `--workspace` is required. `--instance` may be used
only with `--app` and maps the connection to that app instance instead of the
app target. `--env-prefix` defaults to `DB` and is stored on the target mapping,
not on the connection record.

## Behavior Contract

### Mapping Rules

- Creates or updates one target mapping from the selected connection to the
  selected app, app instance, or workspace.
- Enforces one mapping per target and env prefix.
- Does not rewrite the target `.env` file immediately. Materialization is owned
  by `doctor --family=database_connection --restore`.

## Renderer Contracts

- [Human renderer](6.1_database-attach_output-render_human.md)
- [JSON renderer](6.2_database-attach_output-render_json.md)

## Failure Semantics

- `database_connection.not_found` when the selected connection does not exist.
- `validation_failed` for missing or conflicting target scope or invalid prefix syntax.
- `database_connection.target_conflict` when the target already uses the prefix for a different connection.

## Doctor Relationship

Attach creates family intent only. Use
[`doctor --family=database_connection --restore`](../../database-doctor.md)
to write the mapped prefix into the target `.env` file.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database-connections/{connection}/targets` |
| Effect | `write` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `slug`, target type, target name, and `env_prefix`. No credentials. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | Target-scope validation, attach payload posting, and conflicting scope rejection. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway attach persistence, invalid scope envelope, and no immediate `.env` rewrite. |
