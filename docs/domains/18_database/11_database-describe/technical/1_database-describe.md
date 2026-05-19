# Technical Contract: `orbit database:describe`

[Back to public `database:describe` documentation.](../database-describe.md)

**Owner:** `database`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to inspect the selected target.

## Signature

```bash
orbit database:describe {target} {table} [--connection=<slug>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `target` | `argument` | Always. | n/a | Visible app or workspace selector. |
| `table` | `argument` | Always. | n/a | Table name visible to the resolved connection. |
| `connection` | `--connection` | Required when the target has more than one attached connection. | Unique attached mapping. | Visible attached connection slug. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Table Description Rules

- Resolves the target and connection using the same mapping rules as
  [`database:query`](../../8_database-query/technical/1_database-query.md).
- Returns one table description with column, key, nullability, default, and
  type metadata.
- Follows SQLite locality for `sqlite`.

## Renderer Contracts

- [Human renderer](6.1_database-describe_output-render_human.md)
- [JSON renderer](6.2_database-describe_output-render_json.md)

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database/describe` |
| Effect | `read` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `connection`, `target`, `target_type`, and `table`. No raw passwords. |
| Description | derived |

## Failure Semantics

- `database_connection.not_found` when the selected or inferred connection does not exist.
- `database_connection.target_not_found` when the target has no matching mapping.
- `database_query.connection_ambiguous` when `--connection` is required but omitted.
- `database_query.execution_failed` for table inspection failures.

## Doctor Relationship

The command reads stored connection intent only. Family drift and `.env`
convergence stay with [`database-doctor.md`](../../database-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Database/DatabaseDescribeCommandTest.php` | Target resolution, ambiguity handling, table lookup, SQLite locality, and metadata failure codes. |
