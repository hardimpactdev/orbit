# Technical Contract: `orbit workspace:log <run>`

[Back to public `workspace:log` documentation.](../workspace-log.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read workspace history for the
  workspace that owns `<run>` through gateway-owned access policy.
- The `<run>` ID resolves to an existing workspace run record visible to the
  caller and the durable per-step output for that run has not been pruned.

## Signature

```bash
orbit workspace:log <run> [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

`<run>` is a required positional integer. This command does not prompt for
missing input and does not resolve `<run>` from the current working directory.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `run` | `<run>` | Always. | Never. | None. | Must be a positive integer matching an existing workspace run record visible to the caller. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`<run>` is a gateway-owned numeric ID. The caller obtains run IDs from
[`orbit workspace:history`](../../6_workspace-history/workspace-history.md)
or from the `latest_setup_run.run_id` field returned by
[`orbit workspace:show`](../../4_workspace-show/workspace-show.md).

## Input Resolution

1. **Validate `run`.** `<run>` must be supplied and must be a positive integer.
   Missing or non-integer input fails before side effects with
   `error.code=validation_failed` and `error.meta.field=run`.
2. **Look up the run.** Resolve `<run>` against the gateway database. If no
   run record exists, fail with `error.code=workspace.run_not_found`.
3. **Authorize.** Verify the caller is authorized to read history for the
   workspace that owns the run. If not authorized, fail before side effects
   with `error.code=authorization_failed`.
4. **Confirm log retention.** If the run record exists but the durable
   per-step output has been pruned, fail with `error.code=workspace.log_not_found`.
5. **Select renderer** and assemble the captured run detail.

## Behavior Contract

1. **Historical audit read.** Read the run record and its captured per-step
   output from the gateway database. The command surfaces stored
   `stdout`/`stderr`/`exit_code` plus timing data captured per step and per
   run during application. No live process inspection is performed.
2. **Run wrapper timing.** Each run carries `started_at`, `finished_at`, and
   `duration_ms` for the whole run, mirroring the per-run timing already
   stored by [`workspace:history`](../../6_workspace-history/workspace-history.md).
3. **Per-step timing.** Each step carries its own `started_at`, `finished_at`,
   and `duration_ms`. Step timing is independent captured data, not a
   projection of run-level timestamps. The output payload preserves enough
   timing detail for renderer contracts to present run and step duration without
   a canonical contract change.
4. **No lifecycle environment capture.** The lifecycle environment variables
   defined in
   [`6_workspace/README.md`](../../README.md#lifecycle-step-environment)
   are **not** captured into per-step metadata. They are derivable from
   gateway-tracked workspace configuration (workspace path, parent app,
   effective PHP version, derived URL) and from
   [`workspace:history`](../../6_workspace-history/workspace-history.md)
   lifecycle actions such as `php_update`. Snapshotting them on every step
   row would create a redundant projection of state that already lives in
   workspace configuration.
5. **Truncation policy.** If a step's `stdout` or `stderr` exceeds the
   gateway storage cap (1 MB per stream per run), the captured stream is
   truncated and ends with the literal `[TRUNCATED]` marker. Per-step
   `stdout_truncated` and `stderr_truncated` booleans signal the truncation
   to JSON consumers without requiring marker parsing.
6. **Render output.** Return the captured run detail through the selected
   output renderer.

`workspace:log` must not:

- Connect to running processes, tail process manager logs, or otherwise
  stream live output. Live process logs belong to
  [`orbit process:logs`](../../../7_process/8_process-logs/process-logs.md).
- SSH into the owning app node. The command is gateway-only.
- Modify gateway configuration or node artifacts.
- Rewrite or repair historical run rows or captured output.

### Status Taxonomy

The `run.status` enum matches the values resolved for
[`workspace:history`](../../6_workspace-history/technical/1_workspace-history.md#status-taxonomy):

`steps[].status` is an execution enum, defined per step, that is owned by `workspace:log`.

| Field | Values |
| --- | --- |
| `run.status` | `running`, `completed`, `failed`, `cancelled`. |
| `steps[].status` | `success`, `failure`, `skipped`. |

`steps[].status=skipped` is reserved for steps the orchestrator did not
execute because an earlier step failed; it is not synthesised at read time.

### Retention

- Run records and their captured per-step output are retained for the lifetime
  of the workspace row on the gateway, matching the resolved
  [`workspace:history` retention contract](../../6_workspace-history/technical/1_workspace-history.md#retention).
- There is no automatic time-based pruning of captured output and no global
  retention setting.
- Captured output is removed atomically with the workspace via
  [`workspace:remove`](../../5_workspace-remove/workspace-remove.md), and via
  [`app:remove`](../../../5_app/6_app-remove/app-remove.md) or
  the [`app:prune`](../../../5_app/7_app-prune/app-prune.md) cascade when an
  app-level command removes a workspace.
- A run record may exist with its captured output pruned only once a future
  retention rule applied per row lands; until then,
  `error.code=workspace.log_not_found` is reserved for that case but is not
  produced by the default contract.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_workspace-log_output-render_human.md`](6.1_workspace-log_output-render_human.md):
  progress tree of captured steps, per-step stdout/stderr formatting,
  truncation marker rendering, prose errors.
- [`6.2_workspace-log_output-render_json.md`](6.2_workspace-log_output-render_json.md):
  JSON envelope, run and step shape, per-step timing fields, truncation
  flags, error codes.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Run not found | The provided run ID does not exist or is not visible to the caller. | Failure |
| Log not found | The run record exists but its captured output has been pruned. | Failure |

`workspace:log` exits zero whenever the gateway read succeeds, including
when a run completed without producing any captured output (empty steps array
plus zero-length stdout/stderr is a valid result, not a failure).

## Doctor Relationship

- `workspace:log` explains **past behavior**: which step in a captured run
  produced which output and which exit code.
- [`doctor --family=workspace`](../../workspace-doctor.md) verifies
  **current reality** and owns repair behavior. `doctor` does not rewrite
  or repair captured `workspace:log` history; it converges live workspace
  reality against current configuration.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
workspace run-log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/runs/{run}/log` |
| Effect | `read` |
| Subject | `WorkspaceRun` when the run is resolved and visible; `none` for not-found, validation, or authorization failures before a run can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceLogCommandTest.php` | Input resolution and `<run>` validation, run lookup, authorization, log-not-found vs run-not-found, per-run/per-step timing, no lifecycle env capture, truncation policy and per-step booleans, status taxonomy, read-only guarantee, and failure semantics. |
| `tests/E2E/WorkspaceLogTest.php` | Real read-only `workspace:log <run> --json` against a workspace with both a completed and a failed setup run, asserting captured stdout/stderr, per-step timing, and truncation reporting. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-log_output-render_human.md`](6.1_workspace-log_output-render_human.md#test-mapping)
- [`6.2_workspace-log_output-render_json.md`](6.2_workspace-log_output-render_json.md#test-mapping)
