# Technical Contract: `orbit deploy:history [instance] [--limit=<count>] [--json]`

[Back to public `deploy-history` documentation.](../deploy-history.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:read` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:history [instance] [--limit=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `argument` | `Required.` | `Never.` | `None.` | Visible production instance selector. A bare project is valid only when it has exactly one instance. |
| `limit` | `--limit` | `Optional.` | `Never.` | `50`. | Positive integer. Values greater than `500` are clamped to `500` and reported via `success.meta.pagination.limit_capped`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment History Visibility Rules

- Reads deployment run history for one concrete production instance from gateway state.
- Sorts runs by `started_at` descending.
- Applies the effective `--limit` after clamping to the hard cap.
- Returns an empty list for production instances with no recorded deployment runs.

### History Boundary Rules

`deploy:history` must not SSH into nodes, inspect live node state, execute
deployment steps, repair failed deployments, mutate deployment policy, mutate
run history, or perform application health checks.

### Limits

- Default `--limit` is `50`.
- Hard cap is `500` runs per request.
- A caller-supplied `--limit > 500` is clamped to `500` and
  `success.meta.pagination.limit_capped` is set to `true`.
- A non-positive or non-integer `--limit` fails with
  `error.code=validation_failed` and `error.meta.field=limit`.

## Renderer Contracts

- [Human renderer](6.1_deploy-history_output-render_human.md)
- [JSON renderer](6.2_deploy-history_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No visible instance matches the selector. | `error.code=instance.not_found` |
| Production project required | The app exists but is not a production project. | `error.code=deploy.production_project_required` |
| Instance required | A bare project has more than one instance. | `error.code=validation_failed`, `error.meta.reason=instance_required` |

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /deploy/history` |
| Effect | `read` |
| Subject | `none` (list). |
| Properties | `project`, `instance`, `count`, `limit`. |
| Description | derived |

## Doctor Relationship

`deploy:history` reads deployment history that the instance owns. It does not own a
doctor family. [`instance-doctor.md`](../../../5_project/instance-doctor.md) owns production
app health checks that may incorporate latest deployment status through
`instance.latest_deployment_failed` and `instance.deployment_run_stuck`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | CLI `deploy:history` GET forwarding, `--limit` query forwarding, JSON run envelope and metadata, human newest-first table output, empty-history output, missing-instance validation before gateway contact, gateway limit validation passthrough, and `authorization_failed`, `deploy.production_project_required`, and WireGuard failure passthrough. |

There is no gateway-side coverage for this mapped surface; linked CLI tests cover only the mapped assertions above. Remaining behavior stays a coverage gap until focused tests land.

Coverage gaps until focused tests land: default limit and cap behavior, read-only side-effect boundary proof, exhaustive documented `error.code` values, and instance-doctor handoff behavior.
