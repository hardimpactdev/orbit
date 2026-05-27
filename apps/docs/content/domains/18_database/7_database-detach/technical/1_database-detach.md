# Technical Contract: `orbit database:detach`

[Back to public `database:detach` documentation.](../database-detach.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the selected app or workspace.

## Signature

```bash
orbit database:detach {connection} (--app=<app>|--workspace=<workspace>) [--env-prefix=DB] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

Exactly one of `--app` or `--workspace` is required. `--env-prefix` defaults to
`DB` and selects the mapping row to remove.

## Behavior Contract

### Detach Rules

- Removes one target mapping row for the selected connection, target, and env prefix.
- Does not edit the target `.env` file during detach.
- A later doctor restore may rewrite mapped prefixes that still exist for other connections, but detached prefixes are no longer expected family state.

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
| `apps/gateway/tests/Feature/Commands/Database/DatabaseDetachCommandTest.php` | Target-scope validation, mapping-not-found behavior, and no immediate `.env` rewrite. |
