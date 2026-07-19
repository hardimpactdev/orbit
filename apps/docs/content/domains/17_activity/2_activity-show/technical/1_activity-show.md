# Technical Contract: `orbit activity:show [id]`

[Back to public `activity:show` documentation.](../activity-show.md)

**Owner:** `activity`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read gateway activity history.

**Post-input path eligibility:**
- The resolved activity id must identify an activity entry visible to the
  caller.

## Signature

```bash
orbit activity:show [id] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `id` | `[id]` | Required. Interactive input mode may prompt when omitted. | Never. | None. | Positive integer activity id. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `activity_show.id` from `[id]` or the selected input mode.
   - Interactive mode prompts when `[id]` is absent. See
     [`5.1_activity-show_input-mode_interactive.md`](5.1_activity-show_input-mode_interactive.md).
   - Non-interactive mode fails when `[id]` is absent. See
     [`5.2_activity-show_input-mode_non-interactive.md`](5.2_activity-show_input-mode_non-interactive.md).
2. Validate `activity_show.id` as a positive integer.
3. Select the output renderer.
4. Request the visible activity entry and correlated entries from the gateway.

## Input Mode Contracts

- [Interactive input mode](5.1_activity-show_input-mode_interactive.md)
- [Non-interactive input mode](5.2_activity-show_input-mode_non-interactive.md)

## Behavior Contract

### Gateway History Detail Rules

- Read one durable activity record by id from gateway history.
- Return the selected activity entry through the canonical Activity DTO in
  [`activity-concepts.md`](../../activity-concepts.md).
- Return other visible activity entries from the same `correlation_id` under a
  related collection using that same complete DTO.
- Order related entries by `occurred_at` ascending so the operator can read the
  correlated operation flow.
- Exclude the selected activity from the related collection.
- Keep `effect`, `command`, and every other canonical field at the DTO top
  level. Type-specific audit data belongs under `properties`; neither selected
  nor related entries use alternate `summary`/`details` projections.

### Authorization Rules

- Verify the caller is authorized to read the selected activity entry before
  returning it.
- If the entry is not visible to the caller, return the same not-found failure
  used for a missing id.
- Related entries must be filtered to entries visible to the caller.

### Scope Boundaries

`activity:show` must not:
- Mutate gateway configuration, local settings, or node reality.
- Replay, revert, repair, adopt, or retry the selected activity.
- Inspect live node state, app runtimes, process manager programs, process
  logs, Caddy, or filesystem state.
- Treat activity properties as current readiness or drift diagnostics.

## Renderer Contracts

- [Human renderer](6.1_activity-show_output-render_human.md)
- [JSON renderer](6.2_activity-show_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Activity not found | No visible activity entry matches the resolved id. | Failure |

## Activity Logging

Emitted through the cross-cutting Loggable contract. See
[`activity-concepts.md`](../../activity-concepts.md).

| Field | Value |
| --- | --- |
| Channel | `api` (gateway controller). |
| Type | `activity.shown` |
| Effect | `read` |
| Subject | The selected `Activity` record, by id. `null` when the id resolves to no visible record. |
| Properties | `activity_id` (int), `related_count` (int), `outcome` (`shown`\|`not_found`\|`unauthorized`). No secrets, no raw argv. |
| Description | `derived`. Renderers may show `"shown activity #<id>"`. |

A successful read produces one entry. Not-found and authorization failures
also produce an entry recording the outcome.

## Doctor Relationship

- `activity:show` reads gateway-owned activity records.
- `doctor` verifies current gateway-tracked configuration against applied
  reality. It does not use activity history as a substitute for current probes.

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Activity/ActivityShowCommandTest.php` | CLI detail/related rendering, id validation, gateway error pass-through, and read-only guarantee. |
| `apps/gateway/tests/Feature/Http/Api/ActivityShowControllerTest.php` | Gateway authorization and activity detail API behavior. |
