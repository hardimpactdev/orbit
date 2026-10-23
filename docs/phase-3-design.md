# Phase 3: current design and next slice

Status: design proposal for independent parent review, 2026-10-23. This document
supersedes the earlier B1/B2 options. It authorizes no implementation or rollout.

## Current contract

Nick chose Swamp as the lifecycle owner. The Gateway owns effects and exactly-once
operation starts. Tasks keeps the board, assistance, records, and agent sessions.
Every workspace step runs in the group's one task VM. Subtasks share its checkout
in order. Swamp never runs Pi, commands, Git pushes, or merges itself.

Sources inspected:

- Orbit main `4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7`, fetched 2026-10-23:
  [ADR 0229](https://github.com/hardimpactdev/orbit/blob/4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7/docs/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations.md),
  [Tasks](https://github.com/hardimpactdev/orbit/blob/4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7/docs/reference/tasks.md),
  and [compute drivers](https://github.com/hardimpactdev/orbit/blob/4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7/docs/reference/compute-drivers.md).
- orbit-swamp main `baffd7b7b34d5add915486fece60434b9ac98aa4`, through PRs
  [1](https://github.com/nckrtl/orbit-swamp/pull/1),
  [2](https://github.com/nckrtl/orbit-swamp/pull/2),
  [3](https://github.com/nckrtl/orbit-swamp/pull/3), and
  [4](https://github.com/nckrtl/orbit-swamp/pull/4).
- Recall's Swamp topic and current decisions, read on 2026-10-23. Recorded group
  outcomes below come from Recall and the thread handoff. This slice made no live
  Gateway reads or mutations; GitHub merge metadata was independently rechecked.
- The installed Swamp architecture guide and software-factory authoring guide:
  keep the official factory, its declared stages/gates, short driver ticks, and
  behavior methods for Gateway effects. No new orchestration framework is needed.

The four built operation kinds are `tasks.provision`, `tasks.check`, `tasks.turn`,
and `tasks.publish`. Starts accept `Idempotency-Key`; a replay returns the original
operation, changed input conflicts, and keyed operations are never pruned. The
operation owns the group and workspace lease while waiting; delayed reads release
the worker, not the target. Durable owns retries and restart recovery *within* a
Pi turn. Swamp owns decisions between turns. Model credentials stay in the task VM
per group; GitHub App credentials stay on the Gateway.

`orbit-groups` maps one work item to `tg-<group>`. Its current path is
`provision → baseline → implement → handoff → review → publish → published → completed`,
with rework and next-subtask loops. Driver keys are `<workItem>.<stage>.c<cycle>`;
Gateway keys add `.s<subtask>` for child stages. A resumed entry reuses the stored
request and key. A new authorized attempt uses a new cycle. `published` waits for
a human merge and then calls synchronous `tasks:complete`.

The old proposals for development-Instance workspaces, generic command operations,
Pi on app-dev Instances, and a separate key-issue route are superseded. Deterministic
command-only work still needs its own ADR 0225 command contract; it is not added here.

## Evidence and the retirement gate

| Evidence | What it proves | What it does not prove |
| --- | --- | --- |
| Recorded group 2046; [website#7](https://github.com/nckrtl/website/pull/7), merged 2026-10-23 09:21:05 UTC at `32f41b94f97df27fb2ef81bc4b282226b1a8c918`; work item completed afterward | Swamp → live Gateway → task VM, ordinary feature work through a human merge | Review-and-merge Projects, topology subtasks, interruption recovery |
| Recorded group 2049; [website#8](https://github.com/nckrtl/website/pull/8), merged 12:07:19 UTC at `f200b245a8a8f5c71c7a12c7ca232ad85dd13412`; group completed and VM removed | A second external group, stored pre-approvals, publication/merge observation, cleanup | Automatic merge authority or worker/orchestrator kill proof |
| Phase 2 scratch SIGKILL/recover/resume results in the earlier spike and README | Local idempotent effects and factory recovery; caps and loops are expressible | Gateway worker recovery during a live waiting effect |
| PRs 1–4 and their fake-Gateway routing checks | Client, driver, retry routes, parked assistance on open children, era-aware evidence, stale-error fix | A live interruption receipt or supported exceptional Project modes |
| Serve restart after #4, recorded in the handoff | Service loads the merged source and serves HTTP | Recovery of an operation interrupted while doing work |

ADR 0229 step 3 is **not satisfied**. Before retirement, a separately authorized
proof must retain one group's operation IDs, keys, check ID/commit/tree, turn send
key/receipt, PR and cleanup result while (1) killing the Gateway worker during a
waiting check and (2) killing the orchestrator during an in-flight stage. Recover
and resume must return the same effect/result, with no second check, turn, commit,
push, or PR. Record terminal ownership evidence and completion, not only HTTP
health or passing unit tests. A Pi restart test alone does not cover either kill.
This design slice runs none of these experiments and starts no group.

## First slice: group assistance after the children end

Implement the [small group-comment contract](group-assistance-contract.md) first.
It closes an observed gap without changing execution, merge policy, or topology.
Swamp can park at `publish-retry` or `republish` after every child ends, but today
has no board request it can later resolve. Do not reopen an ended child to carry it.

The implementation sequence, after parent approval, is:

1. Orbit docs/ADR amendment and Gateway group-comment API, CLI/MCP parity, using
   existing assistance types, Activity, broadcasts, and access rules.
2. Independent review and normal Orbit release, with focused contract checks.
3. A separate orbit-swamp change to use the route only when no open child exists.
   Keep open-child assistance unchanged. Read recorded resolutions as input; a
   comment never silently grants a Swamp human-approval gate.

Acceptance and rollback are in the contract. This slice removes no tick class,
changes no orchestration mode, starts no new group, and requires no new operation
kind, queue, daemon, or schema for assistance ownership.

## Amendment A: review-and-merge Projects

Current create refuses these external groups with `422 tasks.orchestration_unsupported`.
Removing that guard alone would bypass Project 46's policy. The amendment must
preserve the existing Tasks contract, including the incoming-PR path, before the
tick engine can retire. The first implementation can cover Orbit-created branches
and retain the incoming path on the tick until its own slice is ready.

Proposed lifecycle for Orbit-created work:

1. Run ordinary subtasks with the existing handoff and review loop. A passing
   handoff commits the checked tree. When the Project uses handoff CI, extend
   `tasks.check` to reuse `TaskHandoffCi`'s effect/read path: push only
   `task-<id>-handoff`, poll its check, and return evidence. Its reminders and
   retry decisions move to Swamp. This branch never becomes the PR head.
2. Append a real `type: final_review` subtask, with no implementer, after approved
   work. Review the whole branch from its merge base with the fetched default
   branch. A fixup re-review covers the delta and unresolved findings. No extra
   Project check or merge of main is introduced solely for final review.
3. Extend the reviewer-turn contract for a final-review child to name the passed
   group handoff check of the candidate commit. Today `TaskTurnOperation::reviewCheck`
   accepts only a check belonging to the acting child; a synthetic final review
   has none. Validate the group, candidate HEAD/tree, and provenance explicitly;
   keep ordinary reviewers bound to their own child's check and preserve reset.
4. Add a narrow keyed `tasks:review:record` effect (proposed kind
   `tasks.review.record`) to validate the final-review turn receipt against its
   checked candidate and write the existing `task_reviewed_commits` ledger.
   An approval must be unaltered by reviewer edits, satisfy `final-review`, and
   match the recorded HEAD/tree. The caller cannot assert an arbitrary reviewed
   SHA. For the first PR retain the required PR fields and brief coverage.
   Completing the child and choosing the next stage remain Swamp decisions.
5. Extend `tasks.publish` to require that final-review record as well as the passed
   check for a review-and-merge Project. Push only that exact reviewed head,
   never force; reuse the existing publisher, mirror, and PR lookup. Ordinary
   subtask approval must publish nothing. Reviewer reset/difference evidence
   cannot be treated as an approval of a modified tree.
6. Swamp requests a narrow keyed `tasks:merge` effect (proposed kind `tasks.merge`)
   with the expected PR head. Gateway revalidates current Project policy, the
   reviewed ledger, no open work or assistance, default base, mergeability, the
   exact head's `pull_request` merge-check runs from `github-actions`, and a
   complete trusted-review read with no effective changes request on any head.
   Reuse `TaskPublication::mergeWhenReady`'s safety predicates and the App merger;
   extract the effect from its scheduler callbacks. Return `waiting`, `refused`,
   or `merged` evidence. Replays reconcile the PR; they never merge a newer head.
   GitHub receives the expected SHA and uses a merge commit. Swamp decides a
   subsequent read/attempt or assistance; the Gateway never chooses a fixup.
7. Complete only after the merge is observed. Completion/cleanup stays Orbit's
   effect. Making synchronous completion an operation is a separate small
   follow-up, not a reason to redesign these stages.

On findings, Swamp creates `Address final review` as a new child, runs the normal
implement/check/review loop, then requests a fresh final review. Preserve two
final-review fixups per window; the third finding set parks for assistance.
Only completion of an appended operator subtask opens a new window. This is
separate from implement/review caps and the settling fixup limits.

Required API deltas beyond the two proposed effects: permit an orchestrator to
append a typed final-review/fixup child; make that append replay-safe using its
source receipt/candidate identity under the group lock; expose the child's type,
reviewed-commit ledger, current PR head/base, check state, and complete/incomplete
review observations. Existing append accepts no `type` and always appends `todo`.
It must not accidentally start an implementer for final review. The amendment must
specify these fields before implementation; they are not supported today.

Incoming PRs require the remaining existing semantics: eligible listed author,
same repository, non-draft, default base, non-task branch, and one review group per
PR; baseline/setup before adopting its current head; read-only final review;
App `APPROVE`/`REQUEST_CHANGES` on the exact incoming head; no self-review on
App-created PRs. A moved author head needs a fresh review or a normal merge fixup,
never a force push. Incoming detection can retain its bounded GitHub read, but
creating/scheduling review work must have one owner. External groups cannot simply
be fed to `WatchIncomingPullRequestsAction` unchanged.

Conflict, CI, and trusted-feedback fixup *decisions and caps* move from
`TaskSettling` to factory stages. Keep its source/review identities and reviewed
commit checks. Preserve current no-implicit-main-merge policy. Do not retire
settling until those ordinary paths and incoming PRs are covered.

The proposed stages are `final-review → record-review → publish → merge → completed`,
with `final-review → fixup → handoff → review → final-review` on findings. Each
new effect uses the existing stored-request/stage/cycle key rule and returns
structured evidence; a pending merge never holds a driver call open. A finished merge attempt with outcome `waiting` or `refused` is not authority to
retry another head. Reads and writes retain the existing one-minute GitHub bounds.

Acceptance: unreviewed ordinary approval cannot reach the PR; final-review findings
produce at most two automatic fixups; a moved head invalidates approval; a missing,
failed, push-only, foreign-App, or wrong-head check never authorizes merge; incomplete
reviews never authorize merge; replay records/publishes/merges the same candidate.
Initially enable only a newly created, explicitly authorized external group after
the amendment lands. Existing groups keep their immutable mode. Roll back by
stopping admission of these new external groups; finish or cancel them through
their current owner. Never hand an active external group back to the tick.

## Amendment B: topology subtasks

Current create and append refuse declared topologies on external groups. Keep the
refusal until preparation, evidence, stop, and cleanup have operation contracts.
Reuse `TaskTopology`, `TaskTopologyAdmission`, `TaskVmTopologyAdmission`,
`TaskVmTopologyUnit`, and `TaskVmTopologyEvidenceReader`; no Swamp shell harness.

- Provision sizes from the union of non-cancelled child declarations, at most two
  test nodes. Keep the per-host cloned seed and nested Nodes inside the one task
  VM (ADR 0222). An appended declaration must fit that VM; no implicit resize.
- Baseline/setup precedes preparation. Add a waiting preparation step to
  `tasks.turn` before a prepared implementer or reviewer turn. It starts or reads
  the existing `orbit-task-topology-<run>` unit under the group's operation/lease.
  Persist its run before dispatch. Do not create a separate topology orchestrator
  or put preparation into the model's prompt.
- Reuse ready topology only when its union covers the child and its run matches
  the VM boot. A park/resume or stale boot needs preparation again. Consults and
  direction continuations follow the admitted turn's existing semantics; they do
  not trigger unrelated reacquisition.
- Map preparation-in-progress to operation `waiting`, not a blocked turn or a
  held worker. Return proven-terminal failed preparation as evidence to Swamp,
  which parks for assistance and chooses a new attempt. Preserve the harness's bounded local
  acquire retry; do not add a second Gateway lifecycle retry loop.
- Keep the topology-path gate in the handoff effect. Compare the group's own
  diff with the current merge base, including merge resolutions. Unreadable diff
  or required topology with no declaration fails closed.
- Return counted topology evidence with reviewer results: declared node, run/boot,
  time, label, command, exit code, and bounded output/reference. Preserve today's
  counting window and one counted record per declared node. Missing evidence is
  not approval. The existing gate is cooperative evidence, not tamper protection.
- Cancellation must stop/reconcile the exact preparation unit and prove its
  process lifetime ended before releasing ownership or starting another one.
  Unreachable/uncertain work remains fenced; normal group cancellation keeps
  the existing retained-workspace/removal-assistance path. VM destruction owns
  nested topology cleanup. Swamp never destroys guests itself.

Acceptance: one union on one correctly sized VM; no turn starts before ready; a
replayed waiting operation reads the same unit; stale boot is not mistaken for
ready; missing node evidence or topology-required paths cannot approve; stop and
cleanup retain ownership until proven safe. Rollback stops new topology external
groups and keeps in-flight groups with their original owner. No fallback sender.
These are future implementation checks, not results from this docs-only slice.

## Retirement order and decisions for the parent

The [class inventory](tick-engine-inventory.md) distinguishes delete candidates
from shared effects and safety. Do not delete whole mixed classes because an
external group no longer calls their tick entry point.

Recommended order: group assistance → review-and-merge amendment and own-branch
implementation → topology amendment → incoming/settling parity → authorized
worker/orchestrator interruption proof → remove the mode and tick lifecycle.
Keep ordinary external work available while later modes remain refused.

The parent must decide the first implementation's scope and approve the later
amendments. In particular:

- Accept the narrow post-child assistance contract, or require group comments
  during open-child work too? This design recommends the narrow contract first.
- Accept own-branch review-and-merge before incoming PRs? Incoming work remains
  on the tick until covered; full retirement waits for both.
- Approve the review-record and merge effects, and settle typed-child append
  identity and incoming-work ownership in the Orbit amendment before coding.
- Choose the authorized interruption-proof group/window and eventual production
  Swamp host/storage/licence operation. Neither proof run is repeated here.

One-minute polling, Swamp's 100-call limit (driver budget 90), and synchronous
completion's observed 60-second client timeout remain operational limits.
Notifications, longer check output, artifact-pin visibility, release-age exception
receipts, and async completion remain follow-ups. None requires a new framework
or expands this first slice. No merge, deployment, or phase transition follows
this design PR without the parent's independent review and decision.
