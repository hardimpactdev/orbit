# Orbit Current Slice State

## Feature Context

- Scratchpad: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`
- Worktree: `/Users/nckrtl/orbit/.worktrees/agent-session-capture-disambiguation`
- Branch: `agent-session-capture-disambiguation`
- Completed slices:
  - Session-index facet normalization: merged at `ded3b388a`; archive `.orbit/sessions/2026-07-10-014331-session-index-facet-normalization`.
  - CLI Pest non-interactive baseline: merged at `ab6d1c9e8`; authoritative archive `.orbit/sessions/2026-07-10-030353-cli-pest-noninteractive-baseline`.
  - Core injected-output capability: merged at `1c24084e0`; archive `.orbit/sessions/2026-07-10-035504-core-injected-output-capability`.
- Current slice: resume and complete deterministic Codex agent-session capture disambiguation after the two blocking dependencies landed.

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch: yes - scratchpad 276, current revision 58.
  - `.orbit/loop.md` links the feature roadmap and names the current slice: yes.
  - If the source scratchpad lives in another Solo project, execution-project scratchpad links back to it and mirrors the roadmap substance: not applicable - roadmap and execution are both Solo project 4.
- Parallelization scan:
  - Candidate parallel lanes: capture-helper test/implementation/signal curation, independent review, aggregate verification, and post-feature analysis.
  - Serialized lanes, with required dependency/shared-state/provider-capacity/merge-order reason: one worker owns the script, its gateway fixtures, and the existing signal record because the red/green contract and curation describe one behavior; review consumes that final diff; live lane-close capture consumes the implementation; aggregate verification and analyzer consume the reconciled exact tree.
  - Deferred lanes (lane -> concrete reason -> owner): docs-drift and targeted monorepo trials -> must dogfood accepted loop changes after this merge -> feature-loop orchestrator process 942.
  - Parallel dispatch started (lane -> Solo process or owner): Grok workers 962 and 963 were captured and stopped after both failed the tiny known first-diff shape; the documented orchestrator exception supplied the behavioral red; Codex worker 964 completed production and review-fix TDD; Antigravity terminals 965 and 966 completed review/re-review; Codex analyzer 967 found two evidence/guardrail gaps; Codex correction worker 968 repeated the no-first-diff pattern and was captured/stopped, after which the same documented exception closed only those two deterministic findings; replacement Codex analyzer 969 completed the serialized read-only gate with no findings and `VERDICT: yes`.
- Done when:
  - A failing-first gateway fixture reproduces one true child Codex transcript plus an inherited duplicate marker in another transcript.
  - Deterministic Solo/provider metadata narrows duplicate exact-marker candidates only when one unique survivor is provable, records the disambiguation basis in the capture manifest, and never uses an unrecorded heuristic.
  - Candidate filtering uses provider context, exact normalized cwd, and a transcript's own primary Solo identity so a different primary identity cannot masquerade as the target. Timestamp is manifest-recorded corroboration only after those filters and may never exclude, rescue, tie-break, or solely select a candidate.
  - A companion fixture proves still-indistinguishable candidates remain a loud `ambiguous_duplicate_markers` failure.
  - Existing single-candidate Codex, Claude, and Grok behavior remains unchanged.
  - The existing `harness-signals/2026-07-07-lane-close-agent-session-capture.md` recurrence is curated with the smallest implemented guardrail and its index remains current; no new duplicate record or prose/template redesign is added.
  - Focused/full gateway verification, owned-file formatting, live lane-close capture, independent review, quality gate, fresh analyzer, finalization, archive/index, merge/tree proof, and cleanup pass.
- Evidence:
  - Contract/adjudication: roadmap scratchpad 276 section `Claude consultation: lane-close duplicate markers (2026-07-09)`.
  - Blocked predecessor: `.orbit/sessions/2026-07-10-021736-agent-session-capture-disambiguation/`.
  - Reprepared exact baseline at `1c24084e0bf02a37498f21e00693b65b695d48d3`: gateway 4,401; CLI 2,171 without stdin stall; docs 128; core 112; SDK 128; exit 0.
  - Red output, worker captures, review reports, live-capture manifests, quality artifacts, flawed analyzer report, analyzer-correction evidence, and the passing replacement analyzer report are retained below.
- Reviewer checks:
  - Changed-files-only review verifies unique disambiguation requires deterministic recorded evidence, foreign primary identities are excluded, ambiguous safety remains loud, and existing providers/singletons are not weakened.
  - Fresh analyzer is mandatory because this is an accepted loop-guardrail change inside an explicit multi-slice program.
- Stop if:
  - Selection would require guessing from file order, newest mtime, loose time proximity, or another unrecorded heuristic; retain loud ambiguity and consult Claude process 943.
- Pivot if:
  - Current provider artifacts do not expose enough deterministic metadata for a unique choice; keep capture failure loud, retain fixtures/evidence, and adjudicate the narrow missing metadata with Claude 943 rather than weakening the gate.

## Progress

- Tried: clean fast-forward from blocked base `ded3b388a` to merged `main` `1c24084e0`, then sanctioned `bin/orbit-prepare-worktree agent-session-capture-disambiguation`.
  Result: preparation passed all five test lanes; the former CLI stdin blocker did not recur.
- Tried: launch-pinned Grok worker 962 with a complete continuous TDD handoff and one explicit first-diff correction.
  Result: correct checkout/Solo proof and required reads, but no diff or explicit blocker after more than three minutes; it repeated checkout proof after the correction. Live capture succeeded at `.orbit/agent-sessions/grok/agent-session-capture-disambiguation-worker-962/`; the worker was stopped without repository edits.
  Next: replaced once with an even narrower exact two-fixture patch shape.
- Tried: launch-pinned replacement Grok worker 963.
  Result: correct checkout/Solo proof, but no diff or blocker after 90 seconds; it repeated status/tail reads and drifted into unrelated search-tool guidance. Live capture succeeded at `.orbit/agent-sessions/grok/agent-session-capture-disambiguation-replacement-963/`; it was stopped without repository edits.
  Next: applied only the known test-first diff as a documented implementing-features exception.
- Tried: orchestrator-owned test-only diff for inherited-primary identity and stale/wrong-cwd selection, retaining the existing indistinguishable duplicate safety fixture.
  Result: after removing a late incomplete worker-963 append and `.orig` race artifact with `apply_patch`, PHP syntax passed. Focused RED command `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='(inherited marker|wrong cwd)'` exited 1 with 2 failed / 2 assertions: both expected exit 0 but received loud `ambiguous_duplicate_markers` from the unmodified helper.
- Tried: Claude process 943 adjudication of the previously underspecified start-time rule.
  Result: timestamp must be corroboration-only after provider/cwd/primary-identity filtering, classified as `corroborated`, `resumed-predates-start`, `unavailable`, or `malformed`; it has no exclusion or selection power because real resumed Codex rollouts may legitimately predate the Solo process and a fixed window is a magic-number heuristic.
  Next: delegated production implementation from the concrete red and this final deterministic rule.
- Tried: launch-pinned Codex production worker 964, followed by orchestrator evidence corrections and live lane-close capture.
  Result: the helper now filters duplicate Codex exact-marker candidates by exact normalized cwd and primary Solo identity, selects only a unique survivor, preserves loud ambiguity otherwise, and records the stable basis plus non-selecting timestamp corroboration. Focused disambiguation passed at 2 tests / 9 assertions, unchanged duplicate safety at 1 / 3, and the full archive test file at 15 / 285. PHP syntax, signal-index write/check, gateway-test-only Mago format check, and `git diff --check` passed. The combined Mago check still identifies the root helper's pre-existing baseline style; it was not reformatted or claimed clean.
  Next: completed independent review, review-fix TDD, aggregate quality, and timing triage.
- Tried: live lane-close capture of worker 964 using the changed helper before stopping it.
  Result: `.orbit/agent-sessions/codex/agent-session-capture-production-worker-964/manifest.json` reports `status: ok`, exact marker, and rollout `019f49ca-bbb0-7420-a207-29201a857a14`; worker 964 was then stopped. This proves the singleton live boundary remained healthy while duplicate selection remains fixture-proven.
- Tried: independent Antigravity review in Solo terminal 965.
  Result: `VERDICT: findings`. The reviewer found that the supposed first-user identity scan continued into later user messages while no marker had been found, allowing an inherited child marker to become a parent's primary identity. The full report is `.orbit/evidence/antigravity-review-965.md`; terminal capture returned `terminal_kind_requires_waiver`, and process 965 was stopped.
- Tried: review-fix TDD in restarted worker 964.
  Result: after one execution correction stopped redundant discovery, the exact later-user-marker fixture failed red at 1 test / 1 assertion with `ambiguous_duplicate_markers`; the smallest seen-first-user boolean fix made the same test green at 1 / 5. Focused disambiguation passed 2 / 9, duplicate safety 1 / 3, and full archive file 15 / 285; syntax, signal index, test-only Mago, and diff checks passed. Evidence is `.orbit/evidence/review-fix-worker-964-red-green.md`.
- Tried: lane-close recapture after restarting Solo process 964.
  Result: the helper returned `ok` but selected the original pre-restart rollout `019f49ca-bbb0-7420-a207-29201a857a14`, not the review-fix session. Claude 943 adjudicated this as a distinct stale-singleton/restart defect outside the duplicate-candidate contract: reject false provenance, mark the recapture invalid, waive the review-fix session with independent evidence, use fresh Solo ids for all remaining roles, and promote restart-aware capture validity through its own bounded human-gated slice. The invalid manifest is retained at `.orbit/agent-sessions/codex/agent-session-capture-review-fix-worker-964/manifest.json`.
- Tried: focused independent Antigravity re-review in fresh Solo terminal 966.
  Result: no findings and `VERDICT: pass`; the first-user guard and exact inherited-marker fixture close both prior findings while base-instructions precedence, exact cwd, loud duplicate safety, and timestamp non-selection remain intact. Report: `.orbit/evidence/antigravity-rereview-966.md`. The reviewer redundantly ran tests despite the no-tests contract; this non-mutating ceremony is recorded for analyzer classification. Terminal capture returned `terminal_kind_requires_waiver`; process 966 was stopped.
- Tried: aggregate documentation and monorepo quality gates plus timing final-check.
  Result: `composer docs-lint` passed with zero errors and the existing 97 warning baseline; `composer quality-check` passed every app/package lane (gateway 4,402 / 25,292; CLI 2,171 / 9,089; docs 128 / 1,034; core 112 / 519; SDK 128 / 411; all Mago/Rector/Cargo lanes exit 0). `composer quality-gate:final-check` passed but warned that the 100s first worktree run exceeded the 26s local baseline, led by CLI Pest at 96.8s.
- Tried: quality-gate timing triage under `.agents/skills/quality-gate-triage`.
  Result: classified `stale/missing baseline` with possible cold-cache/host-load secondary because the seeded baseline's source artifact is absent, unrelated lanes slowed together, and the tiny gateway fixture contains no timing hazard. The exact-commit warmed comparison was reserved as the single diagnostic; no baseline refresh or regression claim was made. Report: `.orbit/evidence/quality-check-timing-triage.md`.
- Tried: fresh Codex post-feature analyzer 967 against the reviewed and aggregate-green tree.
  Result: functional correctness had no unresolved bug, but the loop verdict was `flawed` for two exact gaps: the loud duplicate fixture did not mechanically prove timestamp non-selection, and the repeated workers-962/963 first-diff failure had not been added to the canonical signal. Report: `.orbit/evidence/post-feature-analyzer-967.md`. Capture returned `exact_marker_not_found` because the required Solo marker was absent from the transcript; the report is preserved with an explicit waiver rather than presented as a healthy join.
- Tried: launch-pinned Codex correction worker 968 with only the analyzer's two patch shapes and an immediate-edit contract.
  Result: it proved exact checkout, branch, and `SOLO_PROCESS_ID=968`, then spent more than two minutes reading already-supplied guidance and still made no diff after the allowed execution correction. Live capture succeeded at `.orbit/agent-sessions/codex/agent-session-capture-analyzer-corrections-worker-968/manifest.json`; it was stopped. Under the existing exception, the orchestrator changed only the duplicate fixture, canonical first-diff record, and generated index. The two candidates now use valid unequal timestamps (`11:00:01Z` versus `11:25:00Z`) against real `started_at=11:00:00Z`, yet remain loud `ambiguous_duplicate_markers`. Focused duplicate safety passed 1 / 3, inherited/wrong-cwd disambiguation 2 / 9, full archive file 15 / 285, gateway-test-only Mago, signal-index check, and `git diff --check` all passed.
- Tried: fresh replacement Codex post-feature analyzer 969 with an exact first-message Solo marker and read-only contract.
  Result: it independently reviewed the five-file diff, full packet, prior flawed report, reviews/re-review, quality artifacts, both canonical signals, and roadmap. It found no gaps; duplicate disambiguation and the tightened first-diff record are correct as implemented, restart-stale singleton validity remains properly deferred to a separate slice, the stop-boundary race remains a one-off defer, and all other candidates are correct-noop. Report: `.orbit/evidence/post-feature-analyzer-969.md`; live capture: `.orbit/agent-sessions/codex/agent-session-capture-replacement-analyzer-969/manifest.json` (`status: ok`). Final line: `VERDICT: yes`.
- Tried: bind the five-file change to commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0` and rerun exact-commit verification.
  Result: combined focused capture behavior passed 3 / 12; full `AgentSessionArchiveTest.php` passed 15 / 285; helper syntax, signal index, docs lint, and packet lint passed. Exact-commit `composer quality-check` passed every subgate at `.orbit/quality-gates/quality-check-2026-07-10T031432Z-22a9975a3292.json`; aggregate duration improved from 100s to 82s, CLI Pest from 96.8s to 79.1s, and gateway Pest from 32.3s to 22.2s. Final-check remained warning-only against the provenance-incomplete 26s historical baseline. The unchanged CLI suite and prepared-worktree measurement support high-confidence `stale/missing baseline`; no additional rerun or baseline refresh is warranted.
