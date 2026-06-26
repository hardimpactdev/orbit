# Technical Contract: `orbit activity:list`

[Back to public `activity:list` documentation.](../activity-list.md)

**Owner:** `activity`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read gateway activity history for
  the requested filters.

## Signature

```bash
orbit activity:list [--app=<app>] [--node=<node>] [--effect=<effect>] [--correlation=<uuid>] [--limit=<count>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | Optional. | Never. | `null`. | Non-empty app key matched against recorded activity relationships. |
| `node` | `--node` | Optional. | Never. | `null`. | Non-empty node name matched against recorded activity relationships. |
| `effect` | `--effect` | Optional. | Never. | `null`. | One of `read`, `write`, `destructive`. |
| `correlation` | `--correlation` | Optional. | Never. | `null`. | UUID string. |
| `limit` | `--limit` | Optional. | Never. | `25`. | Integer from `1` through `200`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Resolve filters from supplied options.
3. Validate field-local input.
   - `correlation` must be a UUID when present.
   - `limit` must be an integer from `1` through `200`.
   - `app` and `node` must be non-empty when present.
   - `effect` must be one of `read`, `write`, `destructive` when present.
4. Request visible activity history from the gateway.

No input-mode-specific contracts are required. All inputs are optional, and the
command does not prompt.

## Behavior Contract

### Gateway History Read Rules

- Read durable activity history recorded by the gateway database.
- Return entries newest first.
- Apply `app`, `node`, `effect`, and `correlation` filters against recorded
  activity relationships, not live node or app probes.
- Return an empty successful result when no visible activity matches the
  filters.
- Preserve each entry's `correlation_id` so automation can group related
  command, API, and gateway apply records.
- Report the recorded entry effect as `read`, `write`, or `destructive`.

### Authorization Rules

- Verify the caller is authorized to read gateway activity history before
  returning rows.
- Filter visibility is gateway-owned. The caller must not receive rows outside
  its authorized history scope.
- If the requested filter is not visible to the caller, return an authorization
  failure rather than leaking whether hidden activity exists.

### Scope Boundaries

`activity:list` must not:
- Mutate gateway configuration, local settings, or node reality.
- Inspect live node state, app runtimes, process manager programs, process
  logs, Caddy, or filesystem state.
- Fix drift, adopt reality, or enqueue repair work.
- Collapse correlated activity into one synthetic row; correlation is metadata,
  not a replacement for individual history entries.

## Renderer Contracts

- [Human renderer](6.1_activity-list_output-render_human.md)
- [JSON renderer](6.2_activity-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |

No matching activity is success with an empty result.

## Activity Logging

Emitted through the cross-cutting Loggable contract. See
[`activity-concepts.md`](../../activity-concepts.md).

| Field | Value |
| --- | --- |
| Channel | `api` (gateway controller). |
| Type | `activity.listed` |
| Effect | `read` |
| Subject | `null`. The command returns a filtered set, not a single record. |
| Properties | `filter_app` (string\|null), `filter_node` (string\|null), `filter_effect` (`read`\|`write`\|`destructive`\|null), `filter_correlation` (uuid\|null), `filter_limit` (int), `result_count` (int). No secrets, no raw argv. |
| Description | `derived` from filter set. Renderers may show `"listed N activity entries"` and the applied filters. |

A successful read produces one entry. Authorization or validation failures
produce an entry recording the failure outcome under the same correlation id.

## Doctor Relationship

- `activity:list` reads gateway-owned activity records.
- `doctor` verifies current gateway-tracked configuration against applied
  reality. It does not use activity history as a substitute for current probes.

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Activity/ActivityListCommandTest.php` | CLI filters, table rendering, empty results, gateway error pass-through, and read-only guarantee. |
| `apps/gateway/tests/Feature/Http/Api/ActivityListControllerTest.php` | Gateway authorization and activity list API behavior. |
