# Orbit Current Slice State

This is the committed current-slice template. For non-trivial active work, copy
it to `.orbit/loop.md`, keep that local copy focused on the active slice, and
do not commit active `.orbit` state. Completed session archives under
`.orbit/sessions/` are committed so other machines can inspect them.

Use the compact packet below by default. Escalate to the full multi-slice
variant in `Appendix: Full Multi-Slice Variant` when HARNESS.md routing calls
for it.

## Feature Context

- Scratchpad: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`; baseline findings: `solo://proj/4/scratchpad/loop-review-findings--277`
- Worktree: `/Users/nckrtl/orbit/.worktrees/session-index-facet-normalization`
- Branch: `session-index-facet-normalization`
- Completed slices:
  - Baseline loop review: all 75 indexed archives classified, 15 highest-signal archives deep-read, and panel adjudication recorded
- Current slice: Normalize mechanically accepted session-index facets without changing loop policy

## Done Contract

- Single-slice: yes - one parser and its focused Pest contract form one atomic mechanical correction
- Parallelization: serial - the failing fixture defines the parser contract and must precede the implementation; an observer runs concurrently but remains read-only
- Done when:
  - A test-first fixture proves accepted same-line and nested packet shapes normalize loop outcome, analyzer verdict, and explicit no-blocker prose.
  - Existing nested packet behavior remains covered and passing.
  - The focused SessionIndexTest, syntax/format checks, full `composer quality-check`, review, and finalization analyzer pass on the final tree.
- Evidence:
  - Preserve the test-only red diff, focused command output, worker/reviewer/analyzer captures, final commit, and exact-tree quality evidence.
- Reviewer checks:
  - Confirm normalization is deterministic, backwards-compatible, fixture-driven, and does not widen archive lifecycle or finalization policy.
- Stop if:
  - The slice requires archive deletion/clearing, delegation-policy changes, warning-to-block severity changes, or any parked candidate B-D.
- Pivot if:
  - An accepted historical packet shape cannot be normalized without product or lifecycle semantics; return it to the Claude panel and park it.

## Progress

- Tried:
  Baseline worktree preparation, full `composer test`, and Grok worker 945 test-first dispatch with Codex observer 946.
  Result: baseline passed; worker produced a test-only first diff and focused RED (`complete` expected, `unknown` received). One orchestrator correction was required because the initial fixture did not exercise the historical same-line analyzer form; the corrected RED now covers raw values and closed aliases.
  Next: worker implements the smallest parser correction, then focused/full verification and independent review.

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- 2026-07-09 baseline review: same-line packet fields and natural no-blocker prose produce unknown/false-positive index facets; accepted as a mechanical parser correction.
- 2026-07-09 observer 946: the worker's first diff was correctly test-only but arrived at provider tool call 30 after 18 file reads and four tool-discovery searches, missing the requested roughly-first-dozen budget; retain as trial evidence for parked first-diff candidate B, not an expansion of this slice.
- 2026-07-09 orchestrator: one avoidable correction was needed because the first red fixture asserted a nested analyzer instead of the evidenced same-line analyzer form; corrected before production edits.
- 2026-07-09 orchestrator: a second late verification correction was needed after the first green because the natural no-blocker fixture omitted the leading Markdown bullet and exact historical forms; expanded test-only evidence before accepting the implementation.
- 2026-07-09 orchestrator: a third pre-review correction removed two dead parser wrappers and fixed the `none ... but blocked` safeguard order plus a missing word boundary.
- 2026-07-09 reviewer 947: three blockers remain after the first review—closed-head canonicalization gaps, discarded same-line `Verdict:` values, and indented lookalike labels accepted as top-level facets.
- 2026-07-09 lane-close capture: reviewer 947 capture failed with `ambiguous_duplicate_markers` after its internal convention sub-review inherited the Solo marker; this reappears the guarded lane-close capture signal and requires an explicit waiver plus follow-up adjudication.
- 2026-07-09 worker 948: fixed all reviewer blockers test-first, then repeated a 79-second no-diff planning stall on the table simplification; lane captured and replaced.
- 2026-07-09 replacement 949: consumed 470250 tokens while loading 1389 lines of skill guidance plus the full packet/diff, made no edit in three minutes, and was captured/closed after one execution checkpoint. Per the implementing-features exception ladder, the orchestrator applied the now-known table-only simplification directly and verified it.
- 2026-07-09 reviewer 950: the compressed final tree still promoted a first-line indented lookalike through section trimming and allowed an initial clear blocker bullet to hide a later real blocker bullet; both were corrected from failing boundary tests before acceptance.
- 2026-07-09 boundary replay: treating any resolved-blocker prose as active regressed two historical packets, so an exact resolved-entry test constrained the clear form without weakening either `but` safeguard.
- 2026-07-09 reviewer 951: final narrow boundary re-review found no blockers or optional suggestions after reading the exact two-file diff; checkout and transcript capture are retained.
- 2026-07-09 root verification: the first focused-test and Mago invocations supplied root-relative paths to gateway-scoped wrappers; Pest found no file and Mago skipped the duplicated path. The commands were immediately rerun with gateway-relative paths and passed, so this is retained as one avoidable verification correction rather than passing evidence.
- 2026-07-09 analyzer 952: the required fresh analyzer ultimately returned `VERDICT: yes`, but consumed 2714054 total tokens, loaded 1104 lines of implementation-skill guidance after the analyzer persona, read the exact orchestrator rollout, and required one synthesis checkpoint plus interruption/resume. Combined with observer 946's 9630096 tokens, this is durable cost evidence but the smallest safe target is not yet adjudicated; retain for program-level comparison rather than widening this slice.
- 2026-07-09 quality-gate triage: the cold `composer quality-check` passed in 101s and the prescribed same-command warm comparison passed in 81s. Most subgates recovered near baseline, while unrelated CLI Pest remained 79.3s; classified as stale/missing baseline with host/environment drift rather than a regression from this two-file diff. Existing cold-cache and CLI-lane signals already cover it.

