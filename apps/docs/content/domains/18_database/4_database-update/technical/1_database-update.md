# Technical Contract: `orbit database:update`

[Back to public `database:update` documentation.](../database-update.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to update database connection state.

## Signature

```bash
orbit database:update [connection] [--node=<node>] [--node-transport=<transport>] [--slug=<slug>] [--driver=<mysql|pgsql|sqlite>] [--host=<host>] [--port=<port>] [--database=<name>] [--path=<path>] [--username=<username>] [--password=<password>] [--clear-password] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

At least one mutable field must be supplied. Driver-shape validation matches
[`database:add`](../../3_database-add/technical/1_database-add.md), including
the hard SQLite locality requirement: any update that changes the connection to
`sqlite`, or changes SQLite locality, must provide both `--node` and `--path`.

## Behavior Contract

### Update Rules

- Updates one existing database connection record stored by the gateway.
- May rename the slug, change driver shape, or replace secret material.
- `--clear-password` removes the stored password from encrypted credentials.
- Existing target mappings remain attached; target `.env` convergence stays with doctor restore.

## Renderer Contracts

- [Human renderer](6.1_database-update_output-render_human.md)
- [JSON renderer](6.2_database-update_output-render_json.md)

## Failure Semantics

- `database_connection.not_found` when the selected connection does not exist.
- `database_connection.slug_taken` when `--slug` collides.
- `validation_failed` for missing updates or invalid driver shape.

## Doctor Relationship

Updates change family intent only. A later
[`doctor --family=database_connection --restore`](../../database-doctor.md)
materializes changed mapped values into target `.env` files.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:PATCH /database-connections/{connection}` |
| Effect | `write` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | Changed non-secret fields and the connection slug. No raw password. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | PATCH payload forwarding, mutable-field validation before gateway contact, and password-clearing payload support. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway update persistence, credential clearing, and invalid node selectors. |

Slug-collision handling remains a coverage gap until a focused gateway test lands.
