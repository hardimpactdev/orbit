# Orbit Current Slice State

## Feature Context

- Roadmap: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`, revision 112.
- Product decisions: `PRODUCT_DECISIONS.md` was read for the parent program; this parser/evidence correction changes no product direction.
- Orchestrator: Solo process `942`, project `4`.
- Worktree: `/Users/nckrtl/orbit/.worktrees/session-index-direct-child-authority`.
- Branch: `session-index-direct-child-authority`.
- Base: sanitized coherent `main` commit `235c18f3fdb08dab7840eb7e92e716b5856d7794` with 86 tracked archives and current index.
- Final reviewed commit: `53d16f55e427eff41e2f1c153caf52f6abe46003`; stable X2 patch id `0908c59861162ffb2f4f2b989b7ffffe465604f2` is identical to pre-rebase commit `3e2225e31db325c3f7493b3ae9f6f3e0b91ea122`.
- Current slice: X2 — remove the broad descendant analyzer-verdict fallback and record the review-gate recurrence.

## Done Contract

- Single slice: yes — parser, focused tests, generated index, and the existing accepted-adjudication recurrence record form one replay correction and its deterministic evidence.
- Parallelization: serial — production semantics depend on the two red fixtures; index regeneration and signal-index generation depend on the final parser/signal text; review/analyzer depend on the completed diff.
- Owned files:
  - `bin/orbit-session-index`
  - `apps/gateway/tests/Feature/E2ESupport/SessionIndexTest.php`
  - `.orbit/sessions/index.json`
  - `harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md`
  - `harness-signals/index.json` only as generated companion
- Raw acceptance examples and adjudication: roadmap revision 105, Claude 943 advice:
  - a `Fresh analyzer:` block with no same-line value and only a four-space grandchild `Verdict: yes` must be `unknown` with no authoritative raw facet;
  - a prose continuation containing `VERDICT: yes` must not become authoritative;
  - an exact two-space direct `Verdict:` child remains authoritative;
  - remove the whole-body `/Verdict:/` fallback; add no legacy exception without a future named cohort.
- Done when:
  - both fallback-only examples fail before production changes and pass after the smallest fallback removal;
  - all existing explicit-direct-child, same-line, blocker, and raw-facet tests remain green;
  - exhaustive replay against the coherent 86-record corpus derives the exact demotion set, including at least `2026-07-09-015306-todo-197-schedule-agent-push`, and proves zero other record-field movement;
  - counts are derived from replay, not frozen before evidence;
  - generated `.orbit/sessions/index.json` is byte-identical to fresh output and `--check` passes;
  - the existing raw-contract/accepted-adjudication signal records this occurrence and adds the Reappearance Check: while a user-requested review gate is open, keep `Loop outcome: blocked` with that gate named so finalization refuses merge;
  - no HARNESS, skill, persona, product docs, `PRODUCT_DECISIONS.md`, dependency, or unrelated archive change.
- Reviewer checks:
  - verify no descendant/prose path can produce explicit-verdict provenance;
  - verify direct-child matching remains exactly two spaces and multiple-row behavior does not widen;
  - independently recompute the named replay delta and zero-other-field assertion;
  - verify the signal change records recurrence without duplicating guidance.
- Stop if:
  - replay changes any unnamed facet or shows a real consistently-shaped legacy cohort needing adjudication;
  - primary index/archive state becomes stale;
  - product intent, E2E, or unrelated cleanup would be required.
- Pivot if:
  - more affected records appear: keep the grammar unchanged, name the full set, and consult Claude 943 before accepting counts;
  - the parser cannot distinguish direct child without a broader parser rewrite: stop rather than add abstraction.
- Deferred:
  - X1's 277 ignored local release-candidate files remain undeleted; R1 prevents future copies and aligns HARNESS.
  - R1/R2/R3/W and docs/monorepo trials remain separate slices.

## What Was Tried

- Tried: Solo `whoami` plus checkout proof before broad reads.
  Result: Solo process `1002` (`session-index-direct-child-authority`), project `4`; `pwd` `/Users/nckrtl/orbit/.worktrees/session-index-direct-child-authority`; branch `session-index-direct-child-authority`; HEAD `edaad09f10fe836d7494aede9d84ebfcd3d191a4`; `TRACKED_STATE_CLEAN`.
  Next: read the required harness, decision, implementation, testing, PHP, and version-control guidance before packet-local discovery.
- Tried: complete required reads of `AGENT_FAST_PATH.md`, `HARNESS.md`, `PRODUCT_DECISIONS.md`, `.agents/skills/implementing-features/SKILL.md`, Pest/TDD/PHP/version-control/verification guidance, and the full X2 packet; Laravel Boost docs search covered Pest `describe`/`it` organization and focused execution.
  Result: X2 is one serial shared-state slice; no product-direction change, E2E, final quality-check, topology, analyzer, merge, archive, or cleanup belongs to this lane. A read-only Laravel-rules sub-agent confirmed existing sibling patterns and no Laravel dependency expansion in the standalone parser.
  Next: lint the packet, inspect only the owned files and canonical generator seams, then make the first production-related diff tests-only.
- Tried: `bin/orbit-feature-finalization-check --lint .orbit/loop.md`.
  Result: exit `0`; `PASS: .orbit/loop.md Final Distillation packet shape is valid` while the explicit `Loop outcome: blocked` and open-review blocker remain intact.
  Next: inspect the owned parser, focused tests, recurrence record, and canonical generator commands.
- Tried: resolved a shared-worktree mutation by inspecting Solo project `4` process ownership and process `1001` output before running against mixed state.
  Result: process `1001` (`session-index-direct-child-worker`) was the expected cwd-pinned high-reasoning implementation worker. Its transcript proves a tests-only first diff with `bin/orbit-session-index` still byte-identical to HEAD; focused RED was `5 passed, 1 failed, 277 assertions` with fallback-derived `'yes'` where `null` was required; after removing only the whole-body fallback, focused GREEN was `6 passed, 283 assertions`.
  Next: capture worker `1001`, independently recompute the 86-record replay delta, then continue canonical regeneration and bounded signal update.
- Tried: coordination correction after process `1001` and retained authority process `1002` were dispatched into the same worktree milliseconds apart.
  Result: duplicate dispatch caused read-only inspection to overlap `1001`'s tracked edits; `1001` was stopped, process `1002` became the sole retained high-model worker, and no conflicting edit was applied. The unrecorded replay begun by `1001` is explicitly discarded from evidence.
  Next: audit the exact current diff line-by-line and independently rerun focused tests plus the complete before/after replay.
- Tried: `bin/orbit-agent-session-capture 1001` after the worker was stopped but while its Solo row remained available.
  Result: exit `0`; `status=ok`, provider `codex`, staged at `.orbit/agent-sessions/codex/session-index-direct-child-worker-1001`.
  Next: retain the authority lane for rework and continue independent verification.
- Tried: coordination correction after original low-model orchestrator process `942` continued editing the same X2 worktree despite the open review gate.
  Result: process `942` was stopped; process `1002` remains the sole worker. Visible tracked state gained the recurrence-record edit and generated session index from `942`; both are untrusted inputs pending independent line-by-line audit, replay, and generator checks.
  Next: accept no `942` result by report alone; recompute all four tracked diffs and corpus movement from HEAD.
- Tried: `bin/orbit-agent-session-capture 942` after stop.
  Result: exit `1`; provider `codex`, `exact_marker_not_found` after candidate inspection. Capture failed loudly and is waived below; process `942` remains stopped.
  Next: keep process `1002` retained for capture/rework and continue the bounded audit.
- Tried: line-by-line `git diff` audit of all four tracked files after processes `1001` and `942` stopped.
  Result: exact bounded shape: 43 test lines add the two required fallback-only fixtures/assertions; parser changes delete only the 12-line whole-body accumulator/fallback while preserving same-line and exact `^ {2}-` direct-child parsing; the signal edit adds only the adjudicated recurrence and Reappearance Check; the generated index contains one two-field demotion.
  Next: independently rerun focused tests and exhaustive replay rather than trusting worker/orchestrator summaries.
- Tried: `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionIndexTest.php` from process `1002`.
  Result: exit `0`; `6 passed`, `283 assertions`, `783ms`.
  Next: recursively compare current generator output against `git show HEAD:.orbit/sessions/index.json` across all 86 records and every field.
- Tried: `bin/orbit-session-index | php -r '<recursive HEAD/current comparator>'` with hard failures for count, top-level, record-set, or non-verdict field movement.
  Result: exit `0`; `86 -> 86`, no top-level changes, no added/removed records, exactly one changed record, and zero unexpected record-field movement. Complete demotion set: `2026-07-09-015306-todo-197-schedule-agent-push` changed `fresh_analyzer_verdict` from `yes` to `unknown` and `fresh_analyzer_verdict_raw` from the literal JSON string "yes`." to `null`.
  Next: inspect that named source shape, then run only the canonical index generators and checks.