## Blockers

- none

## Evidence Links

- `bin/orbit-prepare-worktree session-index-facet-normalization` -> prepared clean checkout; baseline suite passed.
- Baseline and panel evidence -> `solo://proj/4/scratchpad/loop-review-findings--277`.
- Worker 945 initial RED -> 1 failed / 3 assertions / 0.14s; corrected RED -> 1 failed / 3 assertions / 0.13s.
- Observer 946 phase report -> first diff test-only but at provider tool call 30; zero checkout/scope errors; one self-corrected path typo; first RED reached in 1m42s.
- Claude 943 facet adjudication and final adjudication -> roadmap scratchpad revision 4.
- Selected-archive blocker readback -> exact forms include `- None.`, `- none currently`, `- No blocker for Solo todo #190.`, `- No blocker currently.`, and `- No active ... blocker remains.`; the first green did not cover these bullet-preserving rows.
- Worker 945 final verification -> focused 1 passed / 49 assertions / 0.24s; full SessionIndexTest 4 passed / 95 assertions / 0.62s; PHP syntax and `git diff --check` passed.
- Worker 945 lane-close capture -> `.orbit/agent-sessions/grok/session-index-facet-worker-945`; 136 tool calls, 197212/512000 context tokens, 1116s duration.
- Observer 946 lane-close capture -> `.orbit/agent-sessions/codex/session-index-loop-observer-946`; 9602211 input, 27885 output, 9630096 total, 14691 reasoning tokens reported by provider capture.
- Read-only 75-archive replay with changed script -> loop-outcome `unknown` 10 to 0; analyzer `unknown` 14 to 8; `blockers_present=true` 36 to 7. Replay also exposed two `skipped because/for ...` values that remain noncanonical and were sent to reviewer 947 for adjudication before acceptance.
- Reviewer 947 -> `VERDICT: Blockers`; preserved at `.orbit/evidence/session-index-facet-reviewer-947.md` after lane-close capture failed with `ambiguous_duplicate_markers`.
- Worker 948 lane-close capture -> `.orbit/agent-sessions/grok/session-index-review-fix-worker-948`; 53 tool calls, 103373/512000 context tokens, 326s captured duration.
- Replacement 949 lane-close capture -> `.orbit/agent-sessions/codex/session-index-simplification-worker-949`; 466449 input, 3801 output, 470250 total, 2497 reasoning tokens; no diff produced.
- Orchestrator exception verification -> focused SessionIndex 1 passed / 79 assertions; full file 4 passed / 125 assertions; test Mago format, PHP syntax, and diff check passed; test insertion reduced from 310 to 200 lines; replay outcome unknown=0, analyzer unknown=8, blockers true=7, skipped-reason raw=2, bad normalized skipped-reason=0.
- Reviewer 950 lane-close capture -> `.orbit/agent-sessions/codex/session-index-facet-rereviewer-950`; verdict blockers on first-line indentation preservation and multiple blocker-entry parsing.
- Boundary REDs -> mixed clear/real blocker fixture failed at 1 failed / 65 assertions; exact resolved-entry fixture failed at 1 failed / 61 assertions.
- Boundary-fix verification -> focused SessionIndex 1 passed / 83 assertions; full file 4 passed / 129 assertions; test Mago format, PHP syntax, and diff check passed; replay outcome unknown=0, analyzer unknown=8, blockers true=7, skipped-reason raw=2, bad normalized skipped-reason=0.
- Reviewer 951 lane-close capture -> `.orbit/agent-sessions/codex/session-index-boundary-rereviewer-951`; exact marker, 185390 input / 4470 output / 189860 total / 3981 reasoning tokens; `VERDICT: No blockers`, optional suggestions none.
- Fresh root verification -> corrected gateway-relative focused command passed 4 tests / 129 assertions; Mago test format passed; PHP syntax and `git diff --check` passed. The preceding root-relative test command failed to locate the file and the root-relative Mago path was skipped; neither is counted as positive evidence.
- Broad pre-merge gate -> `composer quality-check` passed all nine apps/packages: gateway 4400 tests / 25275 assertions; CLI 2171 / 9089; docs 128 / 1034; core 111 / 517; SDK 128 / 411; docs-lint and the remaining Mago/Rector/Cargo lanes passed.
- Quality-gate timing triage -> cold pass 101s, same-command warm pass 81s, both exit 0; report `.orbit/evidence/session-index-quality-gate-triage.md`; no baseline refresh or product/timing diff assigned in this slice.
- Exact committed-tree gate -> commit `88c86af1538e035c030337b104e485eac5ff7a51`; `composer quality-check` passed all lanes in 79s; artifact `.orbit/quality-gates/quality-check-2026-07-09T234213Z-c4740726bb03.json` records the same commit.
- Fresh analyzer 952 -> `VERDICT: yes`; duplicate-marker recurrence classified `missed`, first-diff/fingerprint/archive lifecycle `defer`, and parser/stalls/wrapper-path decisions accepted as correct-noop. Capture `.orbit/agent-sessions/codex/session-index-post-feature-analyzer-952`; exact marker; 2701807 input / 12247 output / 2714054 total / 6652 reasoning tokens.
- Session archive: .orbit/sessions/2026-07-10-014331-session-index-facet-normalization

