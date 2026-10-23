# First slice: group assistance after all children end

Status: proposed Orbit ADR 0229 amendment, not an existing endpoint.

## Confirmed gap

At Orbit main `4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7`:

- `routes/api.php` exposes comments only under
  `/api/v1/task-groups/{group}/tasks/{task}/comments`.
- `TaskGroupsController::storeComment` requires `task.parent_id == group.id`.
- `StoreTaskCommentAction` writes a child comment, then requests direction on
  child and group. `TaskAssistance::apply` refuses direction on an ended child,
  but can set it on the still-open group. The external resolution path calls
  `resolveExternalSubtask`; it clears the group only if the child owns the same
  complete open request. An ended child owns none, so the group request stays.
- `TaskAssistance` already supports a top-level record, row locks, typed causes,
  Activity and broadcasts. Group show/status already expose assistance.

At orbit-swamp main `baffd7b7b34d5add915486fece60434b9ac98aa4`,
`syncAssistance` in `extensions/behaviors/_lib/gateway_stage.ts` records
`skipped: "no open subtask can own the request"` at post-child gates. Thus the
board receives no request at `publish-retry` or `republish`. This is a source
verification of the recorded live limitation; no new live failure was induced.

## Smallest proposed API

Add a group-level sibling of the existing comment routes. Use the same Gateway
Node access check, response envelope, comment types and pagination/filter rules.

| Method | Path | Contract |
| --- | --- | --- |
| POST | `/api/v1/task-groups/{group}/comments` | `{type: "assistance_requested" | "resolution", body: nonblank string, author: string}`; `201 {data: TaskCommentData, meta: ...}` |
| GET | `/api/v1/task-groups/{group}/comments` | Group-owned comments only; existing `type` and `limit` filters; `200 {data: [...], meta: ...}` |

Writes initially require an **external, non-terminal group with no open child**.
Use existing `409 tasks.not_orchestrated` for tick groups and
`409 tasks.group_closed` for ended groups. Add a narrowly named refusal such as
`409 tasks.assistance_subtask_open` for an open child (proposed name). Ordinary
request validation is `422`; missing/inaccessible groups follow existing access
rules. Reads remain available after completion so answers outlive the VM.
Do not expose an agent-thread or receipt-writing input through this route.

Persist the existing `TaskComment` with `task_group_id = group.id` and
`task_id = group.id`; both foreign keys already reference `tasks`. This avoids
nullable child IDs, dummy children, a new request table, or a new assistance kind.
Audit `TaskComment::task()`'s subtask global scope and group-comment readers before
implementation: group-owned reads must resolve a top-level Task explicitly. Do
not relax scopes globally or include these rows in an actual child's history.
The comment DTO already accepts integer IDs without dereferencing the child.

Under one transaction and group-row lock, recheck mode, group status, and no open
children, store the comment, and apply existing `TaskAssistance` semantics:

- `assistance_requested` records `Direction`, with body as question and reason,
  using the current explicit operator-comment replacement rule.
- A nonblank `resolution` clears the group's direction request only. It neither
  clears children nor resolves `RemovalFailure` or other safety failures.
- Keep Activity, redaction and `TaskBroadcasts::groupChanged`. The board and
  `tasks:status` show the group through their current assistance fields.
- Start no agent, check, topology, retry, state move, completion, or Swamp approval.
  No call into the tick's direction-delivery path is allowed.

Reuse the current one-open-request/comment model. This does **not** promise
exactly-once comment insertion: a lost HTTP reply can duplicate audit comments,
as on today's child route. Assistance state writes are idempotent for the same
complete fields. Add no operation kind or permanent idempotency registry for a
comment. The Swamp client reads the current group request before posting again
and stores its own stage/cycle/body identity. It posts a resolution only after
reading a matching Swamp request; if it observes an operator replacement, it leaves that request
alone. Concurrent replacements retain today's one-request comment semantics; this
slice adds no versioned resolution protocol. A human resolution is recorded
input, not automatic permission to pass a factory gate. Explicit Swamp approval
or an already-authorized, matching pre-approval still controls continuation.

This narrow scope deliberately avoids changing how independent group requests
and mirrored child requests coexist while children are open. If a new child is
appended, the no-open-child precondition refuses group writes; the driver rereads
and uses the child path. Do not bypass that refusal by changing old children.

## Acceptance for the implementation PR

1. An open external group with all children ended can request and resolve help;
   both comments are readable and the board flag follows them. No child changes.
2. A parked publish retry or republish raises a readable reason naming work item,
   stage, cycle and gate. Once explicitly approved, continuation clears only the
   matching Swamp request. Existing open-child behavior remains unchanged.
3. Empty resolution, wrong access, tick group, ended group and newly open child
   are refused as specified. An ended-group cleanup failure remains untouched.
4. Repeated identical requests do not change the assistance state; a lost response
   followed by a read/retry cannot start an effect. An observed operator replacement
   is left alone. Duplicate audit comments are allowed and documented.
5. Focused Gateway/API tests cover these cases, CLI/MCP parity and serialization
   of a group-owned comment. Client tests use the fake Gateway. Assert zero turn,
   check, state-advance or scheduler dispatches. These tests belong to the future
   implementation; none ran in this design slice.

## Rollout and rollback

Land the additive Orbit contract first, then the Swamp client in a separate PR.
Until the client is updated, its current skip behavior remains valid. No new
production group is needed to publish the API. Parent approval controls any live
acceptance exercise.

Rollback the Swamp client first so it returns to the documented post-child skip.
Keep the additive API/read path until any active group request is explicitly
resolved by its operator. Do not clear requests or reopen children as rollback.
Remove the unused route later if desired; existing comments remain readable.
No live ownership, orchestration mode, or task status changes during rollback.