- Tried: inspected only `.orbit/sessions/2026-07-09-015306-todo-197-schedule-agent-push/loop.md` lines 180-210 after replay named it.
  Result: the old fallback matched prose continuation containing `analyzer updated to` followed by the inline token `VERDICT: yes` under `Fresh analyzer`, not a same-line value or exact two-space direct `Verdict:` child; no coherent legacy cohort exists.
  Next: regenerate and check `.orbit/sessions/index.json` and `harness-signals/index.json` through their canonical tools.
- Tried: `bin/orbit-session-index --write`; `bin/orbit-harness-signal-index --write`; then both corresponding `--check` commands.
  Result: all exit `0`; session index written and reported up to date; signal index written and reported up to date. `harness-signals/index.json` is byte-identical to HEAD, leaving exactly four tracked files.
  Next: run narrow syntax, format, and diff checks; do not run final `composer quality-check` or broader orchestrator-owned gates.
- Tried: `php -l bin/orbit-session-index`; `bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/SessionIndexTest.php`; `git diff --check`.
  Result: all exit `0`; no PHP syntax errors, Mago reports all files already formatted, and Git reports no whitespace errors.
  Next: refresh the blocked final packet, re-lint it, stage only the four owned tracked files, and commit the bounded implementation.
- Tried: final packet/scope proof with `bin/orbit-feature-finalization-check --lint .orbit/loop.md`, explicit no-diff checks for `PRODUCT_DECISIONS.md`, HARNESS/skills/personas, and `harness-signals/index.json`, then explicit staging of the four owned files.
  Result: packet lint exit `0`; scope exclusions byte-identical; staged diff check exit `0`; staged shape `4 files changed, 59 insertions(+), 14 deletions(-)`.
  Next: commit with repository convention and rerun fresh narrow checks from the commit.
