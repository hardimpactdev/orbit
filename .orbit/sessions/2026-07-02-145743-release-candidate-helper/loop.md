# Orbit Current Slice State

## Feature Context

- Scratchpad: solo://proj/4/scratchpad/orbit-loop-review-20--223 (open items 3 and 4 from the Status sections)
- Worktree: /Users/nckrtl/orbit/.worktrees/release-candidate-helper
- Branch: release-candidate-helper
- Completed slices:
  - loop-plumbing-hardening (f36df928) and loop-hardening-followups (623fd844): archiver/gate/template/persona/staleness hardening, verified on main.
- Current slice: Extract the release skill's step-7 inline script into bin/orbit-release-candidate with persisted candidate state (formalizing the existing .orbit/release-candidates/<build_id>/candidate.env convention, adding CLI sha256s), wire steps 9/12/13 to source it, and pin worker cwd at spawn (codex --cd / grok --cwd via extra_args) in the canonical spawn guidance.

## Done Contract

- Single-slice: yes - two bounded fixes from the tracked open-items list; the release helper and the spawn-guidance edit share no files.
- Parallelization: serial - three workflow lanes with disjoint files (helper+tests; release skill doc; spawn-guidance docs) run in parallel; release-skill doc lane waits for the helper lane's final interface.
- Done when:
  - bin/orbit-release-candidate exists with build / env / verify subcommands: build runs the current step-7 flow and writes .orbit/release-candidates/<build_id>/candidate.env including CLI artifact sha256s and gateway digest plus a latest pointer; env prints eval-able exports from latest or --build-id; verify recomputes local artifact hashes against state and optionally checks a promoted image digest.
  - The release skill's step 7 calls the helper; steps 9, 12, and 13 source state via eval "$(bin/orbit-release-candidate env)" instead of relying on same-shell variables; human-approval boundaries are unchanged.
  - HARNESS.md Solo Role Matrix spawn recipe and implementing-features spawn rules require pinning the assigned worktree at launch via extra_args (codex --cd, grok --cwd); Claude lanes keep the relaunch-through-terminal fallback; pwd/branch proof stays mandatory.
  - New Pest coverage (failing-first) for the helper: state round-trip, preflight origin-commit guard, verify hash mismatch detection, env output shape — docker/gh/git stubbed via PATH.
  - Focused Pest green; composer quality-check green; merged to main and pushed.
- Evidence:
  - Red-test output at .orbit/evidence/rc-red.txt; verify results at .orbit/evidence/rc-verify.txt.
- Reviewer checks:
  - One adversarial reviewer over the full diff (correctness, approval-boundary preservation, scope) before commit.
- Stop if:
  - Any change would weaken release approval boundaries (no auto GitHub publish, human approval keyed to build_id/commit/hashes/digest) or other scratchpad-223 keep-list items.
- Pivot if:
  - An item is already covered on main — classify already-covered.

## Progress

- Tried: bin/orbit-prepare-worktree release-candidate-helper --skip-tests.
  Result: WORKTREE_PREPARED base_ref=main.
- Tried: Solo feature request for first-class spawn cwd param via submit_solo_feedback.
  Result: form opened with drafted request; awaiting user submit (requires_user_submit).
- Tried: lanes L1 (helper + Pest, TDD), L3 (cwd-pinning docs) in parallel; L2 (release skill wiring) after L1 interface settled.
  Result: bin/orbit-release-candidate with build/env/verify; red 5-fail evidence at .orbit/evidence/rc-red.txt, green 5/70 assertions; release SKILL step 7 collapsed to helper invocation, steps 9/12/13 eval env, verify at 12/16; HARNESS role matrix carries canonical cwd rule (codex --cd, grok --cwd, terminal fallback for claude/agy — agy --help proof at .orbit/evidence/cwd-agy-help.txt).
- Tried: verify lane (Pest 5/5; bash -n; state-fixture smoke incl. tamper-detection FAIL naming sha key; docs-lint; consistency greps) and adversarial reviewer.
  Result: zero blockers, five suggestions.
