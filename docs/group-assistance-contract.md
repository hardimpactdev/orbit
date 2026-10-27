# Group assistance after all children end

Gateway contract: delivered by Orbit PR #1500, merged at
`d6bce77673b00e4b5e60cf9260d5441a746c829a`. The parent reports release 432,
read-only smoke checks and tick confirmation. Swamp adoption is the subject of
this change; no live assistance write or adoption proof has run in this author turn.

The deployed [Tasks contract](https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/reference/tasks.md#group-assistance-after-all-children-end)
and `StoreTaskGroupCommentAction` are authoritative.

## Gateway contract

| Method | Path | Behavior |
| --- | --- | --- |
| POST | `/api/v1/task-groups/{group}/comments` | Only `type: assistance_requested | resolution`, nonblank `body` (max 100,000), `author` (max 255); `201 {data: TaskCommentData, meta: {request_id}}` |
| GET | `/api/v1/task-groups/{group}/comments` | Group-owned history, newest first; omit `type` and `limit` to read all; `200 {data: [...], meta: {request_id}}` |

Writes require an external, non-terminal group with every child completed, failed
or cancelled. Tick groups return `409 tasks.not_orchestrated`, terminal groups
`409 tasks.group_closed`, and open children `409 tasks.assistance_subtask_open`.
Reads remain available after completion and VM removal. Access uses the existing
Gateway Node rules. No new API, operation kind or conditional-write protocol is added.

A request replaces the group's request with Direction, using body as both question
and reason. A resolution clears only Direction, never failures, removal failures
or children. Both are comments/assistance updates only: no retry, turn, check,
status change, ownership change, publication, completion or Swamp approval.

Group comments have `task_group_id = task_id = group.id`. Comment insertion is
not exactly once. A lost response may produce duplicate audit comments; identical
assistance state changes are no-ops. The API has no request version or conditional
resolve, so clients must not claim atomic ownership protection from a prior read.

## Swamp adoption rules

Keep ordinary open-child gates on the existing child-comment path. Use group
comments for the driver failure budget described below and the two post-child factory gates: `publish / publish-retry` and
`published / republish`. Never reopen or choose an ended child as a substitute.

At each child-comment write boundary, use the fresh group and child snapshot,
not the historical local group flag. A new child request requires both requests
to be inactive with kind, question and reason null. A child resolution requires
an open child's Direction request and either an inactive group or a complete
matching Direction copy on the group. An independent Direction, safety cause,
missing fields or inconsistent state blocks the write. Recheck on later ticks
and after restart; a refusal must not latch forever after the request is resolved.
These checks preserve observed requests, but cannot close the API's race after
the final read.

For the group path:

1. Read unfiltered group history and the complete current request before every
   request retry or resolution. Fail closed on unreadable or inconsistent data.
   Check mode, terminal state and every child's status on each reconciliation.
2. Write a human-readable body naming work item, stage, cycle, gate and factory
   run start. Persist that exact identity before posting. History plus the full
   current Direction fields recover a lost response or missing local record.
3. Do not replace an observed operator request, safety cause, stale-era request
   or ambiguous history. A new/open child or refused write is reread next tick,
   without falling back to an ended child's route. Save the reason in the
   assistance resource; do not manufacture approval to escape the condition.
   If an open child appears after the group request is resolved, release the
   obsolete local group identity only after reading full history and confirming
   the current request is inactive with kind, question and reason all null.
   Then resume the ordinary child path, including on an unparked tick or after
   restart. Preserve the local identity while any group request remains active
   or inconsistent; do not replace it through a child comment. No group comment
   write is allowed while a child is open. Releasing local identity grants no
   factory approval and starts no retry.
4. If a person resolves the board request, keep it resolved while the factory
   remains parked. A resolution comment is recorded input, not gate consent.
5. For factory gates, clear only the matching Swamp Direction request after reading an **approved**
   official factory approval record for the same work item, gate, stage, cycle
   and current run era. Advancing stage, an old/wrong/rejected decision, or merely
   reporting `parked: false` is insufficient. The driver passes its factory name;
   the Gateway behavior reads the factory state/approval resources, rather than
   accepting an approval assertion from the comment body.
6. Keep explicit pre-approvals scoped to work item/gate/stage/cycle and the run
   era. Keep factory evidence era filtering. The assistance method calls only
   comment reads/writes; it neither invokes approval nor dispatches an effect.

The current request must agree with the newest history entry, its author,
full body and stored identity. A newer operator comment, a non-Direction cause, a
reset or missing identity leaves the request untouched. A race after the final
read remains possible under the deployed API; no client-side check closes that
window. On an ambiguous response, read again on the next tick rather than
immediately replaying the write. Allowed duplicate audit comments are not proof of
an extra operation.

No fork of `@swamp/software-factory` is needed. Use its approval resources and
normal gates as documented by the official authoring/driving guidance.

## Per-group driver failures

The per-group driver parks after three consecutive failed owner generations.
Its request uses stage `driver`, cycle equal to the failed generation, and gate
`driver-retry-g<generation>`. This is a driver retry identity, not a factory gate.
Use the same child route while a child is open and the group route after all
children end. Keep the full history, current-request, ownership, run-era, and
safety checks above.

Only an explicit `retry` on `orbit-group-tg-<id>` records a retry decision. The
Gateway reads that definition's `retry` resource for driver requests. It never
substitutes a factory approval, another generation, an old run-era decision, or
a board resolution. The decision must match the work item, driver stage,
generation, gate, actor, and current factory run era before resolving the
post-child request. Retry resets only the failure budget. It does not approve a
factory gate, reset factory state, or start an operation.

The durable failure resource is updated before assistance is sent. A failed
notification leaves parking in place and is reconciled on later supervisor
visits. Repeated visits do not consume attempts or start a fourth owner run.
See [per-group failure policy](per-group-workflows.md#repeated-run-failures).

## Validation and bounded parent acceptance

Fixture regressions cover both post-child gates, unchanged open-child routing,
exact versus wrong/old approvals, operator replacements, safety causes, resolved
board requests without approval, lost request/resolution replies, restart recovery,
unreadable history and zero effect dispatch by assistance. Use Gateway PR #1500's
response fixtures and the existing fake-Gateway factory routing test. No live
Gateway writes or group creation belong to these checks.

The focused suites passed 54 tests. The original client fails the new request
regression: the expected group POST is absent. The tests also exercise refused
writes, exact persisted-body retry, final-read changes and pre-reset pre-approvals.
The retained-group-identity regression failed before the P2 correction and passes
with it. It covers the intervening unparked tick, repeated child gates and restart
reloads, plus refusal for active operator/safety requests or unreadable history.
The R2 regression failed for all four independent causes at `be131ae0`: a current
replacement already visible in the fresh child-path read was overwritten.
Request and resolution guards now preserve that observed state across repeated
and restarted ticks, including stage changes, matching reasons with different
questions, and safety copied onto the child. Once the independent request is
cleared, ordinary child request/resolution can proceed without a latched refusal.
After the R2 correction, the real-factory routing fixture passed all 13 scenarios (one test, 1m52s),
including group request/resolution at both gates, unchanged child routes, and no
duplicate operation starts per key. Both changed behavior entry points passed
Deno type checks. These are local results, not live adoption or worker/orchestrator
interruption proof under ADR 0229.

After independent review and merge, the parent may deploy the client through the
normal controlled serve restart when no stage is in flight. First use read-only
checks of the existing completed group 2052's history and the client version;
these cannot prove adoption. A live acceptance exercise needs **separate explicit
authorization** for one named external, non-terminal group already at a supported
post-child gate. Do not create or mutate a group just to accept this PR.

That later exercise is bounded: record original group/history and gate identity;
one parked tick should raise one matching request; another tick must start no
operation; a board resolution alone must not approve/retry; an explicit exact
Swamp approval may allow the normal retry and matching cleanup. Stop if the
request changes, a safety cause appears, a child opens, or ownership is uncertain.
Retain comment IDs, approval record, tick record and operation keys as evidence.
The parent chooses the group, mutations and rollback before that exercise.

## Rollback

Pause the relevant driver under the parent's control and restore the previous
client revision (`3c2b8eeec1069c2d1ec063cfb2c2aefdf91e009c`), then restart serve safely. It returns to the prior post-child
skip; ordinary child routing and the additive Gateway API remain available.
Keep comments and assistance resources. An operator explicitly resolves any
outstanding group request after checking its current identity; rollback never
clears it automatically, reopens children, changes status/mode, or transfers work
to another owner. No Gateway rollback or package fork is required.
