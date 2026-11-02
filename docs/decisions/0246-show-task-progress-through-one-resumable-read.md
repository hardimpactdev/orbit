---
title: "ADR 0246: Show task progress through one resumable read"
sidebarTitle: "0246 Task progress"
description: "In progress. Each task has a numbered progress log in the Gateway. One cursor read with an optional wait gives the CLI, MCP and the web step-by-step progress that survives a lost connection."
---

# ADR 0246: Show task progress through one resumable read

Each [task](/decisions/0244-track-every-action-as-one-task) has a numbered progress log in the Gateway. A client reads it with one call: the events after cursor N, with an optional wait. The CLI, MCP and the web all use that read, so each shows step-by-step progress and continues after a lost connection.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One log and one read serve every client and every driver.

## Context

- The CLI polls `operation:show` every 2 seconds. It sees the overall status and the step that runs at that moment. A step that starts and ends between two polls is never shown.
- An MCP tool that starts work returns at once with "poll in 2 s". An agent sees no progress and concludes that the call timed out.
- No client can continue after a disconnect. A CLI that loses its connection, or that the user stops, cannot pick up the stream where it left off.
- Swamp holds the step detail, but reading it on demand is the wrong source. Many readers would load Swamp serve, which had sustained CPU problems on 2026-11-01. Swamp prunes run history after 6 hours, but a task is kept 30 days. The `command` and `pi` drivers of ADR 0244 report progress that is not a Swamp step list.

## Decision

The Gateway keeps one progress log per task and serves one cursor read. Every client is a thin layer on that read.

### One durable log in the Gateway

Each task has a progress log: events numbered 1, 2, 3 … in order. The number is the cursor.

| Field | Meaning |
| --- | --- |
| `seq` | The event number within the task. |
| `at` | When the Gateway recorded the event. |
| `step` | The step name: the label of the step in the reviewed flow chart `flows/<kind>.mmd`. |
| `event` | `started` or `finished`; a finished step has its outcome. The last event of a task is its terminal status. |
| `position`, `total` | "Step X of Y". |
| `elapsed_seconds`, `budget_seconds` | Time used so far, and the budget that applies. |

- The Gateway writes the log. Its own phases (admission, vault push, run start) write events where they happen.
- The shared observer (`SwampRunSubscriber`) already receives native run events to project live steps and terminal results. It also appends a `started` or `finished` event for each step. A replayed native event for a step and transition that the log already holds is ignored.
- Each driver's projection writes its own progress into the same log, so `command` and `pi` tasks have events as well.
- Nothing runs on the Node for this, and no step calls back to the Gateway.
- An event holds no output. Logs stay in `task:logs` (ADR 0244).
- The flow charts are the progress specification. Port lanes name workflow steps with the chart labels.

### One resumable read

`task:events <id> --after=N [--wait=SECONDS]` returns the events after cursor N, the new cursor, and the task status.

- Without `wait`, it answers at once.
- With `wait` (at most 30 seconds), it answers as soon as there is a new event or the task is terminal, or when the wait ends with no events.
- It reads only the Gateway database, never Swamp serve. The number of watchers does not change the load on serve.

This one read serves every case:

- **Streaming**: a loop of waiting reads. No SSE is needed.
- **Plain polling**: reads without a wait.
- **Reattach**: the same read with the last cursor, or with cursor 0 to replay from the start.

The task record's `current_step` fields come from the newest event and are not stored twice.

### CLI: detach and reattach

- A command that starts a task streams its events with the read loop, one line per event, such as `[3/7] Render environment … done in 4.2 s`.
- Ctrl-C detaches and never cancels. The CLI prints the task id and the command to reattach, and exits 130 as [CLI UX](/reference/cli-ux) requires. Cancel stays explicit with `task:cancel`.
- When the same command runs again while its task runs, admission refuses with `target_busy` and `details.operation_id`. If that task has the same kind and target, the CLI attaches to it and replays its events instead of failing. Otherwise it shows the refusal with the other task's id.
- `orbit task:watch <id> [--after=N]` reattaches. It replays from the start, or from cursor N.

### MCP