- Tried: finalization, archive, guarded merge, tree proof, and merged-main verification.
  Result: merge-boundary finalization passed; the complete packet was archived at `.orbit/sessions/2026-07-10-051706-agent-session-capture-disambiguation` with staged captures preferred and session index current. Commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0` merged to `main` as `bee6c9960e52e937a34ae74db82cdf9fb184af00`. Merge tree and feature tree are identical at `d568a879e73dc63375b5e9415655c3d1551bb966`; an owned-file diff is empty. On merged `main`, the full archive test passed 15 / 285 and both harness-signal and session indexes are current. The primary checkout's pre-existing `.orbit/sessions/index.json`, historical untracked archives, and unrelated plan remain preserved.

## Candidate Signals While Working

- 2026-07-09/reviewer 947: inherited duplicate Codex markers caused `ambiguous_duplicate_markers` despite the existing lane-close signal's explicit tighten-on-recurrence trigger; Claude 943 accepted a separate deterministic-disambiguation slice.
- 2026-07-10/resume: the blocked predecessor required two dependency slices before sanctioned preparation could pass; both dependencies were deterministic code/test guardrails and preparation is now green without intervention.
- 2026-07-10/worker 962: no first diff or blocker after the one allowed correction; the existing first-diff guardrail required capture and replacement, so this is already-covered unless the replacement repeats it.
- 2026-07-10/replacement 963: repeated the same no-diff outcome on an exact tiny patch shape; the existing exception ladder permits orchestrator-owned first test diff and requires this recurrence to be recorded for final analyzer classification.
- 2026-07-10/replacement stop boundary: worker 963 completed an incomplete appended fixture and `.orig` backup after the last clean-status observation but before/while stop settled; the orchestrator removed only that late partial block. Record as a shared-state race candidate for analyzer classification, without adding ceremony from one occurrence.
- 2026-07-10/Claude timestamp adjudication: fixed windows and strictly-before-start rejection are rejected; timestamp is recorded corroboration only, so safety remains biased toward loud ambiguity over silent wrong provenance.
- 2026-07-10/production worker 964: initial broad Mago formatting created unrelated helper churn; the orchestrator required it removed before review. Existing scope/first-diff correction and semantic-diff inspection guardrails caught the drift, so classify with the fresh analyzer rather than adding prose now.
- 2026-07-10/evidence correction: the signal initially carried stale assertion totals and a formatter claim made before baseline-style restoration. The orchestrator required a fresh exact rerun and narrowed the record to truthful gateway-test-only formatting evidence.
- 2026-07-10/Antigravity review 965: a real false-primary-identity path was found and closed test-first; the reviewer contract and independent lane caught it before aggregate gates.
- 2026-07-10/restarted process 964: the recapture falsely selected the pre-restart singleton rollout. Claude 943 classifies this as a separate high-risk durable signal to promote in a new bounded slice; this slice must not imply restart-aware coverage.
- 2026-07-10/re-reviewer 966: Antigravity ran the full archive test, syntax, and formatting despite an explicit read-only/no-tests review contract. No state changed and review passed; classify redundant ceremony with the fresh analyzer rather than changing this slice.
- 2026-07-10/quality timing: first worktree quality-check passed but was broadly slow against a baseline whose source artifact is missing. The exact-commit warmed run also passed and improved from 100s to 82s while the unchanged CLI suite still dominated at 79.1s. Existing quality-gate triage guidance correctly required this one comparison and blocked premature baseline refresh; classify already-covered with high-confidence stale/missing baseline.
- 2026-07-10/analyzer 967: the timestamp rule was correctly implemented but not protected against future proximity tie-breaking, and the workers-962/963 recurrence had not been curated. Both exact findings are now closed without production change; require a replacement fresh analyzer rather than self-approving the correction.
- 2026-07-10/correction worker 968: repeated the first-diff failure despite an already-crystallized two-edit handoff and explicit execution correction. The existing exception again preserved evidence and bounded direct editing; the canonical record now includes this same-slice recurrence without adding another signal or duplicating skill prose.

## Blockers

- None.

## Evidence Links

- Roadmap: `solo://proj/4/scratchpad/orbit-feature-loop-r--276`.
- Claude second opinion and adjudication: `solo://proj/4/process/claude-code--943`.
- Claude timestamp advice/final adjudication: adopted `TIME_RULE_ADVICE` in process 943; provider/cwd/primary identity decide uniqueness, timestamp only annotates the manifest outcome.
- Blocked predecessor archive: `.orbit/sessions/2026-07-10-021736-agent-session-capture-disambiguation/`.
- Prepared base: `1c24084e0bf02a37498f21e00693b65b695d48d3`; branch clean and 0/0 divergent before worker dispatch.
- Initial worker capture: `.orbit/agent-sessions/grok/agent-session-capture-disambiguation-worker-962/` (`status: ok`; no repository diff).
- Replacement worker capture: `.orbit/agent-sessions/grok/agent-session-capture-disambiguation-replacement-963/` (`status: ok`; no repository diff).
- Test-first RED: focused command above, exit 1, 2 failed / 2 assertions, both failing on expected `ambiguous_duplicate_markers`; production and review-fix diffs subsequently closed the red without weakening loud ambiguity.
- Production worker capture: `.orbit/agent-sessions/codex/agent-session-capture-production-worker-964/manifest.json` (`status: ok`; rollout `019f49ca-bbb0-7420-a207-29201a857a14`; captured before worker stop).
- Production GREEN: focused 2 / 9, duplicate safety 1 / 3, full archive file 15 / 285, PHP syntax pass, signal index current, gateway test Mago-clean, and `git diff --check` clean.
- Independent review: `.orbit/evidence/antigravity-review-965.md` (`VERDICT: findings`), resolved by `.orbit/evidence/review-fix-worker-964-red-green.md` and fresh orchestrator reruns.
- Independent re-review: `.orbit/evidence/antigravity-rereview-966.md` (`VERDICT: pass`; no findings).
- Restart recapture adjudication: `.orbit/agent-sessions/codex/agent-session-capture-review-fix-worker-964/manifest.json` is explicitly `invalid`; Claude advice is at `solo://proj/4/process/claude-code--943` with final rule `RESTART_CAPTURE_ADVICE`.
- Aggregate quality: initial `.orbit/quality-gates/quality-check-2026-07-10T024256Z-7d482bfc2960.json`, exact-commit `.orbit/quality-gates/quality-check-2026-07-10T031432Z-22a9975a3292.json`, exact-commit `.orbit/quality-gates/docs-lint-2026-07-10T031306Z-a41d5d9ed0f3.json`, and `.orbit/evidence/quality-check-timing-triage.md`; all functional lanes passed, timing warnings remain non-blocking and are classified as stale/missing baseline.
- First analyzer: `.orbit/evidence/post-feature-analyzer-967.md` (`VERDICT: flawed`; no unresolved functional bug; two required corrections).
- Analyzer-correction worker capture: `.orbit/agent-sessions/codex/agent-session-capture-analyzer-corrections-worker-968/manifest.json` (`status: ok`; no repository diff by the worker before stop).
- Analyzer corrections: unequal valid candidate timestamps plus real Solo `started_at` still produce loud ambiguity (1 / 3); canonical first-diff recurrence record updated for workers 962/963 and `harness-signals/index.json` regenerated; inherited/wrong-cwd 2 / 9, full archive file 15 / 285, test-only Mago, signal index, and diff check pass.
- Replacement analyzer: `.orbit/evidence/post-feature-analyzer-969.md` (`VERDICT: yes`; no findings) and `.orbit/agent-sessions/codex/agent-session-capture-replacement-analyzer-969/manifest.json` (`status: ok`).
- Merge/topology proof: feature commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0`; merge commit `bee6c9960e52e937a34ae74db82cdf9fb184af00`; identical tree `d568a879e73dc63375b5e9415655c3d1551bb966`; merged-main full archive test 15 / 285; harness-signal and session indexes current.
- Session archive: .orbit/sessions/2026-07-10-051955-agent-session-capture-disambiguation

## Harness Signals

- Searched: `harness-signals/2026-07-07-lane-close-agent-session-capture.md`, `HARNESS_SIGNALS.md`, and `harness-signals/README.md`.
- Created or updated: curated the existing July 7 record with the duplicate-marker recurrence, deterministic disambiguation rule, red/green evidence, and explicit timestamp non-selection rule; curated the existing June 23 first-diff record with the workers-962/963/968 recurrences and bounded exception outcomes; regenerated `harness-signals/index.json`; no new record.
- Deferred follow-up: restart-aware singleton capture validity is accepted for its own next bounded slice; broader program measurement stays in scratchpad 276.

## Final Distillation

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: not applicable - this root harness capture helper and gateway fixture do not change Orbit product VM/node behavior; deterministic provider fixtures plus the real Solo lane-close capture at `.orbit/agent-sessions/codex/agent-session-capture-production-worker-964/manifest.json` are the matching boundary proof.
  - `composer quality-check`: passed on exact commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0` - `.orbit/quality-gates/quality-check-2026-07-10T031432Z-22a9975a3292.json`, every app/package subgate exit 0; timing warnings are non-blocking and classified at `.orbit/evidence/quality-check-timing-triage.md`.
