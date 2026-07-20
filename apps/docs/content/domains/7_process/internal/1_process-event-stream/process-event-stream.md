# `orbit process-event:stream`

[Back to Process commands.](../../README.md)

**Visibility:** Internal. Hidden from the public command list.

**Purpose:** Stream process lifecycle events.

**Description:** Opens a live stream of process lifecycle events for internal consumers such as browser toolbars, diagnostics, and command adapters. It keeps runtime status displays current without polling every process continuously.

**Owner:** `process`.

**Effects:** `stream`, `internal`.

**Technical contract:** [`technical/1_process-event-stream.md`](technical/1_process-event-stream.md)

**Prerequisites:**
- The CLI or API caller can reach the Orbit gateway.
- The current node identity is authorized on the selected node or instance serving node to inspect the instance, workspace, node, or process scope.

## Behavior

Use this command to open a live event stream for one or more process runtime scopes.

### Stream behavior

The stream begins with a snapshot of current process state and then delivers later lifecycle events as they happen.

- Sends an initial snapshot for the selected runtime scope by deriving process units and probing live status.
- Streams later `started`, `stopped`, and `crashed` events from `process_events`.

### Constraints

These rules limit what the stream observes and how it interacts with process state.

- Observes lifecycle events recorded by internal process-family mechanics. Crashed events may originate from runtime hooks that Orbit manages, but the intake path is not a CLI command contract.
- Supports resume from an event id.
- Does not mutate process configuration or runtime state.

## Inputs

These flags scope the stream to the selected runtime context.

- `--instance=<project.instance>`: filter by concrete instance. A bare project slug is accepted only when that project has exactly one instance; ambiguity fails with `validation_failed`, `field=instance`, and `reason=instance_required`.
- `--workspace=<workspace>`: filter by workspace.
- `--node=<node>`: filter by node.
- `--process=<name>`: filter by process slug.
- `--after-id=<id>`: resume after a known process event id.
- `--json`: stream event objects as newline-delimited JSON. This is an internal stream frame contract, not a single `success`/`error` response envelope.

## Doctor Relationship

[`process-doctor.md`](../../process-doctor.md) verifies rendered runtime artifacts and the event notifier material required to emit crash events. Event history itself is not desired state.