- Tried: `git commit -m "fix: enforce session index direct-child authority"`.
  Result: exit `0`; commit `3e2225e31db325c3f7493b3ae9f6f3e0b91ea122`, exactly four owned files.
  Next: independently verify committed HEAD and preserve the open review gate.
- Tried: post-commit focused Pest, both canonical index `--check` commands, PHP syntax, focused Mago format, `git diff --check`, exact commit/name proof, and tracked-state proof.
  Result: all exit `0`; Pest `6/6`, `283 assertions`, `725ms`; both indexes up to date; PHP syntax clean; Mago formatted; commit contains only the four owned files; `TRACKED_STATE_CLEAN`.
  Next: prove the review gate blocks the real merge caller context without executing a merge.
- Tried: read-only finalization helper first from the feature worktree, then from the primary `main` checkout using `bin/orbit-feature-finalization-check git merge session-index-direct-child-authority`.
  Result: feature-worktree invocation returned an inapplicable `PASS` because it was not the real merge caller context and was discarded; primary-checkout invocation exited `2` with `FINALIZATION: BLOCKED ... loop outcome is blocked ... only complete outcomes may pass a merge/cleanup boundary`. No merge ran.
  Next: leave process `1002`, the worktree, branch, blocked packet, and review gate available for capture/rework.
- Tried: orchestrator lane-close capture for retained authority process `1002`.
  Result: initial capture returned `exact_marker_not_found`; the lane then emitted its explicit owned `Solo process ID: 1002` join message, repairing capture provenance without an override. The retry succeeded with `status=ok` at `.orbit/agent-sessions/codex/session-index-direct-child-authority-1002` while the Solo row remained alive.
  Next: keep process `1002` idle and available for rework; the orchestrator owns review, quality, topology, analyzer, merge, archive, and cleanup.
