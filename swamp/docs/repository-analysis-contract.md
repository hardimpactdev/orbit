# Pre-implementation repository analysis

Status: independently reviewed PR8 design, followed by native ORB2068/PR1504
at `8b6fb914c6fbab3abd70459afe832f7307e4d5c6`. The historical baseline table and
citations below describe the gaps before that implementation. `tasks.analyze`
and the optional turn reference now exist. The [Swamp adoption](repository-analysis-adoption.md)
documents the implementation, exact current source, isolated proof and
public metadata adoption after ORB2074/PR1505 at
`0364765d6d41794f3f89d702e0f72cc54e954b07`. This historical contract grants no
live rollout or exceptional admission.

Run deterministic repository analysis on the complete typed plan in the group's
actual task VM before a feature author edits runtime code. Keep its full bounded
report and give it to existing author/reviewer turns. LLMs plan, judge meaning,
write prose/code, and review. Extraction, generation, validation and evidence
collection use scripts, with zero LLM calls in the analysis operation.

## Source and delivery boundary

Orbit source inspected read-only: `2187129c471c20a34b6c91d5e1cb6c34878b8742`.
Swamp source: `91bf8a0d392395e490b09d617fe9e265785e571b` (merged PR7).
All Orbit citations below pin the former SHA; Swamp citations pin the latter.

The parent reports ORB2064/PR1503 delivered at that Orbit merge, main CI
`40085180992` PASS, native release 433 verified, smoke 7 PASS, tick confirmed,
group 2064 completed, and Instance 633 naturally destroyed. That is bounded
delivery acceptance, **not** live external `tasks.check` interruption proof.
This design repeats no live check. Live Swamp remains `de462f6`; a docs merge
needs no service change. Live assistance adoption and ADR0229 interruption proof
remain separate unproven items. Create no group to manufacture either proof.

Material change since PR7's pinned `d6bce776…`: hosted CI in `tasks.check` [check]
and shared reviewer/publisher eligibility [candidate] are now implemented. The earlier
[handoff-CI brief](gateway-handoff-ci-brief.md) describes that prior design;
it is not the next implementation task. Review-and-merge and topology admission
remain closed [adr]. This proposal changes neither guard.

| Existing at the historical pinned source | Consequence / proposed addition at design review |
| --- | --- |
| Contributor guide requires all planned paths, including new files; repository owns mappings and waivers [policy] [repo-policy] | Retain policy in the repo; add a typed inventory, not path extraction from prose |
| `DocsImpact::report` unions planned, tracked-diff and untracked paths, normalizes/deduplicates/sorts, and compares current/base surfaces [scanner] | Reuse it without copying its mappings into Gateway or Swamp |
| `DocsImpactCommand` supports base, repeated paths and gate; report mode returns exit 0 even with `errors` [command] | Parse and validate the entire JSON; exit 0 alone is insufficient |
| `bin/review-check` runs `bin/docs-impact --gate --base` on actual candidate paths [gate] | Keep this handoff gate authoritative; preflight evidence cannot replace it |
| `tasks.check` accepts only `{subtask, phase}`; baseline refuses once a group implementer started [check-request] [check] | Do not add planned paths to baseline or pretend it is per-child analysis |
| `tasks.turn` accepts a prompt and reviewer check; no analysis reference [turn-request] | Small proposed reference binds stored analysis to the dispatch snapshot |
| Detached checks already have run-ID start/recovery/read/cancel and full-workspace snapshots [runner] [snapshot] | Extend that owned supervision with a fixed read-only analysis profile |
| Check output is a bounded/redacted tail; no full analysis artifact in its result [tail] [reading] | Add complete typed result transport, never parse a tail as JSON evidence |
| `orbit-groups` moves baseline → implement [factory], and later review → next implement [next-child]; prompts use briefs/feedback [prompts] | Later adoption must add explicit analysis and plan gates before every affected author entry |

These request schemas, runner return fields and factory transitions demonstrate
the missing capability. No live API experiment is needed. A normal handoff check
already solves final docs enforcement. An LLM turn running the scanner would
consume a model call for mechanical work. Neither supplies the required
pre-author execution/evidence boundary.