- Finalization gate fit:
  - The five-file repository diff changes one executable root harness helper, its focused gateway fixtures, two canonical existing signal records, and generated signal index. Focused/full Pest, PHP syntax, signal index, docs lint, independent review, aggregate quality, timing triage, and the analyzer's two exact corrections pass. Merge `bee6c9960e52e937a34ae74db82cdf9fb184af00` has tree equality with feature commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0`, and the merged-main full test passes. Product docs and `PRODUCT_DECISIONS.md` are unchanged because no product behavior or direction changed. Retained topology is not applicable for this harness-only surface.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - accepted contract, exact five-file diff, analyzer corrections, and explicit restart-coverage boundary are recorded.
  - Includes worker/reviewer/terminal/evidence pointers: yes - workers 962/963/964/968, reviewers 965/966, analyzers 967/969, Claude 943, captures/waivers, red/green reports, and quality artifacts are linked.
  - Includes orchestrator steering notes: yes - blocked dependency chain and deterministic-selection stop conditions are recorded.
- Agent session capture waivers: Antigravity reviewer 965 -> `terminal_kind_requires_waiver`, report preserved at `.orbit/evidence/antigravity-review-965.md`; restarted review-fix incarnation of worker 964 -> helper selected the pre-restart rollout because the restarted incarnation minted no exact marker, so the false `ok` join was rejected and independent red/green evidence is preserved at `.orbit/evidence/review-fix-worker-964-red-green.md`; Antigravity re-reviewer 966 -> `terminal_kind_requires_waiver`, passing report preserved at `.orbit/evidence/antigravity-rereview-966.md`; Codex analyzer 967 -> `exact_marker_not_found` because its caller-facing Solo bootstrap marker was absent from the transcript, with report preserved at `.orbit/evidence/post-feature-analyzer-967.md`. Worker 968 required no waiver: its live capture manifest is healthy.
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: fresh Solo Codex process 969; report `.orbit/evidence/post-feature-analyzer-969.md`; healthy live capture `.orbit/agent-sessions/codex/agent-session-capture-replacement-analyzer-969/manifest.json`.
  - Verdict: `VERDICT: yes`