- Tried: independent high-model read-only review in Solo process `1003` at exact commit `3e2225e31`.
  Result: no P0-P2 findings; final `VERDICT: yes`. Reviewer independently proved focused Pest `6/6` with `283 assertions`, coherent `86 -> 86` replay with exactly the two `todo-197` verdict-facet demotions and zero other movement, correct recurrence text, current indexes, clean four-file scope, and accurate packet/capture evidence. Reviewer capture succeeded with `status=ok` at `.orbit/agent-sessions/codex/session-index-direct-child-reviewer-1003`.
  Next: keep `Loop outcome: blocked` for the remaining quality, topology, analyzer, and security gates.
- Tried: `bin/orbit-prepare-worktree session-index-direct-child-authority` from `main` at `edaad09f1`.
  Result: prepared successfully; baseline gateway 4457/26033, CLI 2171/9089, docs 128/1034, core 112/519, SDK 128/411.
  Next: lint this packet, then dispatch one cwd-pinned high-reasoning implementation worker with test-only first diff.
- Tried: security hold after the whole-program high-model review found expired credential/authentication material in five X1 evidence paths.
  Result: unpublished X1 `edaad09f1` was sanitized and amended to `235c18f3fdb08dab7840eb7e92e716b5856d7794`; X2 was rebased to `53d16f55e427eff41e2f1c153caf52f6abe46003`. The parent rewrite changed exactly five expected blobs, the X2 patch id remained identical, no ref/worktree retains old X1, and separate main/X2 scans found zero known-value leaks across all 1,664 imported archive files. Value-free proof: primary `.orbit/evidence/x1-archive-privacy-review.md`; roadmap revision 112.
  Next: revalidate the exact rebased X2 commit before spending final gates.
- Tried: independent revalidation by high-model Solo reviewer process `1003` after the security-only parent rewrite.
  Result: checkout clean at `53d16f55e427eff41e2f1c153caf52f6abe46003`; old/new stable patch ids both `0908c59861162ffb2f4f2b989b7ffffe465604f2`; no P0-P2 findings; focused Pest `6/6`, `283 assertions`; index current; final `VERDICT: yes`. Lane-close capture refreshed with `status=ok` at `.orbit/agent-sessions/codex/session-index-direct-child-reviewer-1003`.
  Next: run the serialized aggregate quality gate.
- Tried: `composer quality-check` at exact rebased X2 commit.
  Result: exit `0`; all app/package lanes passed. Artifact `.orbit/quality-gates/quality-check-2026-07-10T113832Z-230a6aec6af1.json`.
  Next: run retained operator topology proof because the extensionless PHP parser is loop-critical executable code.
- Tried: retained Incus topology `dev-57dcbb` from the exact X2 worktree, source-mounting role `operator`; entered instance `orbit-e2e-dev-57dcbb-operator` through Solo terminal `1004` before executing the proof.
  Result: helper SHA-256 matched `98e91617f70771a2bb33f00d187b4b429725b776d201e0a26d2b70a356786e41`; PHP syntax passed; three-record runtime corpus proved direct child `yes`, grandchild `unknown`, and prose `unknown`; artifact `.orbit/evidence/x2-session-index-topology-proof.md`. The terminal was stopped after capture and topology `dev-57dcbb` was released. No `composer test:e2e*` command ran.
  Next: run fresh high-model analyzer process `1005`, adjudicate its report, then complete packet/finalization.
- Tried: `composer quality-gate:final-check` against the exact quality artifact.
  Result: exit `0`; no gate was rerun. It emitted warning-only comparisons against a stale 2026-06-26 26-second baseline whose source artifact is absent. Triage compared 15 unique passing July 10 artifacts: aggregate 77-131 seconds (median 89) and CLI Pest 74.7-127 seconds (median 85.4); current 103/100.1 seconds is inside the current range and X2 does not change CLI. Classification: `stale/missing baseline`, not an X2 regression; `.orbit/evidence/x2-quality-gate-timing-triage.md`.
  Next: keep the warning-only evidence and leave machine-local baseline maintenance to a separately owned action.
