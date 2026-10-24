# First native slice: fixed repository analysis and evidence

Implement the [repository-analysis contract](repository-analysis-contract.md)
in `hardimpactdev/orbit` after the parent accepts this design. Baseline source:
`2187129c471c20a34b6c91d5e1cb6c34878b8742`. Re-read the actual implementation
base and report material drift. This Swamp PR authorizes no runtime edits.

## Smallest useful boundary

Deliver keyed `tasks.analyze` for a fixed repository-owned read-only entrypoint,
complete bounded results, and the optional analysis reference on existing
`tasks.turn` with validation before first dispatch. This lets later Swamp adoption
run the real checker without Pi and safely hand evidence to current turns. It
does not deliver the docs workflow or open exceptional group admission.

The source already has the scanner, docs handoff gate, task VM, detached run-ID
supervision, operation worker, lease and Pi prompt. The demonstrated gaps are
typed plan input, a non-baseline fixed analysis profile, full report transport,
and stale-safe consumption. Reuse those components; add no scanner, artifact
service, worker, arbitrary command API, agent role or separate approval ledger.

Write docs first in the implementation PR:

- Amend ADR0229's operation table, Check/Turn distinction, sender inventory and
  rejected generic-command rationale for the fixed analysis profile. Preserve
  external review-and-merge/topology refusals and unfinished interruption proof.
- Explicitly amend ADR0225's detached-check supervision boundary for this narrow
  read-only profile. Retain its Node-script growth freeze and command migration
  direction. This is a policy addition, not a claim that the current ADR already
  authorizes command-only task work. Do not add an exception to the script ratchet.
- Update Tasks/Operations references and contributor guidance for manifest/report
  semantics. Keep Orbit mappings, payload schema and waivers in the repository.
  Generate CLI/SDK/OpenAPI/MCP contracts from the one native schema.

Expected files/boundaries (names for new files are proposed):

| Area | Narrow change |
| --- | --- |
| `bin/task-analyze`, `apps/docs/app/Documentation/DocsImpact.php`, existing `DocsImpactCommand` and docs tests | Thin fixed stdin adapter and payload schema validation around the existing scanner. Do not duplicate mappings or alter final gate/waivers. No generator write mode |
| `apps/gateway/app/Http/{Requests/Tasks,Controllers/Api/TaskGroupsController.php}`, `Data/Tasks`, `routes/api.php` | Strict bounded plan/start DTO and endpoint, plus optional turn analysis reference; existing access/replay/error envelopes |
| `app/Domain/Tasks/TaskAnalysisOperation.php`, `TaskOperations`, `Domain/Operations/OperationKinds.php` (Gateway-relative) | One new conforming kind, existing target lock/lease, persisted workspace/analysis/report steps; no board transitions or candidate check row |
| Existing `TaskCheckRunner`, `RemoteTaskCheckRunner`, `resources/tasks/check`, workspace lease/recovery | Extend existing supervision with the fixed analysis profile and complete bounded run-result read. Reserve identity before dispatch; store/recover exact bytes. Keep ordinary check behavior intact |
| Existing `TaskTurnData`, `TaskTurnOperation` and generated clients | Resolve same-child stored evidence, snapshot/entrypoint comparison under turn lease, full prompt injection and combined size guard before send; preserve same-send recovery |
| Existing operation state/result storage | Retain bounded raw bytes, digest and typed provenance with keyed record; no duplicate durable artifact table or log-tail parsing |
| Focused API/domain/runner/docs fixtures | Prove the matrix below at real resume/dispatch boundaries; shared JSON examples/hash fixtures across generated consumers |

Use existing programs and fixed argument transport. No new Gateway inline/file
Node scripts, renamed script labels, hidden code in data, or new execution
framework. Keep the current script-ratchet check passing without relaxing it.
If reuse cannot meet a required result/recovery boundary, report that exact
missing primitive to the parent before widening the task. Do not silently wait
for a complete Node-agent redesign or bypass its decisions.

## Acceptance matrix

These are requirements for the **future implementation**, not tests run by this
docs PR. Extend existing `apps/docs/tests/Feature/DocsImpact{,Gate}Test.php`
fixtures and add focused `TaskAnalysisOperationTest`/turn/runner cases. Use the
actual repository checker in isolated Git fixtures, not a fake verdict scanner.

