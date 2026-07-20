# Technical Contract: `orbit database:detach`

[Back to public `database:detach` documentation.](../database-detach.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the selected instance
  or workspace.

## Signature

```bash
orbit database:detach [connection] [--instance=<project.instance>] [--workspace=<workspace>] [--env-prefix=DB] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `connection` | `argument` | Always. | n/a | Visible database connection slug. |
| `app` | `--instance` | Required when `workspace` is absent. | None. | Visible active app the caller may manage. |
| `instance` | `--instance` | Required with `app`. | None. | Instance belonging to the selected app. |
| `workspace` | `--workspace` | Required when `app` is absent. | None. | Visible workspace the caller may manage. |
| `env_prefix` | `--env-prefix` | Optional. | `DB`. | Selects the mapping row to remove. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

Exactly one of `--instance` or `--workspace` is required. `--instance` is required
with `--instance`; a bare app is never a database target.
`--env-prefix` defaults to `DB` and selects the mapping row to remove.

## Behavior Contract

### Detach Rules

- Removes one target mapping row for the selected connection, target, and env prefix.
- Does not edit the target `.env` file during detach.
- A later doctor restore may rewrite mapped prefixes that still exist for other connections, but detached prefixes are outside expected family state.

## Renderer Contracts

- [Human renderer](6.1_database-detach_output-render_human.md)
- [JSON renderer](6.2_database-detach_output-render_json.md)

## Failure Semantics

- `database_connection.not_found` when the selected connection does not exist.
- `database_connection.target_not_found` when the specified mapping does not exist.
- `validation_failed` for invalid target scope or prefix syntax.

## Doctor Relationship

Detach removes family intent only. Existing `.env` keys remain drift or manual
state until reviewed through [`database-doctor.md`](../../database-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /database-connections/{connection}/targets` |
| Effect | `write` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `slug`, target type, target name, and `env_prefix`. No credentials. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | Workspace detach payload posting and target-scope validation. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway detach result shape and no immediate `.env` rewrite. |

Mapping-not-found handling remains a coverage gap until a focused gateway test lands.
