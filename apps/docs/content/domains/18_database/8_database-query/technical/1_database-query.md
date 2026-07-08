# Technical Contract: `orbit database:query`

[Back to public `database:query` documentation.](../database-query.md)

**Owner:** `database`.

**Effects:** `read`, `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to inspect the selected target.
- SQLite queries require the gateway to reach the node that owns the SQLite file.

## Signature

```bash
orbit database:query [target] --sql=<sql> [--connection=<slug>] [--limit=50] [--full] [--write] [--timeout=<seconds>] [--max-json-bytes=<bytes>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `target` | `argument` | Always. | n/a | Visible app or workspace selector. |
| `sql` | `--sql` | Always. | n/a | Non-empty SQL string. |
| `connection` | `--connection` | Required when the target has more than one attached connection. | Unique attached mapping. | Visible connection slug attached to the target. |
| `limit` | `--limit` | Optional. | `50`. | Positive integer row cap for row-returning statements. |
| `full` | `--full` | Optional. | `false`. | Disables result truncation within the JSON response. |
| `write` | `--write` | Required for write-capable SQL. | `false`. | Explicit consent for non-read-only execution. |
| `timeout` | `--timeout` | Optional. | Gateway default. | Query timeout in seconds. Positive integer. |
| `max_json_bytes` | `--max-json-bytes` | Optional. | Gateway default. | Maximum JSON response size in bytes. Positive integer. |
| `json` | `--json` | Optional. | `false`. | Accepted for consistency; this command emits strict JSON in both modes. |

## Behavior Contract

### Execution Rules

- Resolves the connection from target mappings and optional `--connection`.
- Classifies SQL as read-only or write-capable before execution.
- Rejects write-capable SQL unless `--write` is supplied.
- Uses gateway execution for reachable `mysql` and `pgsql` connections.
- Uses SQLite locality for `sqlite`: the gateway invokes the hidden internal
  `internal:database-query-local` CLI command on the owning node through
  `RemoteLocalExecutor` with a strict JSON stdin payload.

### Audit Rules

- Successful and failed query attempts are activity logged.
- Activity properties may include connection slug, target, driver, statement
  class, row count, and elapsed time.
- Activity properties must not include raw SQL passwords, raw result rows, or
  full statement text. A normalized statement fingerprint or truncated preview
  is allowed.

## Renderer Contracts

- [Human renderer](6.1_database-query_output-render_human.md) — documents why no human renderer is available.
- [JSON renderer](6.2_database-query_output-render_json.md)

## Failure Semantics

- `database_connection.not_found` when the selected or inferred connection does not exist.
- `database_connection.target_not_found` when the target has no matching mapping.
- `database_query.connection_ambiguous` when the target has multiple mappings and `--connection` is omitted.
- `database_query.write_not_allowed` when write-capable SQL is attempted without `--write`.
- `database_query.execution_failed` when the driver rejects the statement or connectivity fails after resolution.

## Doctor Relationship

The command uses connection intent stored by the gateway only. It does not
parse `.env` files as a fallback source of truth. Family drift and repair stay
with [`database-doctor.md`](../../database-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database/query` |
| Effect | `read` for read-only SQL; `write` when `--write` is accepted. |
| Subject | `DatabaseConnection` when resolved; `none` for pre-resolution failure. |
| Properties | `connection`, `target`, `target_type`, `driver`, `statement_class`, `row_count`, `elapsed_ms`, and optional statement fingerprint. No raw password, no raw result rows. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | SQL and target validation, query payload posting, strict JSON stdout, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Target resolution, ambiguity errors, read/write permission separation, SQLite locality handoff, and unattached-connection failures. |
| `packages/core/tests/Database/DatabaseQueryClassifierTest.php` | Read/write SQL classification for documented statement classes. |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseConnectionExecutorTest.php` | SQLite dispatch through `internal:database-query-local` without credential leakage. |