- Tried: fixer applied four suggestions TDD-first (verify-inspect failure handling, build-id pattern validation closing a proven ../../ traversal, dirty-tree build guard, print-order alignment); fifth suggestion (prefixed env var names) rejected to keep the practiced candidate.env convention.
  Result: 8/8 tests, 84 assertions; red/green appended to rc-red.txt.
  Next: quality-check, distillation, gate, commit, merge, archive, push.

## Candidate Signals While Working

- 2026-07-02 orchestrator: prior release runs already invented the candidate.env state convention ad hoc (.orbit/release-candidates/20260628T*/candidate.env) — the helper formalizes practiced behavior rather than introducing a new one.
- 2026-07-02 quality-check: McpConfigurationTest guard pinned `extra_args` out of HARNESS entirely; the cwd rule legitimately uses it in the Solo Role Matrix. Reconciled the pin structurally (extra_args allowed only inside the Role Matrix section, personas still banned) — second instance of wording-pins-vs-contract-pins this week; the reconciliation itself encodes the contract now.
- 2026-07-02 quality-check: E2ECurrentCheckoutTest parallel flake reproduced again (2 of 3 full runs today) — already tracked in scratchpad 223; urgency of the quarantine slice increased.

## Blockers

- none

## Evidence Links

- Existing state convention sample: .orbit/release-candidates/20260628T212657Z-ad04c124/candidate.env (primary checkout)
- Solo spawn_agent schema lacks cwd param (tool schema inspected 2026-07-02); codex -C/--cd and grok --cwd verified via --help
- Session archive: .orbit/sessions/2026-07-02-145743-release-candidate-helper

## Harness Signals

- Searched: harness-signals/index.json for release-gate and worker-dispatch records — 2026-06-23-release-boundaries-require-explicit-approval and 2026-06-23-worktree-target-before-editing relate; neither covers cross-step shell state or spawn-time cwd pinning.
- Created or updated: pending final distillation.
- Deferred follow-up: pending final distillation.

## Final Distillation

- Loop outcome:
  - complete
- Required verification:
  - Retained topology proof: not applicable - diff adds a repo-dev release helper (bin/orbit-release-candidate), a gateway test suite, and harness/skill markdown; no topology, VM, or CLI product behavior changed, and no release was executed.
  - `composer quality-check`: passed - exit 0 on the second run after reconciling the McpConfigurationTest extra_args guard (first run also tripped the known E2ECurrentCheckoutTest parallel flake, which passes focused; artifact in .orbit/quality-gates/).
- Finalization gate fit:
  - Non-docs diff (new bash helper under bin/ plus gateway tests) requires quality-check evidence — present and passing; no topology-relevant production PHP.
- Distillation packet:
  - Location: `.orbit/loop.md`
- Fresh analyzer:
  - deferred - bounded slice implementing two pre-catalogued open items; adversarial reviewer (zero blockers), independent verify lane with tamper-detection smoke, and red-first TDD evidence cover the risk.
- Candidate signals:
  - env --build-id path traversal found by reviewer and proven red before fix -> reject as loop signal (reviewer catch fixed before merge; the shipped validation test is the guardrail).
  - prior release runs had already hand-built candidate.env state -> promote-by-formalization (the helper + tests now own the practiced convention; no separate signal record needed beyond this slice's diff).
  - Solo spawn lacks cwd param -> defer to Solo product (feature request drafted via submit_solo_feedback, awaiting user submit); orbit-side mitigation shipped in HARNESS/implementing-features.
- Accepted durable updates:
  - bin/orbit-release-candidate + ReleaseCandidateHelperTest (release-state contract now tool-enforced); HARNESS.md Solo Role Matrix launch-time cwd rule with implementing-features pointing at it.
- Rejected or already-covered signals:
  - Prefixed env var names suggestion rejected: unprefixed keys match the practiced candidate.env convention and the skill snippets that consume them.
- Deferred follow-ups:
  - Solo spawn_agent cwd parameter — owner: Solo product (feedback form drafted, user to submit).
  - Antigravity CLI has no working-directory flag (agy --help evidence); revisit the fallback if a flag ships — owner: next release/dispatch touchpoint.
- No-new-signal rationale:
  - Both items came from the already-curated scratchpad-223 backlog; reviewer catches were fixed pre-merge and encoded as tests, so no additional harness-signals record is warranted.
