# Technical Contract: `orbit database:show`

[Back to public `database:show` documentation.](../database-show.md)

**Owner:** `database`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the selected connection.

## Signature

```bash
orbit database:show {connection} [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `connection` | `argument` | Always. | n/a | Visible database connection slug. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Connection Detail Rules

- Resolves one gateway-owned database connection by slug.
- Returns the canonical database entity plus all attached app and workspace targets.
- Never returns decrypted password material.

### Scope Boundaries

`database:show` does not inspect live database connectivity, parse `.env` files,
or mutate target mappings.

## Renderer Contracts

- [Human renderer](6.1_database-show_output-render_human.md)
- [JSON renderer](6.2_database-show_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Connection not found | The slug does not resolve in visible gateway state. | `error.code=database_connection.not_found` |

## Doctor Relationship

The command reads family state only. Drift and repair stay with
[`database-doctor.md`](../../database-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /database-connections/{connection}` |
| Effect | `read` |
| Subject | `DatabaseConnection` |
| Properties | `slug` only. No credentials. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Database/DatabaseShowCommandTest.php` | Resolution, not-found behavior, authorization handoff, and side-effect boundaries. |
