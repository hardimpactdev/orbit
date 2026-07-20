# Technical Contract: `orbit instance:list`

[Back to public `instance:list` documentation.](../instance-list.md)

**Owner:** `instance`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller is authorized for `instance:read` on at least one active app-role
  serving node, or is the gateway.

## Signature

```bash
orbit instance:list [--project=<project>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | `--project` | Optional. | Never. | All visible projects. | Existing project slug. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## State Model

Each returned `AppInstance` belongs to one `Project`. Private storage retains
its rollback-safe legacy foreign key; the public entity fields are `project`
and `name`.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/instances` | `instance:read` | List visible instances, optionally filtered by `project`. |

## Behavior Contract

### Instance Listing Rules

1. Return every visible concrete instance at most once.
2. Apply `--project` before rendering.
3. Authorize Orbit instances against their serving nodes.
4. Expose external-driver instances only to gateway callers.
5. Perform no runtime probe or mutation.

## Renderer Contracts

- [Human renderer](6.1_instance-list_output-render_human.md)
- [JSON renderer](6.2_instance-list_output-render_json.md)

## Failure Semantics

Unknown project filters return `project.not_found`. An authorized caller
receives an empty successful list when no instance rows are visible. A
non-gateway caller with no `instance:read` grant on any active app-role serving
node receives `authorization_failed` with
`error.meta.missing_permission=instance:read`.

## Doctor Relationship

`instance:list` reads registry state. [`instance-doctor.md`](../../instance-doctor.md)
owns live runtime verification and drift convergence.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /instances` |
| Effect | `read` |
| Subject | Selected `Project`, or none without a project filter. |
| Properties | No command-specific properties beyond transport context. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppInstanceCommandTest.php` | CLI list filtering, human rows, and JSON forwarding. |
| `apps/gateway/tests/Feature/Http/Api/AppInstanceControllerTest.php` | Global instance visibility, authorized empty lists, project filtering, and payload shape. |