## Harness Signals

- Searched: current `harness-signals/` index and baseline session index evidence.
- Created or updated: none yet; this slice tests an accepted mechanical fix before any durable signal decision.
- Deferred follow-up: first-diff delegation policy, tested-tree fingerprint policy, and archive-clear lifecycle remain parked.
- Accepted separate follow-up: Claude process 943 adjudicated reviewer 947's repeated `ambiguous_duplicate_markers` capture failure as satisfying the existing signal's tighten-on-recurrence threshold. The next isolated slice will deterministically narrow duplicate candidates by Solo DB metadata, record the basis, and retain loud ambiguity when uniqueness is not provable; it does not widen this parser slice.

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion
reporting for any non-trivial feature loop. Use `not applicable` only for truly
tiny local changes with no workers, reviewer findings, retained terminal/PTY
evidence, quality gate artifacts, or human steering.

This section is the canonical local final packet. Scratchpads, reviewer output,
and final reports should point back here instead of becoming parallel final
packets. After the slice or feature loop is complete, archive the active
`.orbit/` session into the persistent, committed project archive home before
worktree cleanup or before rewriting `.orbit/loop.md` for a new slice. The
default archive home is the primary checkout's
`.orbit/sessions/generated-feature-slug/`. `bin/orbit-session-archive`
generates and enforces the archive directory name; run it instead of
hand-writing timestamps, and see HARNESS.md Worktree-Local State for the
naming contract. Preserve every active `.orbit/` entry except `.orbit/sessions/`,
including `loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, and
`agent-sessions/` output from
lane-close `bin/orbit-agent-session-capture` runs. `bin/orbit-session-archive`
copies staged captures byte-for-byte and falls back to archive-time extraction
only when no staged captures exist. Provider session archives are grouped by
LLM and process/session slug and contain
`manifest.json`, `usage.json`, `messages.jsonl`, and raw provider files for
supported providers. Antigravity remains an explicit unsupported/missing
manifest entry or waiver until a reliable local session-file contract is known.
`harness-signals/` remains curated distilled learning, not raw session storage.

Keep the exact Markdown bullet-label shape below.
`bin/orbit-feature-finalization-check` uses those list labels as the mechanical
merge-boundary contract. Equivalent custom headings, bare label lines without
`- ` and `:`, or prose do not replace them. Before merge or cleanup, at least
one of `- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, or `- No-new-signal rationale:` must contain a
meaningful value.