- Tried: fresh read-only post-feature analyzer in cwd-pinned high-model Solo Codex process `1005` at exact commit `53d16f55e427eff41e2f1c153caf52f6abe46003`.
  Result: checkout proof valid; no findings; descendant fallback, review-gate recurrence, and duplicate-dispatch handling classified `correct-noop`; archive privacy and all named follow-ups classified `defer` to their existing slices; no packet gaps; final `VERDICT: yes`. Report `.orbit/evidence/x2-session-index-fresh-analyzer-1005.md`; lane-close capture `status=ok` at `.orbit/agent-sessions/codex/x2-session-index-fresh-analyzer-1005`.
  Next: accept the analyzer result, finalize the packet, run the primary merge-boundary gate, and merge X2.
- Tried: primary-checkout merge boundary and merge-back.
  Result: `bin/orbit-feature-finalization-check git merge session-index-direct-child-authority` returned `FINALIZATION: PASS`; merge commit `b4a3dbffab8cf612d43c76008b2a1f49e25e2247` was created on `main`. Feature and merge trees are identical at `ed53a69fe14ec0cf3ee62669710e8951c513afd5`. Merged-main focused Pest remains `6/6`, `283 assertions`; session and harness-signal indexes are current; the unrelated untracked instance-runtime plan remains untouched.
  Next: archive the completed active `.orbit` state before process/worktree cleanup.

## Candidate Signals While Working

- Review/merge race: genuine post-tightening recurrence. The prior packet recorded a hold but remained completion-ready, so merge passed while the requested review was active.
- Parser fallback: deterministic correctness gap spanning the previous before/after proof; repair belongs in parser/tests/index, not new prose.
- Duplicate dispatch: processes `1001` and `942` overlapped retained authority process `1002` in one shared worktree; both were stopped, their contributions were treated as untrusted inputs, and process `1002` independently audited/replayed the final diff before commit.

## Blockers

None currently.

## Evidence Links

