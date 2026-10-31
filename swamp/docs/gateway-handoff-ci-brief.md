# First native Gateway slice: hosted CI in tasks.check

Implement only section 1 of the [review-and-merge contract](review-and-merge-contract.md).
This is a future task brief for `hardimpactdev/orbit`, not authorization to change
Gateway in this documentation PR. Baseline source:
`d6bce77673b00e4b5e60cf9260d5441a746c829a`. Re-read source and ADRs at the future
task's actual base before editing; report material drift to the parent.

## Result and boundary

When an external group's Project requires hosted handoff CI, a keyed handoff
operation must not report a usable passed candidate until CI passes on its exact
committed tree. Reuse `tasks.check`, its target lock, detached check, commit
step and VM push record. For each new CI-required handoff operation, that step
creates one fresh sanitized commit, including an empty commit for an unchanged
checked tree. Same-operation replay/recovery retains that commit. Add persisted
handoff-push/CI-read steps and the `ci` evidence field defined in the contract.
No new operation kind, table, queue,
daemon, command runner, CLI verb, model or generic effect abstraction is needed.

Keep `tasks.orchestration_unsupported` for review-and-merge create and topology
append. This prerequisite can land safely before supported own-branch admission.
Tests construct isolated external fixtures whose Project policy requires CI;
they do not weaken production admission to make the path reachable. Do not enable
a Project or create a live group for acceptance. Existing ordinary external
groups still finish without hosted CI when the policy is absent.

## Docs first and files

First amend Orbit's
`docs/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations.md`
Check subsection: handoff CI inside the keyed operation, waiting/evidence/stop
semantics, unchanged guard. Update `docs/reference/tasks.md` external check and
handoff-CI sections, and `docs/reference/operations.md` only as needed for the
result/steps. Update CLI/SDK/MCP reference output where generated contracts expose
the added result. No claim that review-and-merge admission is delivered.

Expected implementation boundary, relative to `apps/gateway`:

- `app/Domain/Tasks/TaskCheckOperation.php`: existing check/commit, with
  `allowEmpty: true` and skip-directive sanitization for each new CI-required
  handoff operation; persisted handoff push/read steps, waiting result and
  cancellation reconciliation. Baseline/no-policy commit behavior is unchanged.
- `app/Domain/Tasks/TaskHandoffCi.php`: extract/reuse push/read/classification
  effects without tick reminders, assistance, retry decisions or commits. Keep
  the tick path working against the same helpers. Reuse its sanitized message
  and fresh/empty-commit policy in the operation's single commit step; make no
  second CI commit within that operation.
- Existing `TaskPullRequestMerger`, `TaskPullRequestPublisher` and VM publish
  job/record: reuse as-is where possible. Touch them only for a demonstrated
  contract gap; no duplicate CI reader or push journal.
- Existing check result/request DTOs and operation registration: additive result
  only. Input stays `{subtask, phase}`; no caller-selected CI switch/check name.
- `TaskTurnOperation::reviewCheck()` and `TaskPublishOperation::requireCheck()`:
  use one shared CI eligibility predicate when the Project requires CI. A locally
  passed check whose hosted-CI operation is waiting, failed or cancelled is not
  eligible. This is a narrow consumer guard, not final-review publication support.
- `tests/Feature/Api/TaskCheckOperationTest.php` and the existing handoff-CI,
  publish-lease and GitHub-reader tests that cover extracted behavior. No unrelated
  runtime changes in CLI, Swamp driver, Pi server, topology, incoming PR watcher,
  final-review append, review recording or merge admission. Publication changes
  are limited to that shared CI predicate.

Review `Project::handoffCheckName()` at `app/Models/Project.php:130`,
`TaskCheckOperation::commit()` at `app/Domain/Tasks/TaskCheckOperation.php:159`,
`TaskHandoffCi::test()` at `app/Domain/Tasks/TaskHandoffCi.php:47`, and the shared
check snapshot at `app/Infrastructure/Tasks/GitHubTaskPullRequestMerger.php:86`.
These line numbers refer to the pinned SHA. The contract's permalinks provide
the source evidence. In particular, Tasks reference lines 1065–1069 require a
sanitized new commit even for unchanged work; `TaskHandoffCi.php:99–100` uses
`allowEmpty: true` to ensure a new push starts CI. Do not invoke the tick-guarded
test method externally.

