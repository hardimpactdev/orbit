# Technical Contract: `orbit database:schema`

[Back to public `database:schema` documentation.](../database-schema.md)

**Owner:** `database`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to inspect the selected target.

## Signature

```bash
orbit database:schema [target] [--connection=<slug>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `target` | `argument` | Always. | n/a | Dotted instance selector, workspace selector, or direct connection slug. Bare project selectors are invalid. |
| `connection` | `--connection` | Required when the target has more than one attached connection. | Unique attached mapping. | Visible attached connection slug. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Schema Snapshot Rules

- Resolves the target and connection using the same mapping rules as
  [`database:query`](../../8_database-query/technical/1_database-query.md).
- Returns driver-reported schema metadata (table inventory including views);
  per-table columns and indexes come from `database:describe`.
- Follows SQLite locality for `sqlite`.

## Renderer Contracts

- [Human renderer](6.1_database-schema_output-render_human.md)
- [JSON renderer](6.2_database-schema_output-render_json.md)

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /database-connections/schema` |
| Effect | `read` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `connection`, `target`, `target_type`, `driver`, and schema object counts. No raw passwords. |
| Description | derived |

## Failure Semantics

- `database_connection.not_found` when the selected or inferred connection does not exist.
- `database_connection.target_not_found` when the target has no matching mapping.
- `database_query.connection_ambiguous` when `--connection` is required but omitted.
- `database_query.execution_failed` for schema inspection failures.

## Doctor Relationship

The command reads stored connection intent only. Family drift and `.env`
convergence stay with [`database-doctor.md`](../../database-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | CLI GET forwarding for target and connection query parameters, required-target validation, and schema human/JSON rendering. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway metadata API execution for table listing and SQLite locality handoff. |

Ambiguity handling, schema-endpoint-specific behavior, and metadata failure codes remain coverage gaps until focused gateway tests land.
