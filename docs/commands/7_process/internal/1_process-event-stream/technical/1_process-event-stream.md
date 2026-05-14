# Technical Contract: `orbit process-event:stream`

[Back to internal `process-event:stream` documentation.](../process-event-stream.md)

**Owner:** `process`.

**Effects:** `stream`, `internal`.

**Prerequisites:**
- The CLI or API caller can reach the Orbit gateway.
- The current node identity is authorized to inspect the selected app, workspace, node, or process scope.

## Signature

```bash
orbit process-event:stream [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--process=<name>] [--after-id=<id>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | Optional. | Never. | None. | Must resolve to an app the caller may inspect. |
| `workspace` | `--workspace` | Optional. | Never. | None. | Must resolve to a workspace the caller may inspect. |
| `node` | `--node` | Optional. | Never. | None. | Must resolve to a node whose process events are visible to the caller. |
| `process` | `--process` | Optional. | Never. | None. | Process slug filter. |
| `after_id` | `--after-id` | Optional. | Never. | None. | Positive event id used to resume a stream. |
| `json` | `--json` | Optional. | Never. | `false`. | Streams structured event objects. |

## Stream Output Contract

`process-event:stream --json` is an internal stream contract, not a single JSON response. It is exempt from the standard `success`/`error` command envelope after the stream opens because it emits multiple newline-delimited JSON frames.

Failures before the stream opens use the standard command error envelope. After the stream opens, terminal stream failures emit one error frame and close the stream.

Every stream frame is one JSON object with a `type` discriminator:

| Frame type | Required fields | Meaning |
| --- | --- | --- |
| `snapshot` | `scope`, `processes[]` | Initial derived runtime status for the selected scope. Snapshot items may include `status="unverifiable"` when one runtime unit cannot be probed. |
| `event` | `id`, `event`, `scope`, `process`, `occurred_at` | Durable lifecycle event read from `process_events`. `event` is one of `started`, `stopped`, or `crashed`. |
| `error` | `code`, `message`, `meta` | Terminal stream failure after the stream has opened. |

`scope` contains the stable filters applied to the stream: `app`, `workspace`, `node`, and `process`, with absent filters omitted. Stream frames do not include top-level `success` or `error` keys.

## Behavior Contract

1. Resolve the requested event scope.
2. Send an initial snapshot for the selected runtime scope by deriving process units and probing live status.
3. Stream later `started`, `stopped`, and `crashed` events from durable process event history.
4. Resume after `after_id` when supplied.
5. Keep the stream open until the client disconnects or the stream fails.

This internal command does not mutate process configuration or runtime state.

## Failure Semantics
Standard failures defined in [Common Failures](../../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Stream failed | The event stream cannot be opened or resumed. | Failure (`error.code=process.event_stream_failed`). |

If the live snapshot cannot probe one runtime unit, the stream emits an unverifiable snapshot item for that unit when possible instead of dropping the whole stream.

## Doctor Relationship

[`process-doctor.md`](../../../process-doctor.md) verifies rendered runtime artifacts and lifecycle event notifier material. Event history itself is not desired state.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessEventStreamCommandTest.php` | Internal stream contract, scope filters, resume behavior, initial snapshot, durable event streaming, unverifiable snapshot items, pre-stream error envelopes, terminal `process.event_stream_failed` frames, authorization failure, and no process configuration mutation. |