Keep the `- Fresh analyzer:` row even for compact loops. Use an analyzer verdict
when an explicit request or escalation trigger ran the Solo analyzer; use
`not used - rationale` as the normal compact-loop analyzer result when no
trigger applies; use `deferred - reason` only when analyzer infrastructure was
required but unavailable.

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: not applicable - offline deterministic session-archive index generator and fixture-only gateway test; no integrated topology, live node, host-runtime, or operator-visible runtime contract changed.
  - `composer quality-check`: passed - exact committed tree `88c86af1538e035c030337b104e485eac5ff7a51` passed all nine apps/packages; artifact `.orbit/quality-gates/quality-check-2026-07-09T234213Z-c4740726bb03.json`; timing classification `.orbit/evidence/session-index-quality-gate-triage.md`.
- Finalization gate fit:
  - The only tracked changes are the index generator and its focused Pest contract; docs-lint and the full quality gate passed, topology is correctly not applicable, reviewer 951 found no blockers, and analyzer 952 judged the loop proper.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - mechanical raw-plus-normalized facet parsing in `bin/orbit-session-index` and focused coverage in `SessionIndexTest.php`; parked policy/lifecycle candidates remain out of scope.
  - Includes worker/reviewer/terminal/evidence pointers: yes - lane captures 945, 946, 948-952; retained reviewer 947 report; quality-gate artifacts; 75-archive replay; Claude adjudications in roadmap scratchpad revision 10.
  - Includes orchestrator steering notes: yes - three fixture/implementation corrections, two reviewer correction rounds, stalled-worker exception-ladder use, wrapper-path verification correction, and analyzer checkpoint/interrupt are recorded above.
- Agent session capture waivers: Codex reviewer 947 only - `bin/orbit-agent-session-capture 947` failed loudly with `ambiguous_duplicate_markers` after an inherited subreview marker; its exact report is retained at `.orbit/evidence/session-index-facet-reviewer-947.md`, and the recurrence is accepted as a separate follow-up slice.
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: Codex Solo process 952; capture `.orbit/agent-sessions/codex/session-index-post-feature-analyzer-952`.
  - Verdict: `yes`; no remaining implementation, contract, actor-boundary, or verification finding; duplicate-marker recurrence classified `missed` and all other candidate decisions accepted as correct-noop or defer.
