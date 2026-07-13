# Technical Contract: `orbit database:add-user`

[Back to public `database:add-user` documentation.](../database-add-user.md)

**Owner:** `database`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target managed MySQL process exists.
- The managed MySQL process uses Docker runtime.
- A non-gateway process-owning node is Agent-eligible and reachable over
  WireGuard.
- The authenticated peer has `database:write` on the MySQL process node.

## Signature

```bash
orbit database:add-user [connection] --service=<process> --database=<name> --username=<user> --password=<password> [--node=<node>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Validation |
| --- | --- | --- | --- | --- |
| `connection` | `[connection]` | Always. | Never. | Database connection slug to create or update. |
| `service` | `--service` | Always. | Never. | Existing managed MySQL process name. |
| `database` | `--database` | Always. | Never. | MySQL identifier: letters, digits, and underscores. |
| `username` | `--username` | Always. | Never. | MySQL identifier: letters, digits, and underscores. |
| `password` | `--password` | Always. | Never. | Secret; stored only in encrypted credentials. |
| `node` | `--node` | Optional. | Never. | Visible node slug used to disambiguate process lookup. |
| `json` | `--json` | Optional. | Never. | Selects the JSON renderer. |

## Behavior Contract

### Managed MySQL user rules

1. Resolves the managed MySQL process by name and optional node.
2. Fails before mutation when more than one matching process exists.
3. Fails before mutation unless the process is a Docker runtime MySQL service.
4. Runs the typed `internal:database-add-user` command gateway-locally when the
   gateway owns the process. For a non-gateway owner, send it through
   authenticated Agent push over WireGuard. Bind the SQL convergence payload as
   redacted input in either path.
5. Creates the database if missing.
6. Creates or updates the user password.
7. Grants that user access to the database.
8. Creates or updates the reusable database connection record.

## Renderer Contracts

- [Human renderer](6.1_database-add-user_output-render_human.md)
- [JSON renderer](6.2_database-add-user_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process lookup failed | The service is missing, unsupported, ambiguous, or not Docker-backed MySQL. | `error.code=validation_failed` |
| Convergence failed | The internal command is unsuccessful because the MySQL container is missing, not ready, or rejects the SQL. | `error.code=validation_failed` with service metadata. |

## Doctor Relationship

The resulting connection is database family intent. Attachment and
[`doctor --family=database_connection --restore`](../../database-doctor.md)
materialize that intent into target `.env` files. Doctor does not create or
rotate MySQL users.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /database-connections/{connection}/users` |
| Effect | `write` |
| Subject | `DatabaseConnection` on success; `none` on validation or authorization failure. |
| Properties | `slug`, `service`, `database`, `username`, and `node`. No raw password. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | CLI payload, validation, and secret-free human output. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseAddUserControllerTest.php` | Gateway-local or authenticated Agent-push convergence, connection persistence, Docker-runtime guard, and secret redaction. |
