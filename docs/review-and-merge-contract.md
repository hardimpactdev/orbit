# Review-and-merge: proposed Gateway contract

This is an implementation proposal, not a supported API or rollout approval.
It refines Amendment A for **Orbit-created branches only**. The first native
Gateway task is [hosted handoff CI](gateway-handoff-ci-brief.md). That task keeps
the review-and-merge admission guard closed. No runtime changes belong to this PR.

## Evidence and authority

Read on Beast on 2026-10-24: Orbit default checkout and GitHub `main` both name
`d6bce77673b00e4b5e60cf9260d5441a746c829a`. All Orbit links below pin that SHA.
The orbit-swamp base is `de462f6ca388a71e871bbcabcff42a99640d4976` (PR6 merged).
`/Users/nckrtl/sandbox-reset/PLAN.md` was read through the existing Mini SSH
connection. Its one-VM, narrow-slice and source-preservation rules apply; current
Tasks/ADR decisions supersede its old TIA and sandbox implementation details.
Recall's Swamp/Orbit topics and Swamp decisions were read; the parent's current
checkpoint supersedes Recall's older PR6 status. No harness memory was written.

The parent reports PR6 PASS at `be4c26ee172c905fb34abee0947cdec9ede3f238`, merge at
20:00:44Z, and deployment of the exact merge with an idle restart at 20:01:20Z.
That is parent-supplied evidence, not a test repeated here. Its read-only factory
scan found only terminal `tg-2044` (cancelled), `tg-2046` and `tg-2049` (completed).
Live post-child assistance adoption is still unproven. ADR0229's worker and
orchestrator interruption proof is also unproven. These are separate acceptance
items; neither requires manufacturing a group or blocks writing this contract.

