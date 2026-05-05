# Technical Contract: `orbit activity:show [id]`

[Back to public `activity:show` documentation.](../activity-show.md)

**Owner:** `operation`.

**Effects:** `read`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation local-node
  role contract.
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

## Caller Role Behavior

`activity:show` resolves the caller role from the local node role setting before
it reads command inputs or renders prompts.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the request to the gateway over HTTPS through WireGuard. |
| `gateway` | Executes the gateway history read locally. |
| `app` | Forwards the request to the gateway over HTTPS through WireGuard. App-node context does not grant additional history visibility. |
| `unknown` | Invalid local context. Fail before prompts, input validation, or gateway requests. |

Caller role only selects local execution versus gateway-client transport. The
gateway authorizes the history read against the current node identity and the
resolved activity id.

## Input Resolution

1. Resolve caller role.
   - If the local role setting is unset or `null`, resolve caller role as
     `control`.
   - If the local role setting contains an unsupported value or cannot be read,
     fail before prompts, input validation, or gateway requests.
2. Resolve `activity_show.id` from `[id]` or the selected input mode.
   - Interactive mode prompts when `[id]` is absent. See
     [`5.1_activity-show_input-mode_interactive.md`](5.1_activity-show_input-mode_interactive.md).
   - Non-interactive mode fails when `[id]` is absent. See
     [`5.2_activity-show_input-mode_non-interactive.md`](5.2_activity-show_input-mode_non-interactive.md).
3. Validate `activity_show.id` as a positive integer.
4. Select the output renderer.
5. Request the visible activity entry and correlated entries from the gateway.

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
- Mutate gateway intent, local settings, or node reality.
- Replay, revert, repair, adopt, or retry the selected activity.
- Inspect live node state, app runtimes, runtime backend programs, process
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
| Local context invalid | The local node role setting is unreadable or unsupported. | Failure before gateway history read |
| Gateway unavailable | The caller cannot reach the configured gateway API. | Failure |
| Authorization failed | The gateway denies activity-history access for the caller. | Failure |

## Doctor Relationship

- `activity:show` reads historical gateway records.
- `doctor` verifies current gateway-tracked configuration against enacted
  reality. It does not use activity history as a substitute for current probes.

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/ActivityShowCommandTest.php` | Gateway-history detail behavior, caller-role transport selection, id validation, not-found behavior, authorization behavior, related-entry visibility, read-only guarantee, no live probes, and no repair side effects. |
| `tests/Feature/Commands/Operations/ActivityShowInteractiveInputModeTest.php` | TTY selection, prompt behavior when `id` is missing, prompt ID, label, primitive, validation, prompt abort behavior, and `--json` opt-out. |
| `tests/Feature/Commands/Operations/ActivityShowNonInteractiveInputModeTest.php` | No-prompt selection without a TTY, `--json` forcing non-interactive mode, missing-id failure, invalid-id failure, and no prompt rendering. |
| `tests/Feature/Commands/Operations/ActivityShowJsonRendererTest.php` | JSON renderer selection, success envelope, activity detail DTO shape, related entry shape, every `error.code` value, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Operations/ActivityShowHumanRendererTest.php` | Human renderer detail view, related activity rendering, no-progress-tree behavior, validation failure prose, not-found prose, gateway failure prose, authorization failure prose, and absence of JSON envelopes in human mode. |
