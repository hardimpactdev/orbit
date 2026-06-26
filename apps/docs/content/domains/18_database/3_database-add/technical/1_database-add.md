# Technical Contract: `orbit database:add`

[Back to public `database:add` documentation.](../database-add.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to create database connection state.

## Signature

```bash
orbit database:add [slug] --driver=<mysql|pgsql|sqlite> [--node=<node>] [--host=<host>] [--port=<port>] [--database=<name>] [--path=<path>] [--username=<username>] [--password=<password>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Validation |
| --- | --- | --- | --- | --- |
| `slug` | `argument` | Always. | Never. | Globally unique connection slug. |
| `driver` | `--driver` | Always. | Never. | `mysql`, `pgsql`, or `sqlite`. |
| `host` | `--host` | `driver` is `mysql` or `pgsql`. | `driver=sqlite`. | Non-empty host. |
| `port` | `--port` | `driver` is `mysql` or `pgsql`. | `driver=sqlite`. | Positive integer. |
| `database` | `--database` | `driver` is `mysql` or `pgsql`. | Optional for `sqlite`. | Non-empty database name. |
| `path` | `--path` | `driver=sqlite`. | `driver` is `mysql` or `pgsql`. | Absolute SQLite path. |
| `username` | `--username` | `driver` is `mysql` or `pgsql`. | Never. | Non-secret username. |
| `password` | `--password` | Optional. | Never. | Secret; stored only in encrypted credentials. |
| `node` | `--node` | Required when `driver=sqlite`; optional otherwise. | Never. | Visible node slug. SQLite always requires an owning node plus file path. |
| `json` | `--json` | Optional. | Never. | Selects the JSON renderer. |

## Behavior Contract

### Connection Creation Rules

- Creates one database connection record stored by the gateway.
- Stores secret material such as passwords only in encrypted credentials.
- Uses slug suggestions as operator convenience only; no family doctor rule enforces slug naming.
- Does not create app or workspace target mappings. Attachment is a separate command.

### Driver Shape Rules

- `mysql` and `pgsql` require network fields and may store a nullable owning node.
- `sqlite` requires both `node` and `path` and follows SQLite locality: later
  query execution runs on the node that owns that file.

## Renderer Contracts

- [Human renderer](6.1_database-add_output-render_human.md)
- [JSON renderer](6.2_database-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Duplicate slug | The slug already exists. | `error.code=database_connection.slug_taken` |
| Invalid driver shape | Required or forbidden fields for the driver are wrong. | `error.code=validation_failed` |

## Doctor Relationship

New connections become family intent. Attachment and
[`doctor --family=database_connection --restore`](../../database-doctor.md)
materialize that intent into target `.env` files later.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database-connections` |
| Effect | `write` |
| Subject | `DatabaseConnection` on success; `none` on validation or authorization failure. |
| Properties | `slug`, `driver`, `node`, and non-secret shape fields only. No raw password. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | CLI payload posting, pre-gateway validation, secret omission, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway create persistence, credential encryption, validation envelopes, and invalid node selectors. |

Slug-collision and exhaustive documented create error codes remain coverage gaps until focused gateway tests land.
