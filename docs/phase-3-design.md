# Phase 3: current design and next slice

Status: design proposal for independent parent review, updated 2026-10-24. This document
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

## Delivered slice: group assistance after the children end

The client adopts the [delivered group-comment contract](group-assistance-contract.md).
Orbit PR #1500 merged at `d6bce77673b00e4b5e60cf9260d5441a746c829a`;
PR6 delivered the separate Swamp client change.
It closes an observed gap without changing execution, merge policy, or topology.
The client adopts this route for `publish-retry` and `republish` after every
child ends. It reads full history/current request and clears only its matching
Direction request after an explicit exact factory approval in the current era.
Open-child assistance keeps its child-comment path. A board resolution grants no
factory approval and starts no retry. Never reopen an ended child to carry it.

Gateway and client delivery are complete. The parent reports PR6 independently
PASS at `be4c26ee172c905fb34abee0947cdec9ede3f238`, merged as
`de462f6ca388a71e871bbcabcff42a99640d4976` at 20:00:44Z, then deployed with an
idle restart at 20:01:20Z. No tests or deployment checks were repeated here.
The parent's factory scan found only terminal `tg-2044`, `tg-2046`, `tg-2049`;
there was no eligible live post-child gate. Live assistance adoption remains
unproven and requires its separately authorized opportunity. ADR0229 interruption
proof is a different open item; neither requires a manufactured group.

Acceptance and rollback are in the contract. This slice removes no tick class,
changes no orchestration mode, starts no new group, and requires no new operation
kind, queue, daemon, or schema for assistance ownership.

## Amendment A: review-and-merge Projects

The [source-backed API contract](review-and-merge-contract.md) now owns this
amendment's request, response, error, replay, ownership and acceptance details.
It pins current Orbit main at `d6bce77673b00e4b5e60cf9260d5441a746c829a` and
corrects the earlier inventory: group reads already expose child type and the
reviewed-commit ledger. Typed source-derived append, final-review group-check
binding, keyed review recording and exact-head merge remain proposed APIs.

The [smallest first native Gateway task](gateway-handoff-ci-brief.md) extends
`tasks.check` with hosted handoff CI and keeps review-and-merge admission closed.
It adds no operation kind. Later steps bind the checked candidate to a real
final-review child, record its existing receipt and ledger entry, gate publication
on that approval, and make one bounded exact-head merge attempt. Swamp keeps
lifecycle decisions and caps; Gateway keeps all effects on the group's one VM
and the sole holder/sender. No tick callback becomes a second external sender.

The first admitted scope may cover Orbit-created branches and park on merge
refusals. Incoming PRs and automatic settling parity remain explicit later
slices with their guards. Topology is separate. Before any own-branch guard is
removed, Gateway must amend ADR0229 and deliver the native contracts and safety
checks named in the contract. No guard change, runtime implementation or rollout
is authorized by these orbit-swamp documents.

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

- The narrow post-child assistance slice is authorized. Open-child group comments
  remain outside this slice; ordinary gates keep the child path.
- Accept own-branch review-and-merge before incoming PRs? Incoming work remains
  on the tick until covered; full retirement waits for both.
- Review the proposed native API contract and first handoff-CI task brief.
  Gateway documentation must amend ADR0229 before its behavior changes; incoming
  ownership remains guarded until its own slice.
- Choose the authorized interruption-proof group/window and eventual production
  Swamp host/storage/licence operation. Neither proof run is repeated here.

One-minute polling, Swamp's 100-call limit (driver budget 90), and synchronous
completion's observed 60-second client timeout remain operational limits.
Notifications, longer check output, artifact-pin visibility, release-age exception
receipts, and async completion remain follow-ups. None requires a new framework
or expands this first slice. No merge, deployment, or phase transition follows
this design PR without the parent's independent review and decision.