- Candidate signals:
  - inherited duplicate marker recurrence -> promote - accepted by Claude 943 because the existing guarded signal's reappearance trigger fired; implemented and verified in the helper, fixtures, canonical signal, and generated index.
  - repeated first-diff failure -> tighten existing record - workers 962, 963, and 968 triggered the existing bounded correction/exception paths exactly as designed, so the canonical June 23 signal now records the recurrences and successful recoveries without duplicating skill prose.
  - restart-stale singleton capture -> promote in a separate bounded slice - one concrete high-risk false-provenance near miss, a natural restart-after-review recurrence path, no existing coverage, a clear per-incarnation/restart-aware counterfactual, and reachable fixture verification; Claude 943 explicitly adjudicated it above the durable threshold.
- Accepted durable updates:
  - deterministic duplicate-Codex disambiguation in `bin/orbit-agent-session-capture`, focused gateway fixtures including later-user inherited identity and timestamp non-selection, curation of the canonical July 7 capture signal and June 23 first-diff signal, plus regenerated index; no template, waiver, gate, or broad prose redesign.
- Rejected or already-covered signals:
  - marker redesign, subreview prohibition, template/waiver/gate changes, and heuristic newest-file selection are rejected by the accepted design; formatter churn, evidence-count correction, redundant Antigravity tests, and cold/warm timing routing were caught by existing correction/review/triage guardrails; the one-off stop-boundary race remains deferred until recurrence.
- Deferred follow-ups:
  - restart-aware capture validity must be a new bounded loop-improvement slice after this merge; docs-drift and monorepo trials remain owned later program slices.
- No-new-signal rationale:
  - no new signal record is needed: this slice promotes the already-accepted July 7 recurrence and tightens the existing June 23 first-diff history.
