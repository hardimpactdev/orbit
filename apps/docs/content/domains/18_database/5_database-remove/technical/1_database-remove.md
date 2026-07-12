# Technical Contract: `orbit database:remove`

[Back to public `database:remove` documentation.](../database-remove.md)

**Owner:** `database`.

**Effects:** `write`, `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to remove database connection state.

## Signature

```bash
orbit database:remove [connection] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `connection` | `argument` | Always. | n/a | Visible database connection slug. |
| `force` | `--force` | Always in non-interactive mode. | `false`. | Explicit destructive consent. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Removal Rules

- Deletes the selected database connection record stored by the gateway.
- Deletes its target mappings in the same operation.
- Does not rewrite app-instance or workspace `.env` files as part of removal.

## Input Mode Contracts

- [Interactive input mode](5.1_database-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_database-remove_input-mode_non-interactive.md)

## Renderer Contracts

- [Human renderer](6.1_database-remove_output-render_human.md)
- [JSON renderer](6.2_database-remove_output-render_json.md)

## Failure Semantics

- `database_connection.not_found` when the selected slug does not exist.
- `validation_failed` when destructive consent is missing in non-interactive mode.

## Doctor Relationship

Removal deletes family intent. It does not clean target `.env` files, so later
drift review belongs with [`database-doctor.md`](../../database-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /database-connections/{connection}` |
| Effect | `write` |
| Subject | `DatabaseConnection` when resolved; `none` otherwise. |
| Properties | `slug` and attached target count only. No credentials. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | Destructive consent, force-required validation, delete payload, and removal success. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway remove with force, mapping cascade, missing-consent envelope, and activity logging without secrets. |