- A tool call that starts a task stays open. It sends standard `notifications/progress` messages, driven by the same waiting read, for up to 240 seconds. That is below the 570-second MCP request deadline.
- It then returns the task id, the events so far and the cursor. An agent reattaches by calling the same tool again, which attaches as the CLI does, or by calling `task-events` with the cursor. `task-events` is the generated tool for the read, not new code.
- **MCP Tasks extension.** The 2026-07-28 revision moved tasks into the official extension `io.modelcontextprotocol/tasks`. The server returns a durable task handle, the client polls `tasks/get` and cancels with `tasks/cancel`, and the handle survives a disconnect. It fits Orbit: an Orbit task is already that handle. When a client declares the extension, the Gateway returns the Orbit task id as the MCP `taskId`, answers `tasks/get` from the task record with the newest event as `statusMessage`, and maps `tasks/cancel` to `task:cancel`. That client needs no `task-events` call.
- The extension does not replace the read. `tasks/get` has one status message, not the step list or a cursor, and host support varies by client. A client without the extension uses the open call and `task-events`. Both paths are thin adapters on the same read.
- The same revision removed SSE resumption (`Last-Event-ID`) from Streamable HTTP. A broken response stream loses the request, and the client must send it again. A repeated call that attaches to the running task is the recovery the protocol expects.

### Web

Phase 2. The existing Reverb channel is only a doorbell: "task N has new events". The web app then fetches the events through the read with its cursor. No event content goes over Reverb.

### Retention

The log lives and goes with its task: 30 days for a task with an effect or a failure, 24 hours for a no-op scheduled fire (ADR 0244).

### Phases

1. The log, its writers, `task:events` with `wait`, CLI streaming, detach, attach and `task:watch`, and MCP progress with the Tasks extension.
2. The web doorbell.
3. SSE, only if measurement shows that the waiting read is not enough.

Until phase 1 is built, port lanes only name their workflow steps with the flow chart labels.

### Is this overcomplicated? Could clients simply wait?

A client that waits on one blocking call loses everything when the connection drops: the progress it saw and the way back to the task. Each transport would then need its own recovery: one for the CLI, one for MCP and one for the web. A blocking call also holds a Gateway worker for the whole task, which can take longer than any request deadline.

The log and one cursor read are the minimum that survives a disconnect. Waiting stays possible, as a waiting read of at most 30 seconds that a client repeats. The CLI loop, the MCP adapter and the web doorbell are thin layers on that read.

Orbit deliberately does not build:

- SSE in phase 1.
- Recovery for each transport.
- A separate progress service or store.
- Callbacks from steps or Nodes.
- Copies of step output in the log.

## Rejected alternatives

- **Read Swamp history on demand.** Each watcher loads Swamp serve, history is pruned after 6 hours, and the `command` and `pi` drivers have no step list there.
- **Clients block until the task ends.** A lost connection loses the progress and the task, and every transport needs its own recovery.
- **SSE with `Last-Event-ID` first.** It needs a held stream for each watcher and adds a second read path. MCP removed this resumption in the 2026-07-28 revision. The waiting read covers the same cases.
- **Progress over Reverb.** Reverb does not keep messages, so a client that was away misses events. As a doorbell it needs no delivery guarantee.
- **Only the MCP Tasks extension.** It has no step list or cursor, and not every client supports it.

## Consequences

- Every client shows the same steps, and any client continues after a disconnect.
- Agents see progress while a call is open, and they get a cursor when it closes.
- A waiting read holds one PHP-FPM worker for up to 30 seconds, and an open MCP call holds one for up to 240 seconds. The Gateway has eight workers. Phase 1 limits concurrent waits from measurement. A read over the limit answers at once without a wait.
- The flow charts become a contract: renaming a step label changes what clients show.
- The task store grows by one row per event. It is pruned with its task.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk
- ADRs: [0244](/decisions/0244-track-every-action-as-one-task)
- Detail: [Operations](/reference/operations), which becomes the task reference under ADR 0244; [MCP server](/reference/mcp); [CLI UX](/reference/cli-ux)
- Verify: Gateway tests for event append, replay deduplication, cursor reads and the waiting read's early return and timeout; a CLI test for Ctrl-C detach, attach on `target_busy` and `task:watch --after`; an MCP test for progress notifications, the 240-second return with a cursor, and `tasks/get` for a client that declares the extension; a proof receipt that streams a task, drops the connection and reattaches without lost events
