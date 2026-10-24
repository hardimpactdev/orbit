# Swamp repository-analysis adoption

Status: implementation for independent review, 2026-10-25. This changes the
official `orbit-groups` factory and its existing Gateway behavior. It has not
been deployed or exercised against a live group. Dependency adoption follows
independent acceptance of this slice.

Native source inspected read-only: Orbit
`0364765d6d41794f3f89d702e0f72cc54e954b07` (ORB2074/PR1505), following
ORB2068/PR1504. The parent verified native release 435 at that exact merge. Delivery is not live external analysis,
workflow or interruption proof. The earlier [contract](repository-analysis-contract.md)
and [native brief](gateway-repository-analysis-brief.md) retain their design
history; the source and limitations below describe this consumer.

## Existing native surface

`POST /api/v1/task-groups/{group}/analyze` accepts exactly
`{subtask, plan, plan_sha256, base, head, tree}` with `Idempotency-Key`.
It runs fixed tracked `bin/task-analyze` in the owned task VM, without Pi.
The result contains `{outcome, artifact:{content,bytes,sha256}, provenance}`.
`tasks.turn` accepts `analysis:{operation,plan_sha256}`. Gateway appends the
retained full manifest/report/provenance, checks freshness before first send,
and preserves its original assembled prompt/send identity during recovery.
Its combined prompt bound remains 100000 characters. Swamp sends the reference,
not a second reconstructed copy of the report.

Source contracts:

