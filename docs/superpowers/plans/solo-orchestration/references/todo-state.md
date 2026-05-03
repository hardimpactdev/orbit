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
- `PIPELINE_FILL_STARTED process=<id>`
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `PIPELINE_READY`
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`
- `WORKER_STARTED process=<id>`
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`
- `REVIEW_APPROVED`
- `CHANGES_REQUESTED`
- `NEEDS_DIRECTION`
- `RUBBER_DUCK_PROPOSAL agent=<name> verdict=PATH|NEEDS_USER_DIRECTION`
- `E2E_STARTED process=<id>`
- `E2E_DONE status=PASSED|FAILED|SKIPPED lane=<name>`
- `ORCHESTRATOR_CLOSED`
- `PROCESS_CLOSED process=<id> reason=<role>`

## Dispatch Eligibility

- Implementer: open, unblocked, unlocked, `worker-ready`, no live owner, and
  not tagged `e2e-gate`.
- Reviewer: open, unblocked, `review-ready`, has `WORKER_DONE`, no live
  reviewer.
- E2E for implementation todo: has `REVIEW_APPROVED`, declares lane other than
  `none`, no `E2E_DONE`, no live E2E process.
- E2E gate todo: open, unblocked, unlocked, `e2e-ready`, declares lane other
  than `none`, no `E2E_DONE`, no live E2E process.
- Rubber ducks: open and blocked or `needs-direction`, clear blocker/comment,
  no completed duck pair for that blocker, no live duck processes.

## Outcome Transitions

- `SCOUT_REPORT status=READY` -> `worker-ready` for implementation todos.
- `SCOUT_REPORT status=READY` -> `e2e-ready` for todos tagged `e2e-gate`.
- Implementer start: `worker-ready` -> `in-progress`, post
  `WORKER_STARTED process=<id>`.
- E2E gate start: `e2e-ready` -> `in-progress`, post
  `E2E_STARTED process=<id>`.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS` -> `review-ready`.
- `WORKER_DONE status=BLOCKED|NEEDS_DIRECTION` -> `needs-direction`.
- `CHANGES_REQUESTED` -> `worker-ready` when fixes are in scope.
- `NEEDS_DIRECTION` -> `needs-direction`.
- Two duck `PATH` proposals that agree -> `worker-ready`.
- Any duck `NEEDS_USER_DIRECTION` or disagreement -> `needs-direction`.
- `REVIEW_APPROVED` with E2E lane `none` and a stated reason -> `verified`.
- `E2E_DONE status=PASSED|SKIPPED` after `REVIEW_APPROVED` -> `verified`.
- `E2E_DONE status=PASSED|SKIPPED` on an `e2e-gate` todo -> `verified`.
- `E2E_DONE status=FAILED` -> `worker-ready`, `e2e-ready`, or
  `needs-direction` by scope.

## Close-Out

Complete a `verified` implementation todo only when `WORKER_DONE`,
`REVIEW_APPROVED`, coherent tags, no blocking lock, and required E2E evidence
are present. Complete a `verified` E2E gate todo when `E2E_DONE` evidence is
present, tags are coherent, and no blocking lock remains. Post
`ORCHESTRATOR_CLOSED`.