## 1. Complete plan and candidate identity

The planning owner supplies a typed manifest, reviewed against the brief and
deliverables before feature implementation. Swamp stores each revision as an
immutable factory artifact, with its source brief revision, planning actor and
review evidence. These are provenance, not API approval or a new review ledger.
Never infer completeness from a regex, a list of existing files, or an LLM's
`no docs` sentence. A machine can validate shape and later detect omitted actual
paths; it cannot prove that a plan includes every future edit.

The manifest is `{id, revision, source, asserted_by, complete, paths}`:

- `id`: stable 1–64 characters from `A-Z a-z 0-9 _ -`; `revision`: positive
  integer, increasing for any change. `source` is `{brief_id, revision_sha256}`;
  `asserted_by` is a nonempty actor identifier, at most 128 characters.
- `complete` must be `true`. `paths` is a nonempty list of entries
  `{change: add|modify|delete, path}` or `{change: rename, from, path}`. Include
  source, tests, docs, generated outputs and proposed files that do not exist.
  The initial docs child uses the whole feature's planned inventory. Later
  revisions remain cumulative across the group, not just the current child.
- Paths are UTF-8 repository-relative **file** paths, at most 512 bytes each.
  Reject absolute/drive paths, backslashes, empty/`.`/`..` components, control
  characters, leading/trailing whitespace, globs, directories, `.git` components
  and symlink escapes. No
  silent trimming or correction. New files need not exist; delete/from paths
  must exist in the base or current tracked inventory. Rename contributes both
  old and new paths to the checker. Reject conflicting/duplicate entries.
- Sort entries by `(path, change, from-or-empty)` using UTF-8 byte order. The
  request must already be sorted. Limit to 256 entries and 32 KiB of canonical
  manifest JSON; exceedance refuses the whole plan, never drops entries.
- `plan_sha256` is SHA-256 of RFC 8785 canonical JSON of that entire manifest.
  `source.revision_sha256` hashes the exact source brief bytes, before any prompt
  formatting. All digests are lowercase hex. Shared fixtures fix encoding and
  hash behavior; clients do not maintain competing schema definitions.

The analysis request separately binds `base`, `head` and `tree` to full Git
object IDs in the repository's object format. Resolve refs in the owned VM;
never pass a branch name as a revision. `base` is the group's recorded analysis
base: its initial merge base with `origin/main`, established after provision.
Keep it fixed across children. If an authorized base update is needed, retain
the old evidence, create a new plan revision and recompute from the new full
base. No fetch, merge or base adoption occurs inside analysis.

Gateway verifies the base object/ancestry and takes fresh HEAD and whole-worktree
snapshots under the lease before dispatch and after completion. The tree uses
the existing snapshot definition, including uncommitted and unignored untracked
files, without changing the real index [snapshot]. A clean HEAD alone is not a
candidate identity. A post-handoff request uses `check.commit` as HEAD, not the
check result's pre-commit `head` [operations]. Pin repository identity, actual
branch, Instance/Node/lease,
and fixed entrypoint's tracked content digest. Ignored caches are not candidate
evidence. The report and run metadata must live outside the checkout's candidate
paths; no analysis output may change that tree.

## 2. Narrow native start, execution and report

**Proposed** `POST /api/v1/task-groups/{group}/analyze`, kind `tasks.analyze`, CLI
`tasks:analyze`, generated MCP `tasks_analyze`. Kind follows the existing
`<family>.<verb>` grammar [kinds]. Require `Idempotency-Key`. Exact input:
`{subtask, plan, plan_sha256, base, head, tree}`. Unknown fields refuse. IDs are
positive integers; manifest and revisions use section 1. The full JSON request
is at most 48 KiB. No command, arguments, environment, executable, working
directory, report path, model or caller-selected analyzer is accepted.

Reuse access checks, operation envelopes, keyed retention/replay, target lock
and current-open-child rules [operations] [owner]. Return `202`
`{data: OperationData, meta: {request_id}}`; read/cancel through existing
operations routes. `result` is null until terminal. A new call on a tick/ended
group, missing workspace, foreign/noncurrent child or busy group keeps the
existing refusals. Analysis changes no child/board status, starts no Pi turn,
creates no TaskCheck candidate, commits/pushes nothing, and advances no stage.