- [Manifest bounds, file paths and canonical digest](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisPlan.php#L14-L108).
- [Strict start fields](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Http/Requests/Tasks/StartTaskAnalysisRequest.php#L19-L47),
  [complete result/provenance](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisOperation.php#L243-L292),
  [repository report interpretation](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/docs/app/Documentation/DocsImpactReport.php#L12-L87).
- [Turn evidence and prompt bound](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisEvidence.php#L22-L68),
  [persisted prompt and ambiguous-send recovery](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L235-L323).
- [Actual tracked/untracked path union](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/docs/app/Documentation/DocsImpact.php#L217-L270),
  [fixed adapter](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/bin/task-analyze#L1-L116).

No arbitrary command, environment, model, generator-write flag or host checkout
access is added. Repository mappings and waiver policy remain in Orbit.

## Factory and plan ownership

The path is `provision → baseline → plan → analyze → implement → handoff →
analyze → review`. Rework and next-child paths return through `analyze`.
Analysis failure or unusable evidence parks for `analysis-retry`, then returns
to `plan`. This does not retry with a new key while the original operation is
pending. Existing handoff/review caps and retry approvals remain in force.

At `plan`, the planning owner records the declared `plan` artifact with the
existing factory `record_artifact` method. Its payload is:

```json
{
  "manifest": {
    "id": "feature-widget", "revision": 1,
    "source": {"brief_id": "tg-1234", "revision_sha256": "<64 lowercase hex>"},
    "asserted_by": "planning-owner", "complete": true,
    "paths": [
      {"change": "add", "path": "apps/gateway/app/Domain/Tasks/Widget.php"},
      {"change": "delete", "path": "deleted.txt"},
      {"change": "modify", "path": "docs/reference/tasks.md"},
      {"change": "rename", "from": "old.txt", "path": "renamed.txt"}
    ]
  },
  "plan_sha256": "<SHA-256 of canonical manifest>",
  "base": "<full fixed provision analysis base>",
  "repository": "<exact Project repository URL>",
  "branch": "<exact task workspace branch>",
  "docsSubtask": 1235
}
```

Placeholders above are explanatory, not valid wire values. `source.brief_id`
must equal the work item. `briefSource(group)` defines the exact source bytes:
canonical JSON of `{title,brief,tasks}`, with tasks ordered by position and
each task containing `{id,position,title,brief,deliverables}`. Board status and
assistance fields are excluded. `source.revision_sha256` hashes those UTF-8
bytes. `canonical` and `validatePlan` are exported for plan producers. Never
infer a complete inventory from prose or existing files.

The owner reviews all intended source, tests, documentation, generated outputs,
new files and both rename endpoints, then explicitly authorizes the existing
`plan-authorized` human gate. Fetch the actual current artifact before consent.
Pass `note=planConsentNote(payload)` to the existing factory `approve` method.
The exported helper returns `plan-v1:` followed by length-prefixed strings for
`[plan_sha256, base, repository, branch, String(docsSubtask)]`; lengths count
Unicode code points. The manifest digest covers every manifest field and is
recomputed by `validatePlan` before any native request. The closed plan schema
and remaining tuple fields bind the complete plan payload, including scope.
Identical content retains its identity; any changed manifest or scope needs
its own explicit consent.

The official `human-approval` gate still enforces approval/rejection and cycle
rules. The additional official CEL gate selects the **last decision** for the
current stage/cycle in this run. That decision must approve the exact current
payload with the matching note. Historical matching notes cannot supply consent
behind a later rejection, generic approval, wrong-scope approval or approval of
another plan. After rejection, restoring v1 behind an exact v2 approval stays
closed until fresh exact v1 consent. Generic approval after rejection also stays
closed. A generic approval or driver preapproval cannot authorize an unspecified
future plan. Replacing v1 with v2 in the same cycle requires exact v2 consent.
If no `plan_authorized` history exists, official CEL can report a missing-key
diagnostic; the gate still refuses and exact approval recovers. PR9's earlier
all-policy-message evidence claim was too broad. This nonblocking diagnostic
does not weaken or change the accepted consent rule.

This ordering reuses installed official factory `2026.10.13.1` and its
`models/_lib/run_data.ts`:
lines 349–369 load every approval version in ascending version order, and lines
375–395 exclude records from prior run eras. `models/_lib/gates.ts:217–223`
filters by current stage/cycle and reads the last decision for the human gate;
lines 346–365 expose those ordered records to CEL. The new predicate filters
the same way before selecting the last record. It does not compare timestamps
or search backward for a matching approval. A return to `plan` increments its
cycle, so an earlier cycle cannot authorize it. The factory versions artifacts
and approvals using its existing records; there is no second ledger or factory
fork. This implementation grants no approval outside isolated simulated fixtures.
Swamp validates the closed manifest, digest, brief revision, UTF-8 byte ordering,
unique safe file endpoints, 256-entry/32-KiB bounds, and cumulative revisions
before an analysis start. Native validation also checks tracked delete/rename
sources and actual symlink containment inside the VM.

`docsSubtask` identifies the existing documentation child. All children retain
ordinary author, handoff and reviewer turns, including a no-docs case. A feature
child requires that documentation child to be completed and its existing native
reviewer operation to approve this exact plan digest. The pointer is carried in
the existing `review` artifact; Swamp reads that operation's group, role, child,
analysis digest and approved receipt before dispatch. It creates no separate
documentation approval ledger. Reviewer prompts retain semantic coverage and
waiver judgment. A machine report grants neither.

A scope or brief change requires a higher cumulative manifest revision. Keep all
previous endpoints; never silently add omitted actual paths to the plan. A new
revision needs documentation review for that revision; when its former docs
child has ended, the owner must arrange an appropriate new docs child through
the normal task lifecycle and identify it in the authorized revision. This
client does not reopen or create children. Base/repository/branch changes are
refused; there is no client base-adoption mechanism.

## Evidence and refusal behavior

The existing stage Job persists the request/key before POST, then retains the
operation ID. A lost reply resends exactly that request and key. Once accepted,
ticks only read that operation. Report bytes, digest, full provenance, plan and
candidate are retained in the Job and factory `analysis` artifact. There is no
tail parser, report shortening, second scanner or second worker.

Candidate HEAD/tree come from native baseline/check and turn results. Passed
handoff uses `commit` as HEAD, rather than the check's pre-commit `head`.
Reviewer completion uses `after_reset`; author completion uses `after`.
This also applies to proven terminal failed/cancelled turns with supported
results: retain a **blocked** change/review artifact bound to the actual
operation, group, child, role and thread. Preserve failed evidence and the
existing retry approval; never approve a receipt or complete a child from that
result. Keep attempt counters and the existing author thread. Retry analysis
uses the terminal candidate, and a turn refuses analysis for an older candidate.
Missing, malformed or foreign result identity/snapshots produce a blocked
artifact with `candidate:null`, which shadows the old baseline and parks
reanalysis. Pre-dispatch refusal has no terminal result to adopt. Waiting or
unknown termination releases no snapshot.

The delivered [failed/cancelled turn result](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L119-L126)
contains the [captured after/reset snapshots](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L336-L363).
Terminal check results receive the same scoped blocked treatment using their
[recorded commit/head/tree](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskCheckOperation.php#L345-L347).
Their failed evidence still requires the existing check retry gate. None of
these records infer termination or lease release from elapsed time.
Gateway remains authoritative for fresh whole-workspace snapshots, the pinned
entrypoint, target ownership and leases. Swamp also validates group/child,
workspace Instance/Node, repository/branch, fixed base, plan ID/revision/digest,
operation/execution IDs, before/after snapshots, timings, exit status and zero
mechanical LLM calls.

| Observation | Consumer result and next action |
| --- | --- |
| Valid no-docs, empty errors, passed applicable checks | `ready`; supply complete native evidence to the current turn; retain docs-first semantic review |
| Owned public surface with required pages/reasons | `action_required`; docs child may write; feature child still requires current-plan docs review |
| Exit 0 with errors; unowned surface; failed/missing/stale generator check | `blocked`; no author/reviewer dispatch |
| Missing, malformed, duplicate-key, truncated, wrong-digest/schema/candidate/plan/workspace report | `invalid`; retain the received full result as unusable; no dispatch |
| Report changes HEAD/tree | `changed`; no dispatch; reconcile under native ownership |
| Report path union contains an actual path outside the authorized plan | `plan_required`; retain all omitted paths and require a revised cumulative plan |
| Plan/input changes during an admitted operation | Drain/read the original operation; keep its original request and turn prompt/send identity; retain terminal evidence as stale/blocked; no new operation under that key |
| Native waiting, cancel requested, unknown termination, or competing target owner | Stay running; obey poll delay; never infer lease release from elapsed time or manufacture a second execution |
| Native terminal failed/cancelled or typed unavailable/oversize/prompt-too-large refusal | Retain typed code and complete supported operation read; park; no green fallback |

The actual-file handoff docs gate still runs once through the normal native
check. Post-handoff analysis compares its complete path union with the plan
before reviewer dispatch. Planned paths do not replace actual tracked/untracked
files. The source checker still owns generator checks and the existing semantic
waiver mechanism. Required write-mode generation has no added native primitive
in this slice and must block rather than use an agent/host workaround for
mechanical execution.

## Demonstrated native limitations

The report bound remains **65536 UTF-8 bytes**. The earlier actual 104-path
216325-byte report (162390 compact bytes) remains unusable. Nothing here claims
that all Orbit requests fit. The isolated overflow fixture retains its complete
69452-byte producer envelope separately as `overflow.complete.UNUSABLE.json`;
it never feeds a reduced report to the API or reconstructs evidence from stderr.

ORB2074 delivered the two previously missing public contracts. This consumer
now adopts them; there is no remaining native prerequisite for these fields.

Successful provision returns `{instance_id,node_id,starting_commit,analysis_base}`.
The nullable `analysis_base` is exactly `{base,instance_id,repository,branch}`.
[The owning Tasks contract](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/reference/tasks.md#L97-L107)
and [native validator](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisBase.php#L12-L26)
require a full lowercase 40/64-hex base, a positive matching Instance ID, and
valid recorded Git repository/branch identities. Swamp rejects credentials,
queries/fragments, unsafe refs, extra pin fields and incomplete identity.
`starting_commit` remains separate and may be null or differ from the fixed base.

The existing `workspace` artifact retains the entire validated pin, Node,
starting commit, group and provision operation ID. Swamp verifies the returned
operation's ID/kind/group before recording a ready workspace. Missing, null,
malformed or wrong-Instance pins park the Swamp stage as failed/blocked while
retaining native `operationStatus:succeeded` and its full public result. This
is a consumer refusal, not a claim that native provision failed or its lease
was released. Baseline cannot advance from this blocked workspace. Existing
retry approval remains required; it does not authorize guessing or recomputing
a pin. Old results without the field remain unavailable, with no backfill.

Before every first analysis/turn request, the authorized plan's base/repository/
branch must equal the retained pin, whose Instance must equal the workspace
Instance. Analysis provenance must also name the retained Instance and Node.
Same-key recovery keeps the original request and pin; a changed binding marks
in-flight work invalidated and drains that original operation. It does not
resend a changed prompt or start a replacement execution. A later admission
with a missing or different pin fails closed. There is no fallback to
`starting_commit`, today's Project settings, private checkpoints or host refs.
The [provision implementation](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskProvisionOperation.php#L151-L162)
records the pin once; the public terminal result preserves it independently of
workspace cleanup and replay.

A qualifying failed `tasks.analyze` operation carries exactly
`{schema:1,error:{code,required_bytes}}` as its non-null `result`; it remains
**failed**. Swamp validates the matching operation ID/kind/group, original
complete request input, failed step, result schema and original `error_code`.
The [owning failure contract](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/reference/tasks.md#L289-L335)
and [typed validator](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisFailure.php#L12-L42)
permit only `tasks.analysis_report_too_large` and `tasks.analysis_unavailable`.
Overflow size is null (unknown) or a safe JSON integer from 65537 through
9007199254740991. Unavailable tooling always has null size. Swamp retains the
validated object in existing failed evidence as `failure`, with `requiredBytes`
and a local availability label; no analysis artifact or success is created.

Missing old failure results retain the original code and unknown size.
Malformed, mismatched, foreign or stale-request metadata retains the received
operation as unusable, with a validation diagnostic and null trusted size.
Cancelled, running and waiting operations supply no failed-analysis metadata.
No free-text exception, stderr, tail, partial artifact or private state is a
metadata source. The [native terminal checkpoint/release handling](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisOperation.php#L142-L193)
remains authoritative for terminal proof and ownership. A failed public result
never authorizes the client to release a lease or infer remote termination.

The four recorded PHP SDK public fixtures are copied byte-for-byte under
`tests/fixtures/repository-analysis-public/`, with source SHA and content hashes.
They demonstrate divergent `starting_commit`/base, unavailable pin, overflow
216325 and missing tooling. Unit simulations rebind operation/request identity;
factory fixtures also bind repository/branch to their synthetic workspace.
The recorded files remain byte-identical, and all simulated results retain
the delivered nested shape.
[Operation reads](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/reference/operations.md#L79-L101)
remain the API/CLI/SDK/MCP boundary; no new endpoint or generated request schema
is introduced in Swamp.

## Isolated acceptance and rollout boundary

`tests/repository_analysis_producer_test.ts` copies the exact merged adapter,
plan/report validators and real DocsImpact into disposable Git repositories.
It checks their committed source hashes before execution. No native suite or
source checkout code is run in place. Generator scripts in these fixtures are
test doubles that accept only `--check`; the scanner and adapter are real.

| Acceptance | Evidence class |
| --- | --- |
| No-docs and public-contract owning page; planned new file; actual untracked/delete/rename endpoints; unresolved owner and exit0+errors | Real local producer integration |
| Missing/failed/stale generator checks; fixed read-only check arguments; tooling unavailable; full oversized evidence and exact required size at producer | Real local producer integration |
| Omitted actual path remains visible in actual-file handoff gate; missing documentation fails; existing explicit waiver mechanism retained | Real local checker gate integration; waiver meaning still requires review |
| Full byte/schema/hash/provenance validation; wrong/missing/malformed/truncated report; changed workspace/plan | Consumer tests using bounded transport fixtures |
| Same-key lost reply/restart, pending/cancel/competing-owner boundary, original request recovery, typed unavailable/oversize/prompt-bound refusals | Client transport simulation; not a remote-process interruption proof |
| Factory analysis routing, semantic docs-first gate, normal rework/caps and child sequencing | Real official factory/driver with fake Gateway in a disposable Swamp repository |
| Public pin and failed-result metadata; divergent base; known/unknown size; malformed/foreign data and recovery | Exact delivered SDK fixtures, consumer simulations and official factory/driver with fake Gateway |
| Live external analysis workflow and native worker/orchestrator interruption | **Not proved**; no live group or kill experiment was authorized |

Producer evidence is retained under the printed `/tmp/swamp-analysis-evidence-*`
directory: complete stdout/stderr, overflow envelope, handoff gate/waiver output,
source file hashes, durations and zero mechanical LLM calls. Planning, writing
and review remain explicit judgment steps, outside this fixture. There is no
comparable before-workflow measurement and no savings claim.

Current ORB2074 adoption validation: 44 affected tests passed in 2s, including
the real checker/adapter, public fixture parity, failure metadata, immutable
pins, replay/recovery, malformed/foreign inputs, candidate/plan invalidation,
semantic review guards and the actual-file handoff gate. Seven public tests
also passed after the lint-only control-character check rewrite. Lint passed
for ten changed TypeScript files; Gateway/driver type checks passed.

The new official-factory/driver test passed five simulated groups in 50s.
It retained a divergent public base through provision and analysis into the
author's analysis reference. A null pin prevented baseline/analysis. Known
overflow 216325, unknown-size overflow and unavailable tooling each retained
failed metadata and prevented turns. Evidence:
`/tmp/swamp-public-analysis-factory-9fdbdffbd2ca234e`.
The first fixture run refused inconsistent Node identities;
the fake transport was corrected to report the provisioned Node. No product
guard, timeout, assertion, tick/call bound or report limit was relaxed.

Fresh real-producer evidence is
`/tmp/swamp-analysis-evidence-ced048aaa5dea940`, copied from exact native git
objects at `0364765d6d41794f3f89d702e0f72cc54e954b07`; moving checkout HEAD is
not the source authority. Analysis durations were 56.36 ms (no-docs), 61.07 ms
(public contract), 73.46 ms (actual inventory), 59.86–64.34 ms (generator/unresolved
states), 28.31 ms (unavailable tooling), and 467.45 ms (overflow rejection).
Mechanical LLM calls were zero. Complete unusable overflow: 69452 bytes, SHA-256
`c4a5ef034dcf06cba987f6ccd16c79e88a7f2abee523209e6c800370a63b3727`.
Prior complete reports remain retained; nothing was truncated or admitted.

Current source hashes, readback, logs, responses and validation are retained at
`/tmp/swamp-public-analysis-adoption-Pbnqok`. PR9's independently accepted R3
consent/cycle and terminal retry proofs remain valid context; the consent gate
and failure-artifact repair are unchanged. This run does not claim another
execution of their heavy factory suites or a live external workflow.

Initial validation at `cd257d20213ebd6098deefbe49e242c28b6fec17`: 64 focused
producer/client/driver/assistance tests passed; the official-factory test passed
its 13 simulated scenarios in 3m31s with the original 70-tick/call bounds.
That PR9 evidence is retained; its later correction runs did not repeat the
unchanged full suite.

R1 correction validation: 36 affected consumer/stage tests passed. Two targeted
real-factory tests passed in 55s (31s consent, 24s failed-author recovery).
Consent reproduced both generic-preapproval and v1-approved/v2-replaced defects
before the gate change, then proved zero analysis/turn starts until exact v2
consent. Recovery proved the failed author parks without direct dispatch until
`implement-retry` approval, then analyzes T1 and reuses the author thread with
attempt 2. Client simulations also cover cancelled turns, absent/malformed or
foreign results, reviewer reset identity, stale evidence, and same-key lost
replies/restart. Native terminal/check ownership was not fault-injected.
The original 13-scenario test is explicitly filtered out of this targeted run;
no test timeout, assertion, call or tick limit was relaxed. Current lint, type
checks, source citations and local links were checked.

Retained R1 targeted evidence on Beast:
`/tmp/swamp-plan-consent-evidence-6a0bf1a9770aa493`,
`/tmp/swamp-failed-author-evidence-46b1bef256e135ff`, and
`/tmp/swamp-pr9-r1-r2-{focused,factory}.log`. They retain gate status, the exact
admitted request, failed stage evidence/artifact and recovery operation identities.
These are real official factory/driver runs with fake Gateway transport, not
live approval, failure, cancellation or interruption proof.

R2 correction validation: the new real-factory regression failed both rejection
sequences against the old gate, then passed with the authoritative-decision
rule above. The two consent tests passed in 1m45s (32s existing consent controls,
73s new regression with two scenarios). They prove zero analysis/turn starts
until fresh exact consent after generic reapproval or rejected-v1 restoration
behind approved v2. Each scenario then starts exactly one matching analysis.
Rejection alone, stored generic preapproval, wrong-scope notes, other-stage
consent and a generic decision superseding fresh exact consent all stay closed.
No test, tick or call bound changed. Current test type checking, lint, native
source line bounds, local links, JSON example and whitespace validation passed.
At that correction, the R1 retry repair and producer/consumer code were
byte-identical to the independently reviewed R2 head; that proof was reused.
Current consent evidence is `/tmp/swamp-plan-consent-evidence-be188702fd6684b5`
and `/tmp/swamp-plan-decision-evidence-4739e9c4bf47c53f`. R2 responses, red/green
logs, exact official-source hashes and validation are retained on Beast at
`/tmp/swamp-pr9-r2-fix`. Those were PR9's bounded results before ORB2074 adoption.

The prior PR9 producer run retained evidence at
`/tmp/swamp-analysis-evidence-39d3b35d20cfdef9` on Beast. Fixed-command durations
were 56.57 ms (no-docs), 59.78 ms (public contract), 73.88 ms (actual inventory),
57.47–62.25 ms (unresolved/generator states), 26.79 ms (unavailable tooling),
and 468.45 ms (overflow rejection). Mechanical LLM calls were zero in every
case. The retained complete overflow envelope is 69452 bytes with SHA-256
`d0f168fcfbfc7324a56a64a29f600f25736b9767d9b3e1b20fb6ecc406c10fe9`.
These are isolated fixtures, not timings or fit guarantees for the full Orbit
repository. Test source pins and recreates the evidence; `/tmp` is local retained
evidence rather than a published artifact service.

Focused commands (use already-installed tools; no package install):

```sh
~/.swamp/deno/deno test --allow-all tests/repository_analysis_test.ts tests/repository_analysis_public_test.ts tests/repository_analysis_producer_test.ts tests/gateway_stage_test.ts
SWAMP_TEST_EXTENSIONS=/path/to/existing/pulled-extensions ~/.swamp/deno/deno test --allow-all --filter 'real factory consumes public' tests/factory_routing_test.ts
~/.swamp/deno/deno check extensions/behaviors/orbit_gateway.ts extensions/behaviors/orbit_driver.ts
```

The factory fixture copies already-installed official extension sources into
its throwaway repository. It does not modify their source checkout, runtime
data or services. Existing tick/call/test limits are retained.

Parent owns independent exact-head review, merge and any rollout. Before rollout,
preserve operation evidence and reconcile any in-flight entry. New factory entries need
an authorized typed plan and docs child. Existing entries without evidence fail
closed; no implicit migration dispatches an author. Rollback is a normal reviewed
revert of this consumer/model change after native in-flight work reaches proven
terminal state. Keep admitted keys/results; never clear leases or restart work
under a replacement identity to make rollback proceed.
ORB2074 is delivered; this change completes its isolated consumer adoption for
independent acceptance. It neither deploys Swamp nor establishes live workflow
proof. Dependency adoption follows independent acceptance as a separate serial
slice; it is not implemented here.

External review-and-merge/topology admission remains closed. ADR0229's bounded
worker/orchestrator interruption proof, engine retirement, incoming-PR support,
write generators and dependency updates remain separate work. No live proof
group, API invocation, service restart, deployment or lease mutation occurred.