Policy authority is [ADR0229][adr], [Tasks final review and merge][policy],
[Tasks rationale][rationale], and [ADR0223's integration-gate decision][gate].
Code identifies reusable effects and gaps; scheduler behavior alone does not
create a requirement. Retain these documented policies:

- Review the whole branch before a PR-head push. Ordinary subtask approval is
  insufficient. Handoff CI may push only `task-{group}-handoff`.
- Final review is a real child without an implementer. It commits nothing.
  A finding set gets at most two automatic final-review fixups per window.
  Only a completed operator child opens a new window.
- No implicit merge of main, extra Project check before final review, or
  branch-update API call. The PR workflow tests the merge ref before merge.
- Merge only a recorded final-reviewed SHA, into the Project default branch,
  with complete trusted review/check evidence. Use the App and a merge commit.
- Retain PR summary/change/breaking fields and brief coverage when opening the
  PR. Moving that validation out of tick callbacks does not remove the policy.

Swamp owns stages, prompts, budgets, fixup choices, assistance and explicit board
transitions. Gateway owns admission, one group holder/sender, workspace leases,
all VM/Git/Pi/GitHub effects and their evidence. All children share one task VM.
Durable owns recovery within one Pi submission. No second sender, new queue,
generic effect endpoint, factory fork or replacement workflow engine is proposed.
The Swamp architecture guide and installed software-factory guidance support
keeping stages/gates in the existing official factory and effects in behavior
methods. They do not authorize any approval or live run in this slice.

## Existing surface versus proposed changes

The installed MCP tool descriptions were inspected, together with the CLI,
requests, routes and DTOs in the pinned source. No live task tool was invoked.

| Surface at the pinned SHA | Present behavior | Proposed delta |
| --- | --- | --- |
| `POST /api/v1/task-groups`, `tasks:create`, `tasks_create` | External review-and-merge Projects and topology declarations return `422 tasks.orchestration_unsupported` [guard] | Keep this guard until the own-branch contract below is implemented and accepted; keep topology/incoming guards afterward |
| `POST .../{group}/check`, `tasks:check`, `tasks_check` | Keyed `tasks.check`; baseline/handoff; a passed handoff commits, with no hosted CI [check] | Add hosted CI within the existing operation; no new operation kind |
| `POST .../{group}/tasks`, `tasks:subtask:create`, `tasks_subtask_create` | Unkeyed append, always `todo`; input has title/brief/deliverables/topology, no type or source [append] | Add the narrow source-derived final-review/fixup variants below |
| `POST .../{group}/turn`, `tasks:turn`, `tasks_turn` | Keyed `tasks.turn`; reviewer check must belong to the acting child; returns receipt and reset evidence [turn] | Final-review child may use its source handoff check from the same group; preserve ordinary child binding |
| `GET .../{group}`, `tasks:show`, `tasks_show` | Already exposes child `type`, `fixup_problem`, latest check [child-dto], and `review_and_merge.reviewed_commits` [dto] | Add only source-operation lineage to children; group reads are records, not fresh merge authority |
| `POST .../{group}/publish`, `tasks:publish`, `tasks_publish` | Keyed `tasks.publish`, passed group handoff check, non-force push and PR lookup/open/update; no final-review ledger gate or brief coverage [publish] | Require recorded final approval for review-and-merge groups and preserve PR coverage policy |
| Review application | Tick code creates approval comments and `TaskReviewedCommit` [record] | New keyed `tasks.review.record`, CLI `tasks:review:record`, MCP `tasks_review_record` |
| Merge | Tick-only `TaskPublication::mergeWhenReady`; no external API/CLI/MCP merge command [merge] | New keyed `tasks.merge`, CLI `tasks:merge`, MCP `tasks_merge` |

`...` above means `/api/v1/task-groups`. New command/tool names are proposals,
not commands the current CLI/MCP accepts. SDK requests and generated MCP metadata
must follow the one native HTTP schema; do not maintain a second client schema.

## Shared wire, ownership and errors

Reuse the [operation contract][operations]. Operation starts return HTTP `202`
`{data: OperationData, meta: {request_id}}`, including a terminal keyed replay.
`GET /api/v1/operations/{id}` returns `200` with that record and ordered steps;
`result` is null until terminal. CLI following and MCP's operation/poll wrapper
remain their existing representations of that native record. Poll only after
the returned delay. No driver request waits for hosted CI or a merge to become
eligible. Error envelope remains `{status, error: {code, message, details}}`.

Require `Idempotency-Key` on the two new operation starts. It has the existing
1–128 character alphabet `A-Z a-z 0-9 . _ : -`. Kind scopes the permanent key.
Access is checked first; replay precedes current-state validation. Same key,
target and input returns the original operation; changed target/input is
`409 operation.idempotency_conflict`. Persist Swamp's exact request/key before
dispatch, including its run era, stage, cycle and child. A new authorized attempt
uses a new key; it never edits or reopens a terminal operation.

Reuse `TaskOperations` and the group execution lock [owner]. One non-terminal
operation holds the group even while waiting. Under that lock, the special
append below checks for an operation holder before changing child order. No
GitHub network call holds a DB transaction. Revalidate local eligibility after
reads and before a write; an active operation fences new child/status mutations
that could invalidate its candidate. Waiting releases the worker, not the group.
Uncertain check/turn/push lifetimes retain the existing lease until stopped or
reconciled. A board update or elapsed timeout is not terminal proof.

Existing errors stay unchanged: `403 peer.identity_unknown`/`node.access_denied`,
`404` for an absent target/foreign child, `409 tasks.not_orchestrated`,
`tasks.group_closed`, `tasks.no_workspace`, `tasks.subtask_not_current`,
`operation.target_busy` (with `details.operation_id`), and
`422 tasks.commit_not_checked`. Invalid shape/unknown fields or missing keys use
`422 validation.failed`. Queue errors retain their operation contract.
The following additional codes are **proposed**, with no effect on rejection:

| HTTP/code | Condition |
| --- | --- |
| `409 tasks.head_moved` | Live HEAD differs from the bound candidate commit |
| `409 tasks.tree_moved` | Working tree or commit tree differs from the bound checked tree |
| `409 tasks.review_not_ready` | New special append has open implementation work, unresolved findings, or an incompatible current final review |
| `422 tasks.review_source_invalid` | Source operation is wrong-kind, unkeyed, non-successful, foreign, or has no usable receipt/candidate |
| `422 tasks.review_not_approved` | Publish/merge lacks matching recorded final approval |
| `422 tasks.review_receipt_invalid` | Receipt is missing, invalid, wrong-role/thread/attempt, superseded or lacks deliverable confirmation |
| `409 tasks.review_candidate_changed` | A newer child/candidate/approval supersedes the review or its recorded context |
| `422 tasks.review_policy_required` | New review-record/merge route used outside an enabled own-branch review-and-merge group |
| `422 tasks.review_role_refused` | Implementer/consult turn requested on a final-review child |

Admission errors are HTTP responses. The same predicates failing after admission
end the operation `failed` with that code and no later effect. Merge gate outcomes
below are successful bounded attempts, not admission errors. Existing group mode
is immutable. Nothing here hands an active external group back to tick.

## 1. Checked candidate and hosted handoff CI

Keep the current check request exactly `{subtask: positive integer, phase:
baseline|handoff}` and the existing optional key (Swamp always supplies it).
Only a completed successful `tasks.check` with `outcome: passed`, a handoff
`TaskCheck`, non-null commit, checked tree and satisfied deliverables can supply
a candidate. The source is its operation ID, check ID, owning child, commit and
tree, not a caller-supplied SHA. `head` in the existing check result is the
pre-commit check snapshot; **`commit` is the candidate HEAD**, not `head` [check].

For `Project::handoffCheckName() != null`, add two persisted steps after the
existing commit: handoff push, then hosted CI read. Today that predicate follows
`reviewsAndMerges()`; it is not an independent caller option [project]. Reuse
`TaskHandoffCi`'s push, check classification and output helpers [ci]. Extract
them from scheduler callbacks; do not call its tick-guarded `test()` from an
external operation. Baseline and Projects without this policy retain their path.
Persist the required check name and branch in operation state before the CI push.
Recovery reads that same plan; a later Project edit cannot silently reinterpret
which check passed for this candidate.

The check result keeps `outcome`, `deliverables`, `check_id`, `commit`, `head`,
`tree`, `output`. Add `ci: null | {branch, commit, check_name, state, fault,
pushed_at, observed_at, runs, suites}`. Times are ISO 8601 or null before a read;
`runs`/`suites` use existing `GitHubCheckRun::toArray()` [ci-wire] and
`GitHubCheckSuite::toArray()` [suite-wire], with redacted/bounded text, not raw logs.
`state` is `passed|failed|pending|missing|unreadable`; `fault` is
`change|infrastructure|configuration|null`. Baseline/no-policy returns null.
Persist the same evidence in operation state while waiting. A local check pass
is not an externally usable completed candidate while required hosted CI waits.
Use one shared hosted-CI eligibility predicate in reviewer and publish admission:
for a Project requiring CI, the owning handoff operation must have succeeded with
passed CI on that check/commit. A locally passed `TaskCheck` alone, including one
left by cancelled CI observation, must not pass either consumer. This narrow
consumer guard belongs to the first task; full final-review publication gating
comes later. Store no duplicate check-status schema to express that distinction.

- Push the already recorded commit, without force, only to `task-{group}-handoff`.
  Never open a PR on it, push the task branch, or make a second commit for CI.
  Preserve CI-skip-directive removal from the handoff commit message. Recover
  through the existing VM push record and remote tip before repeating a push.
- Pending/missing/unreadable CI keeps the operation `waiting`, with delayed
  bounded reads and no worker held. Swamp owns its wall-clock budget and cancel.
- A code/workflow failure ends `succeeded` with `outcome: failed`, check
  `failed_step: ci`, and redacted failure evidence. A pass ends with
  `outcome: passed`. Post-check workspace movement ends `outcome: changed`.
- Infrastructure/configuration evidence never silently charges a failed-code
  budget or starts a fixup. No suite after the existing 600-second threshold,
  or completed suites missing the named job, reports the existing classification
  while waiting. Swamp may cancel and park for assistance. Gateway posts no
  reminder, assistance or next turn. It never implicitly merges main.
- Cancellation stops a local check or queued push under existing stop rules;
  a started push is reconciled before ownership ends. Once only hosted CI waits,
  cancel ends observation; it does not cancel GitHub workflows or erase the
  already pushed branch/check evidence. Uncertain local work stays fenced.

## 2. Source-derived children and final-review turn

Extend the existing append route with two discriminated variants. These are
small synchronous **record writes**, not a third operation kind:

| Exact request | Derived child |
| --- | --- |
| `{type: "final_review", source_operation: <handoff operation ID>}` | Final-review child, status `reviewing`, implementer null; Gateway derives `Final review` title/brief and `final-review` review deliverable |
| `{type: "implementation", source_operation: <review-record operation ID>}` | `Address final review` fixup from that record's `changes_requested` receipt; status `todo`, `fixup_problem: final-review`, `final-review-findings` review deliverable |

These variants prohibit title, brief, deliverables and topology overrides.
The ordinary existing append body remains unchanged; it cannot set type/source.
Use one nullable `review_source_operation_id` on the child, with a unique index
on `(parent_id, review_source_operation_id)`. For a final review store the source
handoff operation; for a fixup normalize the supplied review-record operation to
its original reviewer **turn** operation. Two review-record keys for the same
receipt therefore cannot create two fixups. This is a link to retained evidence,
not another receipt store. Expose that nullable field in existing `TaskData`.
Do not overload `continuation_of_task_id` or `fixup_head_sha` with operation IDs.

Under the group lock, resolve that immutable source and look up its canonical
identity before mutable eligibility checks. A repeat returns
`200 {data: TaskData, meta: {request_id}}` for the same child, even after it ends;
creation returns the same envelope with `201`.
It performs no status update, dispatch or second insertion on replay. Concurrent
repeats converge on the same row. Access/group identity still apply. No arbitrary
key registry is added to ordinary append. Reject mutation/deletion of the
source/type on these children; preserve their audit identity after completion.

For a new final review, every implementation child must have ended and every
non-cancelled/non-failed implementation must have matching ordinary approval
evidence from a successful keyed reviewer turn bound to its own passed check,
with unchanged workspace/reset evidence. Its source check must be the latest
approved candidate, including a completed fixup when applicable. No newer queued operator
work may be skipped. Append alone reads no VM, fetches nothing and sends nothing.
Source commit/tree are stored on the existing review snapshot fields as claims
that turn preparation must verify, not as proof of a live workspace read.
The current external client completes children from operation receipts without
creating tick-style approval comments. Resolve ordinary approvals from those
stored operations; do not assume `TaskFinalReview::hasUnreviewedWork()` alone
proves external approval or absence of newer work. Preserve these source IDs in
the final-review operation's context. This adds no ordinary-review comment API.

For a finding fixup, use a successful review-record operation for the same
group's unconsumed final-review findings. Insert before queued `todo` work while
preserving its relative order [policy]. Swamp first completes the reviewed child
through the existing explicit status API, then requests append. Source lineage
prevents a replay from adding a second fixup. The Gateway derives only the
requested record; it chooses no fixup and resets no window.

Use existing `tasks.turn` with the resulting child ID, role `reviewer`, source
`check`, caller prompt, `thread: new` and optional model. Do not add a general
group-wide check exemption. Only `type: final_review` may borrow its own source
check from a completed sibling. Ordinary reviewers still require their own
child's handoff. Reject implementer/consult roles on a final-review child.

Preparation resumes the VM, acquires the normal lease, and verifies branch
`task-{group}`, clean checked tree, `HEAD == check.commit_sha` and
`commitTree == check.tree_after == workingTree`. Bind the source operation/check,
workspace Instance/VM, lease, child/attempt and candidate **before sending**.
Gateway fetches the configured default branch under that lease; the agent never
fetches. Fetch/diff failure fails preparation without sending. Record default
ref/tip and merge base. This does not merge main or run another Project check.

Reuse `TaskReviewBase`, packet/context builders and the existing diff shape
[packet]. Initial final review uses the whole branch from the fetched merge
base. After a final-review fixup, use the prior final-reviewed tree as delta
base and retain its full unresolved findings; queued operator work still appears
in the delta. Missing prior provenance falls back to a whole-branch review,
never an empty diff. Captured diff truncation stays explicit; inaccessible full
evidence cannot produce an approval. Install the uncut context before dispatch.
Keep the prompt orchestrator-owned; do not import rubric reminders or tick loops.

The existing turn result remains authoritative for receipt/thread/before/after/
difference/after_reset/metrics [turn-result]. Add `candidate: {source_operation, check,
check_subtask, review_subtask, review_attempt, instance_id, node_id, lease_id,
approval_turns, commit, tree, default_ref, default_tip, merge_base, diff_base}` on
a final-review turn. `source_operation` is the handoff operation;
`approval_turns` is the ordered list of ordinary reviewer operation IDs
justifying the candidate. IDs/attempt are positive integers, refs are configured
names, hashes are full Git object IDs. The lease is the admitted review lease;
it need not remain live after the turn ends. Also preserve the parsed receipt's
existing `pull_request` object in the result: the current external result omits it.
Do not create a second review-receipt format or let callers submit a verdict.

Use a fresh reviewer thread for a new final-review child. Continuations of that
child keep the model and candidate; a changed candidate needs a new check and
final-review child. The permanent operation key remains the Pi send key and
Durable request ID. Recover the reserved thread, send and terminal receipt,
never a replacement submission. Preserve reset to the checked commit and
untracked-file removal. Save any removed difference. A changed/missing workspace
snapshot or incomplete mutation evidence cannot be laundered into approval by
a successful reset.

## 3. Record the final review without publishing

Proposed `POST /api/v1/task-groups/{group}/review/record`, kind
`tasks.review.record`. Exact body: `{subtask: <final-review child ID>, turn:
<successful keyed reviewer operation ID>}`. CLI uses `--subtask`, `--turn`,
`--idempotency-key`; MCP takes those body fields, group and `Idempotency-Key`.
No SHA, outcome, comment body or approval assertion comes from the caller.

Read the immutable terminal `tasks.turn` result and stored provenance. Validate
same group, current final-review child and attempt, source handoff check,
candidate HEAD/tree, acting reviewer thread, receipt hash and valid reviewer
outcome. Require a stopped turn, unchanged pre-reset workspace, matching reset
snapshot, and a fresh snapshot under the record operation's lease. New work or a
different candidate refuses recording. Approval must confirm `final-review`.
Reuse receipt parsing and confirmations [receipt], with fixed read-only safety.
Missing/invalid/incomplete receipt evidence refuses; it never means approved.

Persist one existing `TaskComment` for the receipt (thread, hash, attempt, body,
deliverables, cause and PR fields). Under the group transaction, recover an
already recorded source turn from retained review-record operations before
creating another comment. For approval set its `commit_sha` to the candidate
and `firstOrCreate` the existing `(group, sha)` reviewed ledger entry with
`source: orbit_push`, `review_task_id: child`, `pushed_at: null`,
`github_review_id: null` [record]. Repeated keys or a second key for the same
source turn reuse that comment and ledger entry. A conflicting existing ledger
provenance refuses; no row is reassigned to another review. Preserve existing
inherited-approval attribution only when its source is unambiguous; it never
authorizes another head. Commit the receipt, ledger and operation result
atomically so recovery cannot apply the record twice.
For a second key on an open group, a previously applied source turn returns that
stored result before current-child/attempt checks; it does not reinterpret the
receipt after the child ends. Only a new, unapplied source needs those mutable
checks. After group closure, only the original keyed replay is admitted.

Terminal result is exactly `{subtask, turn, comment_id, outcome, commit, tree,
reviewed_commit}`. `outcome` is `approved|changes_requested|blocked`.
`commit`/`tree` name the bound candidate for every valid outcome;
`reviewed_commit` is the existing `TaskReviewedCommitData` only for approval,
otherwise null. Findings/blocked receipts create no ledger entry. The operation
does not complete the child, append a fixup, clear assistance, start a turn,
push, open a PR, or submit a GitHub approval. Swamp decides those actions.

When this approval would open the PR, keep `TaskTurnPullRequest` validation and
the existing brief-coverage check [rubric]. Run that bounded effect as an
operation step before committing approval, reusing its source-receipt identity;
coverage failure is `422 tasks.review_receipt_invalid` with missing items in
details. Do not add an agent work loop or policy-driven resend in the Gateway.
The existing external `tasks.publish` does not supply this check today.

Swamp handles findings through the normal fixup implement/check/review loop and
then a fresh final review. Count at most two `final-review` fixups after the last
completed operator child. A third finding set is recorded, its final-review child
ends, and Swamp parks for assistance without a third automatic append. Neither a
resolution comment, new era, final review nor automatic fixup opens a new window.
This cap is distinct from ordinary rework and settling caps. A blocked receipt
uses explicit direction handling, not a fixup or an inferred approval.

## 4. Publish the recorded candidate

Extend existing `tasks.publish` input with optional `review: <review-record
operation ID>`. For an enabled review-and-merge group it is required, alongside
the existing `check` and optional `pull_request: {title, body}`. Other groups keep
their existing contract. Swamp first explicitly completes approved children and
sets the group's publish-ready board state. No open child, newer approved work,
unresolved findings or active assistance may be bypassed by an older approval.

Validate a successful approved record for the same group, check, final-review
child and current candidate; require its exact ledger entry. Under the push
lease repeat the HEAD/tree/commit-tree checks. For a new PR require its recorded
PR fields and passed coverage; render the existing Task PR description from
them, and reject conflicting caller title/body. Updating an existing PR may use
the ordinary title/body pair without changing candidate identity. A final-review
approval commits nothing; publication makes no replacement commit.

Reuse the mirror, `PublishTaskVmCommit`, non-force push and PR lookup before
open/update. Reconcile push/PR results after a lost response and update the same
ledger's `pushed_at` only after confirming the exact push. Revalidate repository,
task branch, default base and expected head on any found PR. If its stored PR is
closed or merged, refuse `409 tasks.review_candidate_changed`; never open a
replacement PR as replay recovery. Keep `tasks.not_fast_forward`,
`tasks.workflows_permission`, `tasks.push_failed` and `tasks.publish_failed`.
The success result stays `{branch, commit, pull_request_number,
pull_request_url}`. App-owned PRs get no self-approval. Publication is not merge
authority, and a handoff push never marks the PR candidate as published.

## 5. Bounded exact-head merge attempt

Proposed `POST /api/v1/task-groups/{group}/merge`, kind `tasks.merge`. Exact body:
`{review: <approved review-record operation ID>, head: <full candidate SHA>}`.
CLI uses `--review`, `--head`, `--idempotency-key`; MCP uses those body fields,
group and `Idempotency-Key`. Repository, PR number, branch, base, check name and
App installation come from the group/Project, never caller input. This effect
needs no workspace read or VM resume; a parked VM stays parked.

Admission requires the approved record/ledger at `head`, a published own-branch
PR, publish-ready group, no open child, no newer unreviewed work and no assistance.
Reject a mismatched request head with `422 tasks.review_not_approved`; report
later remote head movement as a refusal. Read PR health, check snapshot and
trusted reviews through existing readers [merge-reader], with observation times
and repository/PR/head identity. Recheck target identity and local eligibility
before dispatch. Never treat a group GET, old merge status or caller-supplied
GitHub JSON as permission.

Terminal `result` is `{outcome, reason, head, merge_sha, observation}`.
`outcome` is `waiting|refused|merged`; `reason` is one of the codes below (null on
merged); `head` is the requested candidate, while `observation.head` is the
observed remote head. `merge_sha` is null unless the exact head is confirmed merged.
`observation` is `{repository, pull_request_number, state, head, base_ref,
mergeable, conflicts, behind, observed_at, check_name, check_state,
check_observed_at, reviews_status, reviews_observed_at, trust_revision,
blocking_review_ids}`. Nullable unreadable values remain null, never false/empty
success defaults; `blocking_review_ids` contains numeric IDs only from a complete
read. `state` is `open|closed|merged|null`; `check_state` uses the existing
`RequiredCheckState` values; `reviews_status` uses existing `TaskReviewReadStatus`.
Retain the existing bounded evidence records in operation state for diagnosis.

| Outcome/reason | Effect |
| --- | --- |
| `waiting / read_unavailable` | No write; PR/check/reviews unavailable, malformed, incomplete, overflowing, stale or identities disagree |
| `waiting / not_mergeable` | No write; GitHub mergeability unknown or conflict present; Swamp chooses whether to request a conflict fixup |
| `waiting / check_pending`, `check_missing` | No write; no passed PR-workflow merge check yet |
| `waiting / rate_limited` | No write; shared one-minute per-head write bound not yet elapsed |
| `refused / head_moved`, `base_changed`, `pr_closed`, `unreviewed_work` | No write; a new candidate/review or operator decision is required |
| `refused / check_failed` | No write; failed, skipped, neutral, wrong-head or foreign-App named run |
| `refused / changes_requested` | No write; trusted effective request for changes on any head |
| `refused / github_refused` | Exact-head GitHub merge was rejected; retain HTTP status/message as redacted step evidence |
| `merged / null` | Confirmed this PR merged this candidate; store merge commit and existing group merge status/Activity |

Preserve all five documented merge predicates [policy]. The current Project
default ref must equal the candidate's recorded `default_ref`; changing that
review base requires a fresh review (`base_changed`). Check reads share the
existing cache for at most 60 seconds. Count at least one named run, all counted
runs completed with success from `github-actions`, on the candidate SHA, in a
`pull_request` workflow suite. Exclude superseded workflow/event/branch suites.
A green `push` handoff run cannot satisfy this gate. Complete run/suite pagination
and complete workflow identity are required; the existing workflow read bounds
one page of 100 and rejects `total_count` overflow [checks-reader]. No partial
list can become a pass.

Review trust comes from configured numeric account IDs for this repository,
not login, role, PR text or App identity. Use the latest decisive review per
trusted account **across heads**. An old-head request for changes still blocks
until that account approves or dismisses it [reviews]. Preserve reader validation,
duplicate-ID and pagination checks, limits of 10 review pages and 5 comment pages
for any feedback packet. Overflow/failure never means no objection. A verified
empty reviewer configuration may use the existing `disabled` observation with
no trusted accounts; invalid configuration must not masquerade as that exception.

Use the existing repository-scoped App merger with GitHub `sha: head` and
`merge_method: merge`. Never use auto-merge, bypass, squash, branch update or
force push. GitHub's expected-head check closes head movement after the read;
it does not atomically freeze review decisions/base/checks. Acceptance must not
claim a transaction across those services. Retain bounded cache policy and
GitHub's own protection checks, rather than inventing an approval framework.

A finished waiting/refused attempt releases its group holder. Its keyed replay
returns that same result; it does **not** refresh or try the merge again. Swamp
may request another attempt with a new key for the same approved head after the
one-minute bound. New head means a new checked/final-reviewed candidate. Within
an interrupted attempt, reconcile PR state before any resend: exact merged head
and merge SHA recover success; a different merged head refuses; unreadable write
outcome keeps the same operation waiting and fenced. Persist dispatch intent
before calling GitHub. Retry the write only after an authoritative open/same-head
read and the shared write bound; do not claim GitHub offers a merge idempotency
token. Recovery can make another conditional request but cannot merge twice or
merge a different head. Cancellation before dispatch writes nothing; after an
uncertain dispatch it waits for reconciliation and cannot undo a completed merge.

Merge does not complete a child/group, destroy a VM, clear assistance or schedule
a fixup. Swamp calls existing synchronous `tasks:complete` only after exact merge
observation, with all children ended. A lost completion response is reconciled
from group/removal state; completion is not described as keyed or asynchronous.

## Admission, later slices and acceptance

Before removing **only** the own-branch review-and-merge guard, Gateway must
amend ADR0229 Step 1's unsupported-mode clause, Check/Turn/Publish contracts,
new review-record/merge effects, record-only append identity, external sender
entry-point inventory, and Step 3 parity requirements. Update Tasks, Operations,
CLI, SDK and generated MCP contract in the same implementation sequence. Keep
the topology refusal. Explicitly guard incoming adoption (`pr_branch`/incoming
review groups) until its own contract is built; the watcher must not create or
schedule external work. A guard-only PR is not an acceptable first slice.

Incoming PRs remain later: eligible configured author, same repository,
non-draft/default base/non-task branch, one group per PR; baseline/setup before
head adoption; read-only exact incoming-head review; App APPROVE/REQUEST_CHANGES;
moved-author-head re-review or a normal merge fixup, never force push. Their
creation/scheduling needs one owner. Retain their tick path until covered.

Conflict/CI/trusted-feedback fixups also need their own source-bound append
variants before full settling parity. Reuse existing source/consumption records,
review IDs and immutable findings packets; no generic append authority is implied
by the final-review-only variant above. Keep documented settling caps (two per
problem, three per window), infrastructure versus code classification and no
implicit-main-merge policy. The first own-branch rollout may **park** on these
merge refusals and require operator work; it must not claim automatic settling
parity. Topology preparation/stop/evidence, consult/direction parity, async
completion and engine retirement remain separate. No old tick class is deleted.

Future implementation acceptance must prove: same-key replay and changed-input
conflict; one source child/comment/ledger entry across lost replies and concurrent
requests; ordinary cross-child review refused but a source-bound final review
accepted; exact clean candidate before/after reset; full/delta review provenance;
zero PR-head pushes before final approval; handoff-only CI cannot authorize merge;
incomplete/untrusted/wrong-head checks/reviews fail closed; moved head between
read and write is rejected by GitHub; refused/waiting replay starts no new attempt;
uncertain merge/push cancellation retains ownership; no lifecycle callbacks run.
Use meaningful regressions in the later implementation, not runtime tests in
this documentation PR.

After independent review and implementation, the parent controls deployment and
admission of a newly authorized group. Rollback stops new admission and new
driver requests, retains keyed operations/receipts/ledger, and reconciles active
owners before restoring a compatible release. Existing groups finish/cancel with
their original owner. Never switch mode, delete evidence, rewind a public branch
or undo a merge. The separate ADR0229 proof must retain group/operation/check/
turn/PR/cleanup identities while killing the Gateway worker during a waiting
check and the orchestrator in flight, then recover the same effects. Until the
amendments, parity and that bounded proof pass, guards and engine retirement
remain explicit unfinished work.

[adr]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations.md#L61-L99
[policy]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/reference/tasks.md#L1523-L1621
[rationale]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/reference/tasks.md#L2033-L2069
[gate]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/decisions/0223-run-one-gate-from-handoff-to-merge.md#L126-L132
[guard]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskGroupGuard.php#L18-L32
[check]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskCheckOperation.php#L94-L203
[append]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Actions/Tasks/CreateTaskAction.php#L19-L44
[turn]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L47-L82
[turn-result]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L240-L274
[dto]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Data/Tasks/TaskReviewAndMergeData.php#L18-L42
[child-dto]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Data/Tasks/TaskData.php#L20-L46
[publish]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskPublishOperation.php#L47-L188
[record]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskPublication.php#L114-L168
[merge]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskPublication.php#L205-L314
[operations]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/docs/reference/operations.md#L21-L112
[owner]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskOperations.php#L34-L89
[project]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Models/Project.php#L116-L146
[ci]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskHandoffCi.php#L43-L201
[ci-wire]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/GitHub/GitHubCheckRun.php#L17-L51
[suite-wire]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/GitHub/GitHubCheckSuite.php#L48-L52
[packet]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskReviewPacketBuilder.php#L20-L63
[receipt]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskTurnReceipt.php#L9-L59
[rubric]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskReviewOutcome.php#L171-L237
[merge-reader]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskPullRequestMerger.php#L24-L61
[checks-reader]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Infrastructure/Tasks/GitHubTaskPullRequestMerger.php#L56-L169
[reviews]: https://github.com/hardimpactdev/orbit/blob/d6bce77673b00e4b5e60cf9260d5441a746c829a/apps/gateway/app/Domain/Tasks/TaskReviewSelection.php#L20-L57
