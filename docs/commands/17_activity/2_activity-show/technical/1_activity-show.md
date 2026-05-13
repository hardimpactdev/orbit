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

## Authorization By Caller Role

The CLI always sends the request to the gateway over HTTPS through WireGuard.
The gateway authenticates the WireGuard peer, resolves the caller's node
identity, and authorizes the history read against that identity and the
resolved activity id.

| Caller role | Behavior |
| --- | --- |
| `control` | Gateway returns the entry when visible to the calling control node. |
| `gateway` | Gateway returns the entry when visible to itself. |
| `app` | Gateway returns the entry when visible to the calling app node. App-node context does not grant additional history visibility. |

Caller role is gateway-resolved metadata, not a CLI-side branch. The CLI does
not inspect or depend on caller role.

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
- Return the selected activity entry with its full recorded details.
- Return other visible activity entries from the same `correlation_id` under a
  related collection.
- Order related entries by `occurred_at` ascending so the operator can read the
  correlated operation flow.
- Exclude the selected activity from the related collection.
- Treat activity `details` as type-specific diagnostic metadata. Stable fields
  belong on the activity DTO; type-specific details remain under `details`.

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
- Treat activity details as current readiness or drift diagnostics.

## Renderer Contracts

- [Human renderer](6.1_activity-show_output-render_human.md)
- [JSON renderer](6.2_activity-show_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | `id` is missing in non-interactive mode or is not a positive integer. | Failure before gateway history read |
| Activity not found | No visible activity entry matches the resolved id. | Failure |
| Gateway unavailable | The caller cannot reach the configured gateway API. | Failure |
| Authorization failed | The gateway denies activity-history access for the caller. | Failure |

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

- `activity:show` reads historical gateway records.
- `doctor` verifies current gateway-tracked configuration against applied
  reality. It does not use activity history as a substitute for current probes.

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Activity/ActivityShowCommandTest.php` | Gateway-history detail behavior, id validation, not-found behavior, gateway-side authorization behavior, related-entry visibility, read-only guarantee, no live probes, and no repair side effects. |
| `tests/Feature/Commands/Activity/ActivityShowInteractiveInputModeTest.php` | TTY selection, prompt behavior when `id` is missing, prompt ID, label, primitive, validation, prompt abort behavior, and `--json` opt-out. |
| `tests/Feature/Commands/Activity/ActivityShowNonInteractiveInputModeTest.php` | No-prompt selection without a TTY, `--json` forcing non-interactive mode, missing-id failure, invalid-id failure, and no prompt rendering. |
| `tests/Feature/Commands/Activity/ActivityShowJsonRendererTest.php` | JSON renderer selection, success envelope, activity detail DTO shape, related entry shape, every `error.code` value, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Activity/ActivityShowHumanRendererTest.php` | Human renderer detail view, related activity rendering, no-progress-tree behavior, validation failure prose, not-found prose, gateway failure prose, authorization failure prose, and absence of JSON envelopes in human mode. |