- Candidate signals:
  - mechanical session-index facet normalization -> promote -> baseline showed same-line/raw/closed-head and blocker false-positive defects; the deterministic two-file fix passes 129 focused assertions, full quality, final review, and 75-archive replay.
  - Codex inherited duplicate-marker capture ambiguity -> promote -> explicit recurrence trigger fired at reviewer 947; Claude 943 and analyzer 952 agree on a separate deterministic capture-helper slice with loud ambiguity fallback.
  - first-diff delegation budget -> defer -> one observe-mode trial showed a delayed first diff, but there is not yet a stable enforcement contract.
  - final-tree fingerprint/block policy -> defer -> corrections were caught by current review/finalization gates and no repeated stale-tree acceptance is proven.
  - archive-then-clear lifecycle -> defer -> existing archive-before-rewrite guidance remains adequate absent separate enforcement evidence.
  - worker/reviewer stalls and replacement over-reading -> already-covered -> execution checkpoints, capture, replacement, and the implementing-features exception ladder were reachable and bounded the slice.
  - root-relative path supplied to gateway-scoped wrappers -> already-covered -> gateway guidance already defines gateway-relative paths; invalid results were rejected and corrected results passed.
  - broad quality-check timing warnings -> already-covered -> the guarded cold-worktree and CLI timing signals supplied the same-command warm diagnostic and prevented product blame; the old baseline remains warning-only.
- Accepted durable updates:
  - `bin/orbit-session-index` now retains raw facet rows, normalizes only closed historical heads, preserves indented-label boundaries, and evaluates blocker entries independently; `SessionIndexTest.php` supplies the deterministic regression contract.
  - A separate capture-helper slice is accepted in roadmap scratchpad revision 10; no capture change is included in this branch.
- Rejected or already-covered signals:
  - Worker/reviewer stalls and the root wrapper-path correction need no new prose or automation because existing checkpoints, exception routing, and gateway command guidance worked before merge.
  - Cold/warm quality-check timing needs no feature-slice change because both runs passed, this diff does not touch the CLI hotspot, and existing timing-triage guardrails routed the warning correctly.
  - Reviewer-found parser defects need no separate harness prose because they are resolved in executable focused fixtures and independently re-reviewed.
- Deferred follow-ups:
  - First-diff delegation semantics, tested-tree fingerprint policy, and archive-clear lifecycle remain panel-parked pending additional durable evidence.
  - Analyzer/observer context cost is retained for program-level before/after comparison: observer 946 reported 9630096 total tokens and analyzer 952 reported 2714054 total tokens plus one checkpoint/interrupt; target selection remains unadjudicated rather than widening this slice.
- No-new-signal rationale:
  - Not applicable - this slice both lands the accepted parser improvement and promotes one independently scoped capture recurrence; all other observations are either already covered or explicitly deferred.

Validate either variant before merge with:

```bash
bin/orbit-feature-finalization-check --lint .orbit/loop.md
```

---

## Appendix: Full Multi-Slice Variant

Use this variant for multi-slice features, parallel workers, topology-relevant
diffs, product-contract changes, release scope, or any other HARNESS.md routing
case that escalates beyond the compact packet.

When a feature has multiple slices, archive the completed active `.orbit/`
session into the persistent project archive home before rewriting
`.orbit/loop.md` at the start of the next slice. The default archive home is
the primary checkout's `.orbit/sessions/<timestamp-feature-slug>/`.
`bin/orbit-session-archive` generates and enforces the archive directory name;
run it instead of hand-writing timestamps, and see HARNESS.md Worktree-Local
State for the naming contract. Do not leave the soon-to-be-removed feature
worktree as the only copy. Copy every active `.orbit/` entry except
`.orbit/sessions/`. Keep durable feature history, slice outcomes, and ordering
in the feature scratchpad and session archives. Keep code history in Git.

## Feature Context

- Scratchpad: <required `solo://...` feature roadmap for multi-slice features>
- Worktree:
- Branch:
- Completed slices:
  - <slice>: <one-line outcome>
- Current slice:

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch:
  - `.orbit/loop.md` links the feature roadmap and names the current slice:
  - If the source scratchpad lives in another Solo project, execution-project
    scratchpad links back to it and mirrors the roadmap substance:
- Parallelization scan:
  - Candidate parallel lanes:
  - Serialized lanes, with required dependency/shared-state/provider-capacity/
    merge-order reason:
  - Deferred lanes (lane -> concrete reason -> owner):
  - Parallel dispatch started (lane -> Solo process or owner):
- Done when:
  -
- Evidence:
  -
- Reviewer checks:
  -
- Stop if:
  -