## Meaningful checks for that implementation

The positive regression must fail on the pinned source: local pass → exactly one
fresh sanitized checked-tree commit → only `task-{group}-handoff` pushed → pending
CI keeps the same operation waiting → exact named CI pass returns `outcome: passed` with the same
check/commit/tree. Assert no PR-head push, PR, agent send, assistance, subtask
advance or second local check. No-policy and baseline paths must still pass
without any CI push/read. Run the extracted helper's tick callers' focused tests.

Negative/recovery coverage must establish:

- Same key after a lost reply/restart returns the same operation/check/push;
  changed input conflicts. Recovery after push but before its response reconciles
  the existing remote tip, without a second commit/push effect.
- Commit H's hosted workflow is cancelled by infrastructure; Swamp cancels
  observation and authorizes a new key without tree changes. The new operation
  creates H2 != H with the same checked tree and a sanitized message, then pushes
  H2 to start fresh CI instead of reusing the cancelled run. Same-key replay or
  recovery retains H2: no H3, second local check, push or CI-start effect.
- An unchanged tree at an old commit whose message contains a CI-skip directive
  still gets a new commit with those directives removed. Merely passing a clean
  message to a signer that returns the old HEAD does not satisfy this case.
- Tree/HEAD change between local check, commit and handoff push cannot report a
  pass. A second holder is refused while CI waits; the single operations worker
  is free to run another target's work.
- Missing, pending, unreadable, incomplete or overflowing snapshots never pass.
  Failed change jobs return `outcome: failed`/`failed_step: ci`. A foreign App,
  skipped/neutral job, missing named job or infrastructure cancellation preserves
  classification and lets Swamp choose assistance; no code-failure budget charge.
- A superseded workflow suite cannot supply a false green; wrong-head evidence
  cannot pass. Handoff CI's successful push evidence is not a PR merge-check
  receipt. Preserve complete reader bounds rather than rewriting their tests.
- Cancelling a queued push prevents dispatch. Cancelling a started push waits for
  terminal reconciliation. Cancelling CI observation leaves its committed/pushed
  evidence intact and does not cancel GitHub workflows. An unreachable local
  process retains the current stop/fencing behavior. Neither reviewer nor publish
  can reuse a locally passed check after CI cancellation to bypass the hosted gate.

Use isolated fakes for positive/negative cases and fault injection for the actual
operation resume boundaries. Do not assert exactly-once from a mocked happy path.
Run the focused Gateway tests, then the checks required by current Orbit guidance
for that future code change, plus docs lint/build. No new packages. A live worker
or orchestrator kill is a separate authorized proof, not part of these tests.

## Delivery, deployment and rollback

Open one ready Orbit PR, report exact head/base and the guard that remains, and
return it to the parent for independent review/publication/merge. The author
does not deploy or grant API approval. After merge, the parent verifies the normal
Gateway release and read-only API/help output. Keeping admission closed means no
new live review-and-merge group is needed to accept this prerequisite.

Before the later guard-opening PR, implement and accept typed source-derived
children, final-review group-check binding/receipt recording, ledger-gated
publication and exact-head merge as separate narrow steps. Amend ADR0229's
unsupported-mode and sender inventory clauses then. The Swamp client adopts only
the delivered native surface, with stored requests/keys and explicit factory gates.

For rollback, stop new requests and reconcile any active `tasks.check` holder.
Restore a compatible Gateway release only after added push/CI steps have ended
or been cancelled with terminal local proof. Preserve operation/check/push
records and handoff branches until normal cleanup; do not replay an old release
against an unknown added step plan. Keep admission closed throughout. No DB
rollback or ownership transfer to tick is required. Engine retirement, incoming
PRs, topology and bounded ADR0229 interruption proof remain later work.
