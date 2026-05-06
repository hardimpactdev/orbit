# Technical Contract: `orbit workspace:history [name]`

[Back to public `workspace:history` documentation.](../workspace-history.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read workspace history for the
  resolved workspace through gateway-owned access policy.
- The target workspace is visible to the caller (or resolvable from the current
  working directory when `[name]` is omitted).

## Signature

```bash
orbit workspace:history [name] [--app=<slug>] [--limit=<int>] [--since=<date>] [--until=<date>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model). `[name]` is resolvable
through the current working directory when omitted.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Never; resolvable through CWD or fails. | Never. | Current workspace if the CWD is inside a known workspace path. | Must match an existing workspace record visible to the caller. |
| `app` | `--app` | When the resolved `name` matches more than one workspace record. | Never. | Parent app of the uniquely resolved workspace. | Must match an existing app record visible to the caller. Single value only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |
| `limit` | `--limit` | Optional. | Never. | `50`. | Positive integer. Values greater than `500` are clamped to `500` and reported via `success.meta.pagination.limit_capped`. |
| `since` | `--since` | Optional. | Never. | None. | ISO 8601 datetime. Returns runs with `started_at >= since`. |
| `until` | `--until` | Optional. | Never. | None. | ISO 8601 datetime. Returns runs with `started_at < until`. Used as the exclusive range cursor for pagination beyond the cap. |

Workspace slugs are unique within an app but not globally unique. Two apps may
each own a workspace with the same `name`, so `--app` is the disambiguating
coordinate of the `(app, workspace)` identity rather than a redundant flag.

`--limit`, `--since`, and `--until` are scalar filters. Multi-value semantics
are not part of the initial contract.

## Caller Role Behavior

`workspace:history` behavior is access-policy-driven, not role-driven. All
authenticated callers with visible workspace access receive the same command
contract. App-node callers may inspect history for workspaces they are
authorized to see through gateway-owned access policy.

## Input Resolution

1. **Resolve `name`** from `[name]` or current working directory.
2. **Handle ambiguity.** If `name` matches multiple workspaces across apps and
   `--app` is missing, fail with `error.code=workspace.ambiguous_name`.
3. **Authorize.** Verify the caller is authorized to read history for the
   resolved workspace. If not authorized, fail before side effects.
4. **Validate filters.** Validate `--limit` (positive integer; clamped to
   `500`), `--since`, and `--until` (ISO 8601 datetimes).
5. **Select renderer** and query the gateway for history rows.

## Behavior Contract

1. **Query gateway history.** Read durable history rows for the resolved
   workspace from the gateway database, filtered by `--since`, `--until`, and
   `--limit` (after clamping). No host probing is performed.
2. **Sort results.** Rows are sorted by `started_at` descending (newest first).
   Both renderers use this single ordering: the human renderer displays it as a
   table, and the JSON renderer emits the same ordering as a flat array under
   `success.data.runs`.
3. **Apply limit cap.** If `--limit` exceeds `500`, clamp to `500` and set
   `success.meta.pagination.limit_capped` to `true`. Otherwise the field is
   `false`.
4. **Render output.** Return the filtered run list through the selected output
   renderer. Pagination metadata (`total`, `limit`, `since`, `until`,
   `limit_capped`) is reported under `success.meta.pagination`.

`workspace:history` must not:
- SSH into nodes.
- Probe host reachability or health.
- Modify gateway intent or node artifacts.
- Touch downstream family state.
- Rewrite or repair historical run rows.

### Status Taxonomy

| Status | Description |
| --- | --- |
| `running` | The run or lifecycle action is currently in progress. |
| `completed` | The action finished successfully. |
| `failed` | The action encountered an error and stopped. |
| `cancelled` | The action was manually terminated. |

### Lifecycle Actions

| Action | Description |
| --- | --- |
| `creation` | Initial workspace registration. |
| `setup` | Execution of `workspace:setup` steps. |
| `removal` | Execution of `workspace:remove` steps. |
| `adoption` | Discovery and registration via `doctor --adopt`. |
| `php_update` | Modification of the effective PHP version. |

### Limits

- Default `--limit` is `50`.
- Hard cap is `500` runs per request. A caller-supplied `--limit > 500` is
  clamped to `500` and `success.meta.pagination.limit_capped` is set to `true`
  so automation can detect the need to paginate further.
- A non-positive or non-integer `--limit` fails with
  `error.code=validation_failed` and `error.meta.field=limit`; clamping is a
  successful response with `limit_capped` metadata, not a failure.
- Pagination beyond the cap walks the timeline backward by re-querying with
  `--until=<oldest started_at returned>`. Because `--until` is exclusive, the
  boundary row from the previous response is not returned again. The
  `success.meta.pagination` slot is reserved for a future opaque cursor; the
  contract is purely additive.

### Retention

- History rows are retained for the lifetime of the workspace row on the
  gateway.
- There is no automatic time-based pruning and no global retention setting.
- History rows are removed atomically with the workspace via
  [`workspace:remove`](../../5_workspace-remove/workspace-remove.md), and via
  [`app:remove`](../../../5_app/6_app-remove/app-remove.md) or
  the [`app:prune`](../../../5_app/7_app-prune/app-prune.md) cascade when an
  app-level command triggers workspace removal.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_workspace-history_output-render_human.md`](6.1_workspace-history_output-render_human.md):
  no-progress-tree decision, run list table, prose errors.
- [`6.2_workspace-history_output-render_json.md`](6.2_workspace-history_output-render_json.md):
  JSON envelope, run shape, pagination metadata, error codes.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Workspace not found | No visible workspace matches the resolved criteria. | Failure |
| Ambiguous workspace | Multiple workspaces match the name and `--app` is missing. | Failure |
| Authorization failed | The caller is not authorized to read history for this workspace. | Failure |
| Validation failed | `--limit`, `--since`, or `--until` contains an invalid value. | Failure |
| Gateway unavailable | The CLI cannot reach the gateway API. | Failure |

`workspace:history` exits zero whenever the gateway history read succeeds,
including when the result set is empty.

## Doctor Relationship

- `workspace:history` is a historical audit log. It is **not** a source of
  truth for current convergence.
- [`doctor --family=workspace`](../../workspace-doctor.md) verifies current
  workspace reality and owns repair behavior. History explains **how** the
  current reality was reached (or why it failed); doctor verifies whether
  reality matches intent today.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
workspace run-history reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/{name-or-path}/history` |
| Effect | `read` |
| Subject | `Workspace` when the workspace is resolved and visible; `none` for not-found, ambiguous, validation, or authorization failures before a workspace can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceHistoryCommandTest.php` | Command contract: input resolution (CWD, name, `--app` disambiguation), authorization check, filter validation, status and action taxonomy mapping, default `--limit=50` and `500` cap with `limit_capped` reporting, range pagination via `--until`, retention contract (no gateway-side pruning), read-only guarantee, and failure semantics. |
| `tests/E2E/Read/WorkspaceHistoryTest.php` | Real read-only `workspace:history --json` against a workspace with mixed lifecycle and setup runs. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-history_output-render_human.md`](6.1_workspace-history_output-render_human.md#test-mapping)
- [`6.2_workspace-history_output-render_json.md`](6.2_workspace-history_output-render_json.md#test-mapping)