| Case | Required evidence |
| --- | --- |
| Private/internal change with no affected public surface | Actual full `no_docs_change` report, empty errors, deterministic path ordering; no Pi send, commit, push or stage advance |
| Public API/CLI/config surface, proposed new file, untracked new file | Existing owning pages/reasons/generator statuses retained; planned and actual paths both included, unknown owner blocks |
| Deleted/renamed source | Base surfaces remain visible, both rename ends included; preserve existing removed-surface policy, including unowned deleted migration behavior |
| Unresolved/unowned surface; exit 0 with errors | Whole report parsed; blocked outcome, never no-docs success |
| Missing/failed/stale generator or unavailable analyzer/tool | Distinct blocked/unavailable evidence, no write-mode generator and no LLM/host fallback |
| Missing/malformed/duplicate-key/trailing/truncated/oversized JSON | Strict schema/size failure; no tail reconstruction, partial artifact or send |
| Wrong group/child/execution/plan/base/HEAD/tree/entrypoint | Refuse as invalid/stale before use; artifact hash and byte length verified |
| Traversal, glob, directory, symlink escape, bad action/rename, duplicate path, incorrect digest, overflow manifest | Refuse entire request before dispatch; no normalized-away unsafe input |
| Changed plan/source revision or base, even unchanged tree | New revision/digest/key; old replay stays historical and cannot satisfy latest Swamp plan gate |
| Workspace changes during analysis or between result and first turn send | Changed/stale outcome; no send. Include untracked-only change, same HEAD/different tree and entrypoint change |
| Same key after lost start/reply or worker restart | Same operation/run/report bytes; one scanner start. Changed input conflicts. Missing start tombstones before retry decision |
| Cancel before/after dispatch; unreachable/unknown descendants | No queued dispatch; started run stops/reconciles before ownership ends. Unknown work fences competing author/check/publish |
| Concurrent author/check/operator lease | One group holder and lease; wait/refuse without concurrent execution; operations worker can serve another target |
| Turn consumption and interrupted send | Full plan/report/provenance in persisted prompt; exact candidate revalidated under lease; original Pi send key recovered, no second turn |
| Combined evidence/prompt overflow | Explicit refusal before send; neither report nor manifest truncated |
| Actual file omitted from plan at handoff | Repository gate still scans it; later Swamp plan gate refuses progression and demands revision/reanalysis/docs review |
| Machine no-docs but semantic docs change; branch-local waiver | Reviewer still judges meaning and confirms waiver; generator checks remain required; no new approval ledger |
| Existing baseline/no-policy check and ordinary turn | Existing request/result/recovery semantics unchanged when no analysis reference is supplied |

Fault injection must cover reservation → dispatch → terminal artifact persistence
→ Gateway result persistence → turn preparation/send. Prove retained byte identity
and single invocation across those boundaries; a happy-path mock is insufficient.
Use local isolated fixtures/fakes and existing supervision tests. No live group,
fleet change or worker/orchestrator kill is part of this implementation acceptance.
Live bounded ADR0229 interruption proof remains separately authorized work.

Record analysis duration and mechanical LLM call count (zero). Record existing
planning, prose/feature author and review calls separately. Capture a comparable
before measurement before claiming savings; label unavailable measurements.
Run focused affected checks and required repo documentation/generated-contract
validation for that runtime change. This design PR runs docs validation only.

## Delivery and rollback

Return one ready Orbit PR with exact head/base, native contracts, focused test
results and remaining guards to the parent. The parent owns independent review,
approval, merge and deployment. No author grants API approval or deploys.
Rollout exposes the new operation additively; existing callers behave as before.
Swamp adoption waits for delivered native contracts and its own reviewed change.

Before rollback, stop new analysis requests and reconcile all active analysis or
evidence-bound turn owners. Restore a compatible release only after added steps
are terminal. Preserve keyed operations, reports, provenance and turn identities;
do not replay unknown new step plans on an old binary. No live mode change, DB
rollback or tick ownership transfer is needed.

Later Swamp adoption adds an explicit analysis/plan gate around existing turns,
including next-child/rework paths and actual-vs-planned handoff comparison. A
separately authorized docs-workflow proof records initial/final reports, plan
revisions, docs review, actual gate and cleanup. Write-mode generators need a
separate demonstrated deterministic mutation contract if required. Dependency
adoption follows that proof and retains registry-error/constraint/major-approval/
input-revision/lockfile/review rules. Final review/merge, topology, incoming PRs,
bounded interruption proof and engine retirement remain separate work.
