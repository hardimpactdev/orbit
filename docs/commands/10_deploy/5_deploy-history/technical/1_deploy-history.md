# Technical Contract: `orbit deploy:history [app] [--limit=<count>] [--json]`

[Back to public `deploy-history` documentation.](../deploy-history.md)

**Owner:** `deploy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the WireGuard peer to inspect deployment history for the selected app.

## Signature

```bash
orbit deploy:history [app] [--limit=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `argument` | `Required.` | `Never.` | `None.` | Visible production app the caller may inspect. |
| `limit` | `--limit` | `Optional.` | `Never.` | `50`. | Positive integer. Values greater than `500` are clamped to `500` and reported via `success.meta.pagination.limit_capped`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Deployment History Visibility Rules

- Reads deployment run history for one production app from gateway app state.
- Sorts runs by `started_at` descending.
- Applies the effective `--limit` after clamping to the hard cap.
- Returns an empty list for production apps with no recorded deployment runs.

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
| App not found | No visible app matches the selector. | `error.code=app.not_found` |
| Production app required | The app exists but is not a production app. | `error.code=deploy.production_app_required` |

## Doctor Relationship

`deploy:history` reads app-owned deployment history only. It does not own a
doctor family. [`app-doctor.md`](../../../5_app/app-doctor.md) owns production
app health checks that may incorporate latest deployment status through
`app.latest_deployment_failed` and `app.deployment_run_stuck`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Deploy/DeployHistoryCommandTest.php` | Command contract for production app lookup, authorization, ordered run listing, empty history behavior, default limit and cap behavior, read-only side-effect boundary, failure codes, and app-doctor handoff behavior. |
| `tests/Unit/Services/Deploy/DeployCommandContractTest.php` | Deploy-run DTO shape, app selector resolution, newest-first ordering, limit validation, and run entity mapping. |
