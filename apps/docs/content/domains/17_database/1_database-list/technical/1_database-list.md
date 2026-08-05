# Technical Contract: `orbit database:list`

[Back to public `database:list` documentation.](../database-list.md)

**Owner:** `database`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the selected instance, workspace, or node scope.

## Signature

```bash
orbit database:list [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `instance` | `--instance` | Optional. | None. | Visible instance selector. |
| `workspace` | `--workspace` | Optional. | None. | Visible workspace selector. |
| `node` | `--node` | Optional. | None. | Visible node slug. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer. |

`--instance` and `--workspace` are mutually exclusive. `--node` is an additional
filter over connection ownership; it does not trigger live node inspection.

## Behavior Contract

### Visibility Rules

- Reads gateway-owned `database_connection` records and their target mappings.
- Returns every visible connection when no scope filter is supplied.
- `--instance` returns connections attached to any instance of the selected app.
- `--workspace` returns connections attached to the selected workspace.
- `--node` returns connections whose `node` matches the selected node and
  connections attached to targets owned by that node.

### Scope Boundaries

`database:list` does not read target `.env` files, probe live connectivity, run
SQL, decrypt passwords into the output payload, or mutate gateway state.

## Renderer Contracts

- [Human renderer](6.1_database-list_output-render_human.md)
- [JSON renderer](6.2_database-list_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid scope combination | `--instance` and `--workspace` are supplied together. | `error.code=validation_failed`, `error.meta.field=scope` |

## Doctor Relationship

`database:list` reads the same gateway state that
[`doctor --family=database_connection`](../../database-doctor.md) verifies and
repairs, but it does not probe drift itself.

## Activity Logging

The gateway API emits an activity entry for successful and failed list requests.

| Field | Value |
| --- | --- |
| Type | `api:GET /database-connections` |
| Effect | `read` |
| Subject | `DatabaseConnection` for scoped reads; `none` for broad list or validation failure. |
| Properties | `instance`, `workspace`, and `node` selectors only. No decrypted credentials. |
| Description | derived |

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | CLI GET forwarding with instance/workspace filters, scope validation before gateway contact, authorization pass-through, JSON sensitive-field omission, and human table/empty-state output. |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Gateway list authorization, entity shape, password omission, and inactive-caller rejection. |
