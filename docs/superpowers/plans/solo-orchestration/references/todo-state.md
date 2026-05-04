# Todo State

Shared todo state contract for the Solo orchestration loop.

## Phase Tags

Use one open-work phase tag:

- `draft`: not dispatchable.
- `worker-ready`: scout-approved and dispatchable.
- `e2e-ready`: scout-approved E2E gate, dispatchable only to the E2E role.
- `in-progress`: implementer or E2E runner active.
- `review-ready`: worker handoff done.
- `needs-direction`: direction required.
- `verified`: review/E2E evidence accepted; close-out remains.

Attention tags may coexist:

- `changes-requested`
- `e2e-failed`

## Lifecycle Labels

Use exact labels in todo comments:

- `CYCLE_STARTED process=<id>`
- `CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `RECONCILE_STARTED process=<id>`
- `RECONCILE_DONE status=MERGED|FAILED|NEEDS_DIRECTION`
- `RECONCILIATED`
- `RECONCILE_CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `PIPELINE_FILL_STARTED process=<id>`
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`
- `WORKTREE_SETUP_STARTED process=<id> path=<path> branch=<branch> base_ref=<ref>`
- `WORKTREE_PREPARED path=<path> branch=<branch> base_ref=<ref>`
- `WORKTREE_SETUP_FAILED path=<path> branch=<branch> base_ref=<ref>`
- `WORKER_STARTED process=<id>`
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`
- `REVIEW_APPROVED`
- `CHANGES_REQUESTED`
- `NEEDS_DIRECTION`
- `RUBBER_DUCK_PROPOSAL agent=<name> verdict=PATH|NEEDS_USER_DIRECTION`
- `E2E_DISPATCHED process=<id> lane=<e2e-provisioning|e2e-feature|none>`
- `E2E_DONE status=PASSED|FAILED|SKIPPED lane=<name>`
- `ORCHESTRATOR_CLOSED`
- `PROCESS_CLOSED process=<id> reason=<role>`

## Dispatch Eligibility

- Implementer: open, unblocked, unlocked, `worker-ready`, has
  `WORKTREE_PREPARED`, no `WORKTREE_SETUP_FAILED`, no live implementer, and
  not tagged `e2e-gate`.
- Workspace setup: open, unblocked, unlocked, `worker-ready`, no live
  workspace setup process, no `WORKTREE_PREPARED`, and not tagged `e2e-gate`.
- Reviewer: open, unblocked, `review-ready`, has a `WORKER_DONE` newer than
  the latest reviewer outcome, no live reviewer.
- E2E for implementation todo: tagged `verified` (carries `REVIEW_APPROVED`),
  declares lane other than `none`, no `E2E_DONE` newer than the latest
  `REVIEW_APPROVED`, no live E2E process.
- E2E gate todo: open, unblocked, unlocked, `e2e-ready`, declares lane other
  than `none`, no `E2E_DONE`, no live E2E process.
- Rubber ducks: open and blocked or `needs-direction`, clear blocker/comment,
  no completed duck pair for that blocker, no live duck processes.

## Outcome Transitions

- `SCOUT_REPORT status=READY` -> `worker-ready` for implementation todos
  (applied by the pipeline filler).
- `SCOUT_REPORT status=READY` -> `e2e-ready` for todos tagged `e2e-gate`
  (applied by the pipeline filler).
- Implementer start: `worker-ready` -> `in-progress`, post
  `WORKER_STARTED process=<id>`.
- Workspace setup start: keep `worker-ready`, post
  `WORKTREE_SETUP_STARTED process=<id> path=<path> branch=<branch> base_ref=<ref>`.
- `WORKTREE_PREPARED` keeps the todo `worker-ready`; the orchestrator
  dispatches the implementer in the same cycle once setup completes.
- `WORKTREE_SETUP_FAILED` keeps the todo `worker-ready` unless the orchestrator
  determines the failure needs direction.
- E2E gate start: `e2e-ready` -> `in-progress`, post
  `E2E_DISPATCHED process=<id> lane=<name>`.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS`: add `review-ready`, remove
  `in-progress`. The implementer process stays open.
- `WORKER_DONE status=BLOCKED|NEEDS_DIRECTION`: add `needs-direction`, remove
  `in-progress`. The implementer process stays open.
- Reviewer `CHANGES_REQUESTED` (or merge-conflict variant): remove
  `review-ready`, add `in-progress`. Reviewer `send_input`s the long-lived
  implementer with the findings comment id.
- Reviewer `NEEDS_DIRECTION`: remove `review-ready`, add `needs-direction`.
- Two duck `PATH` proposals that agree -> remove `needs-direction`, add
  `worker-ready` (the long-lived implementer receives the resolution comment
  via `send_input`).
- Any duck `NEEDS_USER_DIRECTION` or disagreement -> keep `needs-direction`.
- `REVIEW_APPROVED` with E2E lane `none` and a stated reason -> add
  `verified`, remove `review-ready`. Reconciler picks up.
- `REVIEW_APPROVED` with non-`none` lane -> add `verified`, remove
  `review-ready`. Orchestrator dispatches E2E.
- `E2E_DONE status=PASSED` after `REVIEW_APPROVED`: keep `verified`.
  Reconciler picks up.
- `E2E_DONE status=PASSED|SKIPPED` on an `e2e-gate` todo -> add `verified`.
- `E2E_DONE status=FAILED` on an implementation todo: remove `verified`, add
  `in-progress`. E2E `send_input`s the long-lived implementer with the
  failure comment id.
- `E2E_DONE status=FAILED` on an `e2e-gate` todo: route by scope.
- `E2E_DONE status=SKIPPED reason=merge-conflict`: keep `verified`. E2E
  already `send_input`'d the long-lived implementer to rebase. Next cycle
  reruns E2E.
- `RECONCILE_DONE status=MERGED` -> reconciler completes the todo and posts
  `ORCHESTRATOR_CLOSED`. The long-lived implementer process is closed by the
  reconciler with `PROCESS_CLOSED process=<id> reason=implementer`.
- `RECONCILE_DONE status=FAILED|NEEDS_DIRECTION` -> route by scope before
  close-out. Implementer stays open.

## Close-Out

The reconciler completes a `verified` implementation todo when
`RECONCILE_DONE status=MERGED` is posted, the worktree is removed, the branch
is deleted, and the long-lived implementer process is closed. Complete a
`verified` E2E gate todo when `E2E_DONE` evidence is present, tags are
coherent, and no blocking lock remains. Post `ORCHESTRATOR_CLOSED`.