- Pivot if:
  -

## Progress

- Tried:
  Result:
  Next:

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- <time/source>: <candidate signal, evidence pointer, and current status>

## Blockers

- <blocker, owner, and unblock condition>

## Evidence Links

- <command, result, artifact, retained topology id, Solo terminal/session,
  commit, or report>

## Harness Signals

- Searched:
- Created or updated:
- Deferred follow-up:

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion
reporting for any non-trivial feature loop. Use `not applicable` only for truly
tiny local changes with no workers, reviewer findings, retained terminal/PTY
evidence, quality gate artifacts, or human steering.

This section is the canonical local final packet. Scratchpads, reviewer output,
and final reports should point back here instead of becoming parallel final
packets. After the slice or feature loop is complete, archive the active
`.orbit/` session into the persistent, committed project archive home before
worktree cleanup or before rewriting `.orbit/loop.md` for a new slice. The
default archive home is the primary checkout's
`.orbit/sessions/<timestamp-feature-slug>/`. `bin/orbit-session-archive`
generates and enforces the archive directory name; run it instead of
hand-writing timestamps, and see HARNESS.md Worktree-Local State for the
naming contract. Preserve every active `.orbit/` entry except `.orbit/sessions/`,
including `loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, and
`agent-sessions/` output from
lane-close `bin/orbit-agent-session-capture` runs. `bin/orbit-session-archive`
copies staged captures byte-for-byte and falls back to archive-time extraction
only when no staged captures exist. Provider session archives are grouped by
LLM and process/session slug and contain
`manifest.json`, `usage.json`, `messages.jsonl`, and raw provider files for
supported providers. Antigravity remains an explicit unsupported/missing
manifest entry or waiver until a reliable local session-file contract is known.
`harness-signals/` remains curated distilled learning, not raw session storage.

Keep the exact Markdown bullet-label shape below.
`bin/orbit-feature-finalization-check` uses those list labels as the mechanical
merge-boundary contract. Equivalent custom headings, bare label lines without
`- ` and `:`, or prose do not replace them. Before merge or cleanup, at least
one of `- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, or `- No-new-signal rationale:` must contain a
meaningful value.

Keep the `- Fresh analyzer:` row even for compact loops. Use an analyzer verdict
when an explicit request or escalation trigger ran the Solo analyzer; use
`not used - <rationale>` as the normal compact-loop analyzer result when no
trigger applies; use `deferred - <reason>` only when analyzer infrastructure was
required but unavailable.

- Loop outcome:
  - <complete | blocked | complete + loop improvement>
- Required verification:
  - Retained topology proof: <passed | blocked | not applicable> -
    <retained topology id/kind plus checkout roles or inspected nodes, or host
    topology kind=host-macos; host=<hostname>; os=<Darwin/sw_vers>; command=<exact command>;
    evidence=<terminal/session/artifact/Computer Use evidence>; blocker, or reason>
  - `composer quality-check`: <passed | blocked | not applicable> -
    <command/evidence, blocker, or reason>
- Finalization gate fit:
  - <why the branch diff makes docs-lint, quality-check, and retained topology
    proof passed, blocked, or not applicable; see HARNESS.md Merge Boundary
    Gate>
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff:
  - Includes worker/reviewer/terminal/evidence pointers:
  - Includes orchestrator steering notes:
- Agent session capture waivers: <none | provider(s) and reason for missing or unsupported lane-close capture>
- Fresh analyzer:
  - Persona:
  - Solo process or analyzer:
  - Verdict:
- Candidate signals:
  - <candidate -> correct-noop | missed | redundant | wrong-target | defer |
    promote | already-covered | reject -> reason>
- Accepted durable updates:
  - <guardrail target, record, verification, or none>
- Rejected or already-covered signals:
  - <candidate, rationale, existing coverage when already-covered, and note if
    rejected because it was a one-off handoff, reviewer catch fixed before
    merge, stale historical artifact, or ordinary feature work>
- Deferred follow-ups:
  - <follow-up, owner, trigger, or none>
- No-new-signal rationale:
  - <why local cleanup, existing guardrails, already-landed fixes, or rejection
    was enough>
