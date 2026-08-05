# Technical Contract: `orbit instance:remove`

[Back to public `instance:remove` documentation.](../instance-remove.md)

**Owner:** `instance`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The selected instance exists and the caller can remove it.

## Signature

```bash
orbit instance:remove [instance] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted `app.instance` selector. |
| `force` | `--force` | In non-interactive input mode. | Never. | `false`. | Explicit consent for this instance only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects JSON; never implies consent. |

`--force` supplies destructive consent for the exact selected instance.
`--json` selects non-interactive input mode and never supplies destructive
consent.

## Input Mode Contracts

- [Interactive input](5.1_instance-remove_input-mode_interactive.md)
- [Non-interactive input](5.2_instance-remove_input-mode_non-interactive.md)

## State Model

Deletion removes one `Instance` and cascades only its instance-owned
relationships. The parent `Project` and sibling instances remain.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `DELETE` | `/api/apps/{app}/instances/{instance}` | `instance:write` | Remove one instance. |

## Behavior Contract

### Instance Removal Rules

1. Resolve and authorize the exact dotted instance before effects.
2. Require destructive consent: interactive confirmation via
   `instance_remove.confirm`, or `--force`; non-interactive mode without
   `--force` fails `validation_failed` with `meta.field=force` only.
3. Delete only the named instance and its instance-owned dependents.
4. Never delete the app or sibling instances.
5. Use gateway-only authority for external-driver instances.

## Renderer Contracts

- [Human renderer](6.1_instance-remove_output-render_human.md)
- [JSON renderer](6.2_instance-remove_output-render_json.md)

## Failure Semantics

Missing consent returns `validation_failed` with `error.meta.field=force`.
Unknown targets return `app.not_found` or `instance.not_found`.
Authorization failures occur before deletion.

## Doctor Relationship

The removal command owns deletion. [`instance-doctor.md`](../../instance-doctor.md)
does not infer deletion of a registered instance.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /apps/{app}/instances/{instance}` |
| Effect | `destructive` |
| Subject | Target `Project`. |
| Properties | The API path identifies the removed instance. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppInstanceCommandTest.php` | Required `--force`, forwarding, and human output. |
| `apps/gateway/tests/Feature/InstanceControllerTest.php` | Authorization, consent, deletion scope, and response shape. |
