# Technical Contract: `orbit database:tables`

[Back to public `database:tables` documentation.](../database-tables.md)

**Owner:** `database`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to inspect the selected target.

## Signature

```bash
orbit database:tables [target] [--connection=<slug>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `target` | `argument` | Always. | n/a | Dotted app-instance selector, workspace selector, or direct connection slug. Bare app selectors are invalid. |
| `connection` | `--connection` | Required when the target has more than one attached connection. | Unique attached mapping. | Visible attached connection slug. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Table Inventory Rules

- Resolves the target and connection using the same mapping rules as
  [`database:query`](../../8_database-query/technical/1_database-query.md).
- Returns visible table names and driver-reported table kinds.
- Follows SQLite locality for `sqlite`.

## Renderer Contracts

- [Human renderer](6.1_database-tables_output-render_human.md)
- [JSON renderer](6.2_database-tables_output-render_json.md)

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database/tables` |
| Effect | `read` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `connection`, `target`, `target_type`, `driver`, and `table_count`. No raw rows beyond the returned schema result. |
| Description | derived |

## Failure Semantics

- `database_connection.not_found` when the selected or inferred connection does not exist.
- `database_connection.target_not_found` when the target has no matching mapping.
- `database_query.connection_ambiguous` when `--connection` is required but omitted.
- `database_query.execution_failed` for metadata inspection failures.

## Doctor Relationship

The command reads stored connection intent only. Family drift and `.env`
convergence stay with [`database-doctor.md`](../../database-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | CLI GET forwarding for target and connection query parameters, required-target validation, and table-list human/JSON rendering. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway tables API execution and SQLite locality handoff. |

Ambiguity handling and metadata failure codes remain coverage gaps until focused gateway tests land.