- Roadmap/adjudication: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`, revision 112.
- High-model replay review: collaboration task `review_replay_commit`; P1 descendant fallback and P2 index/corpus coherence.
- Sanitized X1 coherence commit: `235c18f3fdb08dab7840eb7e92e716b5856d7794`; primary and rebased-X2 privacy/index checks passed. Old X1 is unreachable from refs/worktrees and must never be pushed.
- X2 TDD worker capture: `.orbit/agent-sessions/codex/session-index-direct-child-worker-1001` (`status=ok`).
- X2 independent replay: process `1002`; 86 records before/after, one named two-field demotion, zero other field movement.
- X2 bounded commit: `53d16f55e427eff41e2f1c153caf52f6abe46003` after security-only rebase; pre-rebase commit `3e2225e31db325c3f7493b3ae9f6f3e0b91ea122`; identical patch id `0908c59861162ffb2f4f2b989b7ffffe465604f2`.
- X2 authority capture: `.orbit/agent-sessions/codex/session-index-direct-child-authority-1002` (`status=ok` after explicit non-override marker repair).
- X2 independent reviewer: Solo process `1003`, exact rebased commit `53d16f55e4`, no P0-P2 findings, final `VERDICT: yes`; refreshed capture `.orbit/agent-sessions/codex/session-index-direct-child-reviewer-1003` (`status=ok`).
- Full quality gate: `.orbit/quality-gates/quality-check-2026-07-10T113832Z-230a6aec6af1.json`, exit `0`.
- Timing triage: `.orbit/evidence/x2-quality-gate-timing-triage.md`; warning-only stale/missing baseline, no X2 regression and no rerun needed.
- Retained topology: `dev-57dcbb`, role `operator`, instance `orbit-e2e-dev-57dcbb-operator`, Solo terminal `1004`; `.orbit/evidence/x2-session-index-topology-proof.md`; source hash, syntax, and three verdict-authority assertions passed; topology released.
- Fresh analyzer: Solo Codex process `1005`, cwd-pinned to this worktree at the exact rebased commit; `.orbit/evidence/x2-session-index-fresh-analyzer-1005.md`; no findings; capture `status=ok`; final `VERDICT: yes`.
- Merge-back: `b4a3dbffab8cf612d43c76008b2a1f49e25e2247` on primary `main`; feature/merge tree `ed53a69fe14ec0cf3ee62669710e8951c513afd5`; merged-main focused/index checks passed.
- Mechanical review hold: primary-checkout finalization helper exit `2`, `FINALIZATION: BLOCKED` on `Loop outcome: blocked`.
- Session archive: .orbit/sessions/2026-07-10-135417-session-index-direct-child-authority

## Harness Signals

- Update: `harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md` only.
- Generated companion: `harness-signals/index.json`.
- No new record or guidance surface.

## Final Distillation

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: passed - topology `dev-57dcbb`, operator instance `orbit-e2e-dev-57dcbb-operator`, source checkout `/home/orbit/orbit-run`, Solo terminal `1004`, helper SHA-256 and direct-child/grandchild/prose runtime assertions; `.orbit/evidence/x2-session-index-topology-proof.md`.
  - `composer quality-check`: passed - exact commit `53d16f55e427eff41e2f1c153caf52f6abe46003`; artifact `.orbit/quality-gates/quality-check-2026-07-10T113832Z-230a6aec6af1.json`, exit `0`.
- Finalization gate fit:
  - passed - exact-commit independent review, focused Pest, current generated indexes, full quality artifact, retained topology, privacy/ref proof, final timing triage, and fresh analyzer all pass; the primary boundary returned `FINALIZATION: PASS`, and the merged tree is identical to the verified feature tree.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: objective, exact four-file diff, TDD sequence, and complete replay delta recorded.
  - Includes worker/reviewer/terminal/evidence pointers: roadmap, process `1001` healthy capture, process `942` explicit capture waiver, retained authority process `1002`, independent reviewer process `1003` with refreshed healthy capture and `VERDICT: yes`, quality/timing artifacts, retained terminal `1004`, topology evidence, security proof, and analyzer process `1005` with healthy capture and `VERDICT: yes`.
  - Includes orchestrator steering notes: X1/X2 split, duplicate-dispatch resolution, merge-race recurrence, exact raw cases, no-count-freeze rule, and discarded unrecorded replay present.
- Agent session capture waivers: Codex process `942` - stopped shared-worktree interferer; `bin/orbit-agent-session-capture 942` failed loudly with `exact_marker_not_found`. Processes `1001`, `1002`, reviewer `1003`, and analyzer `1005` captured healthy; authority capture path `.orbit/agent-sessions/codex/session-index-direct-child-authority-1002`; reviewer capture path `.orbit/agent-sessions/codex/session-index-direct-child-reviewer-1003`; analyzer capture path `.orbit/agent-sessions/codex/x2-session-index-fresh-analyzer-1005`.
- Fresh analyzer:
  - Persona: required after final reviewed diff because this changes loop metadata and recurrence handling.
  - Solo process or analyzer: Solo Codex process `1005`, cwd-pinned to this worktree at exact commit `53d16f55e427eff41e2f1c153caf52f6abe46003`.
  - Verdict: yes - no findings or packet gaps; deterministic parser and recurrence updates are correctly scoped; privacy and later program slices remain explicit deferrals.
- Candidate signals:
  - review-gate hold lost before action -> tighten existing recurrence record -> accepted for this slice.
  - descendant fallback -> deterministic parser/test/index correction -> accepted for this slice.
- Accepted durable updates:
  - parser/tests/index now enforce same-line or exact two-space direct-child analyzer authority; the only corpus demotion is named above.
  - existing raw-contract/accepted-adjudication record now records the review-gate recurrence and blocked-state reappearance check; no new guidance surface was added.
- Rejected or already-covered signals:
  - no new HARNESS/skill/persona prose; finalization already provides the mechanical gate when packet state is honest.
  - duplicate-dispatch friction was resolved by stopping overlapping processes, preserving/auditing provenance, and independently replaying from process `1002`; it does not expand this X2 recurrence record or justify a new guidance surface.
  - quality timing warning is not an X2 regression; 15 compatible current artifacts show the seeded June 26 baseline is stale, while existing timing-triage guidance already covers the classification.
- Deferred follow-ups:
  - data-only evidence correction; IX token/schema honesty; R1/R2/R3/W; preparation-lock, finalization-frame, archive-privacy, docs drift, monorepo trials, and ignored release-candidate cleanup decision.
- No-new-signal rationale:
  - no new record; this is a recurrence update plus deterministic parser enforcement.
  - analyzer `1005` agreed: remaining security, evidence, schema, archive/capture, CLI/core, preparation, finalization-frame, docs, and monorepo work belongs to the already-adjudicated independent slices rather than widening X2.