Persist steps `workspace_ready`, `analysis`, `report`. Resume a parked owned VM
as existing operations do. Reserve one execution/run identity before dispatch.
The fixed repository entrypoint is **proposed** `bin/task-analyze`, no arguments,
JSON stdin containing the validated plan/base/candidate and Gateway-issued
execution identity. It runs as the workspace owner in that checkout, without
model/GitHub credentials or approval authority. Check that the entrypoint is a
tracked regular executable at its pinned content digest. Missing tooling or
entrypoint is unavailable, with no shell, host-checkout or agent fallback.

In Orbit this is a thin adapter to existing `DocsImpact::report(base, paths)`
(or the existing command's repeated `--paths` interface). It passes the complete
flattened inventory once; the checker still unions actual paths. It validates
the existing report fields [scanner-result] against a repository-owned schema,
retaining all pages, reasons, surfaces, generator statuses, errors and verdict.
Do not introduce a second docs report model. Gateway validates only the generic
envelope and bounds; the repository owns payload schema/semantic validation.

The entrypoint's one stdout value is strict UTF-8 JSON:
`{schema: 1, execution_id, plan_sha256, base, head, tree, status,
diagnostics, report: {schema, data}}`. `status` is
`ready|action_required|blocked`; `diagnostics` is a list of `{code, message}`;
`report.schema` is a repository-owned identifier (Orbit: `orbit.docs-impact.v1`),
and `report.data` is the **complete existing report**, unchanged. No prose prefix,
duplicate object keys, non-finite numbers, trailing bytes or omitted fields.

The complete stdout envelope is at most 64 KiB, JSON depth at most 24. Overflow
is an error, never a shortened successful artifact. Stderr is a separate bounded,
redacted diagnostic tail, never evidence. The repository must emit safe fields;
if transport redaction would alter structured evidence, refuse it rather than
silently changing meaning. Repo validation checks the reported base and path
union against its input and actual workspace; a report for another plan or
candidate cannot become ready. Gateway cross-checks the echoed identities with
its stored execution, pins its own before/after snapshots and treats repository
output as project evidence, never privileged instructions.

Orbit's adapter maps a valid `no_docs_change`, empty errors and all required
generator checks passed to `ready`. `docs_required` without unresolved errors or
failed/missing/stale generators is `action_required`: supply it to the docs author
to write the required pages. Errors, contradictory verdicts, unowned/unresolved
surfaces or unavailable/failed generators yield `blocked`, even with process
exit 0 [command] [generators]. No green fallback. Generators are only checked
here; analysis never runs their write mode. An action-required report permits
docs work, not the start of feature runtime edits before docs review.

On valid completion, persist in the keyed operation's result:
`{outcome: ready|action_required|blocked|changed, artifact, provenance}`.
`artifact` is `{sha256, bytes, content}`: SHA-256/byte length of the exact UTF-8
stdout bytes and those bytes as a JSON string. Clients validate and parse the
complete `content` envelope, not reserialized JSON, when verifying the digest.
`provenance` contains Gateway-owned `operation_id`, `group`, `subtask`, repository,
branch, `plan_id`, `plan_revision`, `plan_sha256`, `base`, `before: {head, tree}`,
`after: {head, tree}`, `instance_id`, `node_id`, `lease_id`, `execution_id`,
`entrypoint_sha256`, `started_at`, `finished_at`, `duration_ms`, `exit_code` and
`mechanical_llm_calls: 0`. Times are ISO 8601 UTC; duration uses a monotonic
clock; identity strings/integers are typed, not extracted from logs.

`outcome: changed` is never usable; preserve any complete report as historical
evidence. A valid blocked report may end `succeeded` with `outcome: blocked`.
A nonzero exit, invalid/missing/oversized report or unknown execution outcome
does not produce a ready/action-required artifact. Malformed transport ends
`failed` only after terminal local proof; preserve its failure code and safe
diagnostics. Store bounded artifact bytes beside the existing operation state,
not in an 8/16 KiB log tail or a new artifact service. Size the typed response
budget for JSON encoding of the complete bounded fields; never silently truncate.
Keyed record/artifact retention survives VM destruction. Reads use existing
operation access checks; no host reaches into VM paths or receives arbitrary
file reads. The VM reader addresses only this operation's run record.

## 3. Ownership, recovery and invalidation

Analysis is read-only with respect to the candidate but takes the same exclusive
group holder and workspace lease as author/check work. This serializes a stable
view and descendants; it does not grant permission to write. No author, check,
publish or generator may overlap. A pre-existing lease makes the operation wait
under current fencing rules, with no dispatch. Release the operations worker
while waiting, not the group. Out-of-band edits invalidate the result. Temporary
analysis/cache writes outside candidate paths must still terminate before release.

Same key/target/input replays the stored operation before mutable eligibility
checks; changed input conflicts. Swamp saves the exact request/key before sending.
Lost responses/restarts adopt the same reserved run and retained report, with no
second scanner invocation. A missing-start recovery must atomically tombstone
the reserved identity before concluding it never ran [runner]. That tombstone
ends this attempt without dispatch; a new attempt needs a new key. If dispatched
work has no recoverable report, fail for inspection after termination; do not
rerun it under that key. A separately authorized retry uses a new key.

Cancellation before dispatch prevents execution. After dispatch, request stop,
reconcile the exact process/unit and descendants, and retain ownership until
terminal proof. A timeout, transport loss, missing PID or elapsed budget does
not prove termination. Unreachable/unknown work stays waiting/fenced; no author
may start. Reuse current stop/recovery semantics [journal]; no second worker or
new supervision framework. Cancellation never converts retained evidence into
approval. Terminal replay stays terminal and starts nothing.

Plan revision/digest, source brief, base, repository, entrypoint content, HEAD,
tree, or owning workspace change invalidates use for a new turn. Keep old
evidence immutable. New analysis gets a new operation/key. Swamp alone tracks
the latest authorized plan; Gateway cannot infer a change to intended work from
prose. Changing that plan while analysis runs stops progression and requires
stop/reconciliation before the new request. Do not overwrite an in-flight input.

## 4. Handoff to existing turns and actual candidate gate

**Proposed additive** `analysis` on existing `tasks.turn`:
`{operation: positive integer, plan_sha256: lowercase SHA-256}`. Absent means
existing behavior. Swamp's future docs workflow requires it; this is not global
docs policy in Gateway. Use the same native request/SDK/generated metadata,
not client-only aliases. No new agent role or separate approval ledger.

Resolve the referenced terminal successful `tasks.analyze` under the turn's
group/current child and validate its digest, complete artifact and usable
outcome. `ready` and `action_required` can supply evidence; `blocked`/`changed`
cannot. Verify its candidate/entrypoint/base under the existing turn lease
immediately before the first send; stale evidence refuses without a Pi send.
Reviewer turns still require their ordinary eligible handoff check, whose
commit/tree must match this fresh analysis. Do not loosen child binding or CI.

Gateway adds the full manifest, full validated envelope and provenance to the
existing prompt, labelled as repository evidence. Caller supplies the semantic
task prompt as today. Validate the **combined** prompt against the current
100,000-character bound [turn-request]; if it does not fit, refuse with no send.
No summary or shortened tail substitutes for the report. Persist the exact
assembled prompt/reference/hash in turn state before dispatch, so lost replies
reuse the same Pi/Durable identity and evidence. After dispatch, same-key recovery
recovers that turn; it does not revalidate the now intentionally edited tree and
send again. Prompt budget failure requires a smaller task/plan or a later reviewed
artifact transport design; a host file mount is not a workaround.

Initial analysis is valid as the **input** to its author turn. The author's
edits intentionally make it historical. After docs edits/generation and the
normal handoff commit/check, analyze the current checked commit again before
the reviewer consumes it. Include both initial and current operation IDs in
factory evidence. The existing reviewer checks plan completeness, prose meaning,
surface ownership, generator results and any waiver. Only then may Swamp admit
the feature runtime author, using a fresh analysis for its current candidate.
Reanalysis is needed for rework/next-child entries whose plan or candidate moved;
baseline is never rerun for this purpose.

At every handoff, derive actual tracked/untracked paths from the pinned base,
with renames contributing both ends and deletes retained. Compare to the full
plan inventory. An unplanned file blocks docs-workflow progression: record the
omission, revise/review the plan, rerun analysis and the affected docs gate.
Do not quietly add the path and retain old approval. The first native slice
provides evidence, not this Swamp lifecycle decision.

`bin/review-check`'s existing docs-impact gate independently checks the actual
candidate even if preflight claimed no docs. Preserve its complete result and
accepted `exceptions` evidence for review. Existing branch-local, page-specific,
reviewer-confirmed waivers remain authoritative [policy]; machine `no_docs_change`
is no waiver and proves no semantic correctness. Required generator checks cannot
be waived by a no-docs report. No separate docs-approval ledger is introduced.

Write-mode generators are distinct mutations. They need the same serial owner,
typed fixed repository selection, before/after evidence and termination proof;
their outputs invalidate earlier analysis. This first read-only slice does not
authorize them through `tasks.analyze`. Until a supported deterministic write
lane is separately demonstrated/reviewed, a needed generator parks the workflow;
do not invent a command endpoint, use an unowned host checkout, or make an agent
turn the mechanical fallback. Human/LLM prose work remains an existing turn.

## 5. Failures and example sequence

Proposed new codes are scoped below. Existing access/operation/task errors stay
unchanged [operations] [owner]. Admission errors have the normal HTTP error
envelope. The same defect discovered after admission fails the operation with
that code once safe to release; no later effect follows.

| Condition | Response / required behavior |
| --- | --- |
| Missing key, malformed plan/path/digest, unknown fields, request over bound | `422 validation.failed`; nothing starts |
| New analysis base is absent/not an ancestor, or HEAD/tree differs | `409 tasks.analysis_stale`; no dispatch |
| Entry point/tool unavailable | `422 tasks.analysis_unavailable` before dispatch, or failed operation with that code when discovered later |
| Invalid/missing/truncated/wrong-identity report | `tasks.analysis_report_invalid`; never usable |
| Envelope overflow | `tasks.analysis_report_too_large`; retain size/failure diagnostics, no partial artifact |
| Exit 0 with errors, unresolved surface, missing/failed/stale generator | Valid report `outcome: blocked`; preserve every diagnostic |
| Nonzero exit or checkout mutation | `tasks.analysis_failed`, or valid historical `outcome: changed`; no green fallback |
| Lost transport/unknown termination | Waiting, same owner/run; retain fencing until stop/reconciliation |
| Foreign/nonterminal/blocked analysis supplied to a turn | `422 tasks.analysis_not_usable`; no send |
| Stale analysis at turn preparation | `409 tasks.analysis_stale`; no send |
| Full evidence plus prompt exceeds turn limit | `422 tasks.analysis_prompt_too_large`; no send |
| New intended path appears at handoff | Swamp plan gate blocks; repository docs gate still runs on actual paths |

Example (IDs/hashes below are illustrative, never live instructions):

```json
{
  "subtask": 12,
  "plan": {
    "id": "feature-widget", "revision": 1,
    "source": {"brief_id": "widget-brief", "revision_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
    "asserted_by": "planning-owner", "complete": true,
    "paths": [
      {"change": "add", "path": "apps/gateway/app/Models/Widget.php"},
      {"change": "modify", "path": "docs/reference/widgets.md"}
    ]
  },
  "plan_sha256": "f729f123646027919d1ebdd3feb344471ca7cf8f02962eda6a0f9cb531c71392",
  "base": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "head": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "tree": "cccccccccccccccccccccccccccccccccccccccc"
}
```

Start with a persisted key such as `tg-10.analysis.s12.p1.c1`. Read its operation
after the returned delay. On usable completion, an existing author turn adds
`"analysis": {"operation": 42, "plan_sha256": "f729f123646027919d1ebdd3feb344471ca7cf8f02962eda6a0f9cb531c71392"}`.
After that author's checked handoff at a new HEAD/tree, operation 42 is historical:
run new analysis before reviewer dispatch. A revision-2 plan needs a new digest
and key even when the tree is unchanged. Same-key replay of 42 still returns its
original report; replay does not certify freshness.

## 6. Acceptance and serial delivery

The implementation brief owns the [acceptance matrix](gateway-repository-analysis-brief.md#acceptance-matrix).
This PR validates documentation only. It supplies no runtime/recovery proof.

Sequence: review this contract → implement only the demonstrated native gaps →
Swamp client/factory adoption and separately authorized docs-workflow proof →
dependency updates. Keep official factory methods/artifacts, stored requests,
era-aware gates and existing turns; no new orchestrator. Dependency adoption
later preserves registry failures, package constraints, major-version approval,
input revisions, lockfile provenance and review. A registry error must never
become an empty successful upgrade plan.

Measure a comparable before case before savings claims: repo/checker revision,
plan size, elapsed analysis time, planning/writing/review calls and token metrics.
For the new path record those separately and assert analysis mechanical LLM
calls = 0. No measurements or savings are claimed by this design.

Final-review/merge, topology, incoming PRs, bounded worker/orchestrator
interruption proof and tick retirement remain separate. Native implementation
must amend ADR0229's operation table, Check/Turn distinction, sender inventory
and rejected generic-command rationale to name this fixed read-only profile.
It must explicitly amend ADR0225's supervision-only boundary [agent-boundary]
for that profile while retaining its script-growth freeze [script-freeze]. No generic run or
generator authority is implied. If existing supervision cannot support it
within those bounds, report that concrete gap before expanding implementation.
Neither amendment opens the external review-and-merge/topology guards.

[policy]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/contributor-guide.md#L46-L64
[repo-policy]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/contributor-guide.md#L300-L306
[scanner]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/docs/app/Documentation/DocsImpact.php#L59-L141
[scanner-result]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/docs/app/Documentation/DocsImpact.php#L200-L270
[command]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/docs/app/Console/Commands/DocsImpactCommand.php#L14-L80
[generators]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/docs/app/Documentation/DocsImpact.php#L1103-L1124
[gate]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/bin/review-check#L193-L227
[check-request]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Http/Requests/Tasks/StartTaskCheckRequest.php#L17-L38
[check]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskCheckOperation.php#L48-L98
[candidate]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskCheckedCandidate.php#L12-L41
[turn-request]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Http/Requests/Tasks/StartTaskTurnRequest.php#L15-L40
[runner]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskCheckRunner.php#L16-L50
[snapshot]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskWorkspaceSnapshot.php#L9-L23
[tail]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskCheckOutputBuilder.php#L12-L49
[reading]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Infrastructure/Tasks/RemoteTaskCheckRunner.php#L181-L208
[factory]: https://github.com/hardimpactdev/orbit-swamp/blob/91bf8a0d392395e490b09d617fe9e265785e571b/models/%40swamp/software-factory/orbit-groups.yaml#L65-L99
[next-child]: https://github.com/hardimpactdev/orbit-swamp/blob/91bf8a0d392395e490b09d617fe9e265785e571b/models/%40swamp/software-factory/orbit-groups.yaml#L195-L224
[prompts]: https://github.com/hardimpactdev/orbit-swamp/blob/91bf8a0d392395e490b09d617fe9e265785e571b/extensions/behaviors/_lib/gateway_stage.ts#L174-L215
[operations]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/reference/operations.md#L25-L128
[owner]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Tasks/TaskOperations.php#L32-L91
[journal]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Infrastructure/Tasks/RemoteWorkspaceOperationJournal.php#L25-L112
[kinds]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/apps/gateway/app/Domain/Operations/OperationKinds.php#L16-L23
[adr]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations.md#L61-L120
[agent-boundary]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/decisions/0225-make-node-changes-through-agent-commands.md#L60-L74
[script-freeze]: https://github.com/hardimpactdev/orbit/blob/2187129c471c20a34b6c91d5e1cb6c34878b8742/docs/decisions/0225-make-node-changes-through-agent-commands.md#L138-L150
