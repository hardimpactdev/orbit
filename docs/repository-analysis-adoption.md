# Swamp repository-analysis adoption

Status: implementation for independent review, 2026-10-25. This changes the
official `orbit-groups` factory and its existing Gateway behavior. It has not
been deployed or exercised against a live group. Dependency adoption follows
independent acceptance of this slice.

Native source inspected read-only: Orbit
`8b6fb914c6fbab3abd70459afe832f7307e4d5c6` (ORB2068/PR1504). The parent reports
release 434 delivered that exact merge. Delivery is not live external analysis,
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

- [Manifest bounds, file paths and canonical digest](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskAnalysisPlan.php#L14-L108).
- [Strict start fields](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Http/Requests/Tasks/StartTaskAnalysisRequest.php#L19-L47),
  [complete result/provenance](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskAnalysisOperation.php#L231-L277),
  [repository report interpretation](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/docs/app/Documentation/DocsImpactReport.php#L12-L87).
- [Turn evidence and prompt bound](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskAnalysisEvidence.php#L22-L68),
  [persisted prompt and ambiguous-send recovery](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L235-L323).
- [Actual tracked/untracked path union](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/docs/app/Documentation/DocsImpact.php#L217-L270),
  [fixed adapter](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/bin/task-analyze#L1-L116).

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
rules. An additional official CEL gate requires a matching note in this run's
approval records for the current stage/cycle. A generic approval or driver
preapproval cannot authorize an unspecified future plan. Replacing v1 with v2
in the same cycle leaves the transition closed until v2 is explicitly approved.
The factory versions the artifact and approval using its existing records;
there is no second ledger or factory fork. This implementation grants no
approval outside its isolated simulated fixtures.
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

The delivered [failed/cancelled turn result](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L119-L126)
contains the [captured after/reset snapshots](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskTurnOperation.php#L336-L363).
Terminal check results receive the same scoped blocked treatment using their
[recorded commit/head/tree](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskCheckOperation.php#L345-L347).
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

The parent confirmed one narrow native follow-up for validated public failure
metadata and the recorded provision base/workspace identity. It follows normal
deduplication and docs-first design review. Adoption acceptance stays incomplete
until those supported fields arrive; this client adds no guessed wire fields.

Full failure-metadata acceptance is currently missing.
[TaskAnalysisReport](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskAnalysisReport.php#L56-L77)
validates `{schema:1,error:{code,required_bytes}}`, then throws with the size in
its message. The operation saves that rejection in
[private terminal state](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskAnalysisOperation.php#L155-L168).
[Public OperationData](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Data/Operations/OperationData.php#L18-L38)
exposes `error_code` and `result`, with no typed failure size/details. Failed
analysis returns no usable result. Swamp therefore records `requiredBytes:null`
and `diagnosticsAvailability:"public operation fields only"`. It preserves the
code and operation, and refuses progression. It does not invent unsupported
wire fields or parse a diagnostic tail. The parent owns a narrow native change
to expose validated safe failure metadata through normal API/CLI/SDK contracts;
this PR does not claim that requirement is satisfied. The consumer needs the
validated code, required size when applicable, and safe availability diagnostics
through the supported public operation read, with the same terminal-proof
ownership semantics. The native follow-up defines their exact wire names.

Provision also stores the fixed analysis base privately, while its public result
returns `starting_commit`. These can differ: the native base is the merge base
of the provisioned starting commit and local `origin/main`
([provision pin](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/app/Domain/Tasks/TaskProvisionOperation.php#L150-L164),
[resolver](https://github.com/hardimpactdev/orbit/blob/8b6fb914c6fbab3abd70459afe832f7307e4d5c6/apps/gateway/resources/tasks/check#L362-L370)).
The plan must supply the exact known base; this consumer neither assumes equality
nor discovers a hidden pin via host access. Native refuses a mismatch. A caller
without that identity must park; exposing it is a further small prerequisite
for adoption with a trustworthy recorded base/workspace binding. A future
consumer must use that supported immutable provision identity, including its
Instance/repository/branch binding, rather than derive it from `starting_commit`
or accept an unverified planning assertion as provenance. This PR exercises
known isolated fixture identities only; real adoption waits for the native
follow-up. No base-update API is assumed.

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
| Live external analysis workflow, native worker/orchestrator interruption, required_bytes on public failure read | **Not proved**; last item needs native transport follow-up |

Producer evidence is retained under the printed `/tmp/swamp-analysis-evidence-*`
directory: complete stdout/stderr, overflow envelope, handoff gate/waiver output,
source file hashes, durations and zero mechanical LLM calls. Planning, writing
and review remain explicit judgment steps, outside this fixture. There is no
comparable before-workflow measurement and no savings claim.

Initial validation at `cd257d20213ebd6098deefbe49e242c28b6fec17`: 64 focused
producer/client/driver/assistance tests passed; the official-factory test passed
its 13 simulated scenarios in 3m31s with the original 70-tick/call bounds.
That unchanged producer/driver/assistance evidence is retained, rather than
claiming a new full-suite run for the corrections below.

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

Current targeted evidence on Beast:
`/tmp/swamp-plan-consent-evidence-6a0bf1a9770aa493`,
`/tmp/swamp-failed-author-evidence-46b1bef256e135ff`, and
`/tmp/swamp-pr9-r1-r2-{focused,factory}.log`. They retain gate status, the exact
admitted request, failed stage evidence/artifact and recovery operation identities.
These are real official factory/driver runs with fake Gateway transport, not
live approval, failure, cancellation or interruption proof.

The final producer run retained evidence at
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
~/.swamp/deno/deno test --allow-all tests/repository_analysis_test.ts tests/repository_analysis_producer_test.ts tests/gateway_stage_test.ts
SWAMP_TEST_EXTENSIONS=/path/to/existing/pulled-extensions ~/.swamp/deno/deno test --allow-all tests/factory_routing_test.ts
~/.swamp/deno/deno check extensions/behaviors/orbit_gateway.ts extensions/behaviors/orbit_driver.ts
```

The factory fixture copies already-installed official extension sources into
its throwaway repository. It does not modify their source checkout, runtime
data or services. Existing tick/call/test limits are retained.

Parent owns independent exact-head review, merge and any rollout. Before rollout,
resolve and adopt the supported native metadata fields, preserve
operation evidence and reconcile any in-flight entry. New factory entries need
an authorized typed plan and docs child. Existing entries without evidence fail
closed; no implicit migration dispatches an author. Rollback is a normal reviewed
revert of this consumer/model change after native in-flight work reaches proven
terminal state. Keep admitted keys/results; never clear leases or restart work
under a replacement identity to make rollback proceed.
Full adoption acceptance, rollout and dependency adoption remain blocked until
those native fields are delivered and adopted. Parent tracks that prerequisite
through ORB2074 (docs2075, implementation2076); this PR does not implement it.

External review-and-merge/topology admission remains closed. ADR0229's bounded
worker/orchestrator interruption proof, engine retirement, incoming-PR support,
write generators and dependency updates remain separate work. No live proof
group, API invocation, service restart, deployment or lease mutation occurred.
