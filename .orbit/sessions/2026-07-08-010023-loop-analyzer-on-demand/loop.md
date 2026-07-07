# Orbit Current Slice State

This is the committed current-slice template. For non-trivial active work, copy
it to `.orbit/loop.md`, keep that local copy focused on the active slice, and
do not commit active `.orbit` state. Completed session archives under
`.orbit/sessions/` are committed so other machines can inspect them.

Use the compact packet below by default. Escalate to the full multi-slice
variant in `Appendix: Full Multi-Slice Variant` when HARNESS.md routing calls
for it.

## Feature Context

- Scratchpad: none, single-slice
- Worktree: `/Users/nckrtl/orbit/.worktrees/loop-analyzer-on-demand`
- Branch: `loop-analyzer-on-demand`
- Completed slices:
  - none
- Current slice: make post-feature analyzer on-demand by default for compact
  feature loops while preserving escalation and archive-backed analysis.

## Done Contract

- Single-slice: yes - the approved change is one root-harness contract
  simplification across the loop packet, implementation skill, analyzer
  routing text, and guard test.
- Parallelization: serial - the owned files all describe the same analyzer
  default contract and need one reconciled wording pass before the guard test
  can be updated safely.
- Done when:
  - `HARNESS.md` says normal compact feature loops do not run a standing or
    default post-feature analyzer; analyzer use is explicit or escalation
    triggered.
  - `.agents/skills/implementing-features/SKILL.md` tells implementers to fill
    `.orbit/loop.md`, classify signals, and record a no-analysis rationale for
    compact loops instead of treating the analyzer as routine.
  - `LOOP.md.example` makes `Fresh analyzer` support `not used - <rationale>`
    as a normal compact-loop value, not only deferred infrastructure failure.
  - `apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php` pins the
    new on-demand analyzer contract.
- Evidence:
  - Baseline worktree prep: `bin/orbit-prepare-worktree loop-analyzer-on-demand`
    completed and ran `composer test` successfully.
  - Red test first for the architecture contract before docs/skill edits.
  - Focused Pest for `McpConfigurationTest.php` after implementation.
  - `composer docs-lint` for root harness and loop template documentation.
  - `composer quality-gate:final-check` after the verification artifacts exist.
- Reviewer checks:
  - Documentation/librarian review is not a separate lane unless the
    Solo-managed orchestrator finds authority conflict; this is root harness
    process documentation, not product docs.
  - Post-feature analyzer for this slice is escalation-triggered because the
    slice changes the analyzer contract itself; use the current contract for
    this one final review unless implementation updates it first and records
    the transition explicitly.
- Stop if:
  - Current docs or tests require analyzer coverage for every feature loop and
    cannot be reconciled with the approved on-demand direction.
  - The finalization gate cannot accept an explicit `not used - <rationale>`
    analyzer row without weakening session archive or verification evidence.
  - Solo cannot run a tracked Codex orchestrator for the implementation loop.
- Pivot if:
  - The guard test shows analyzer requirements are generated from executable
    finalization-check behavior; then update the helper/test contract alongside
    docs instead of leaving docs-only drift.

## Progress

- Tried: user approved the scoped direction from the Codex app discussion.
  Result: single-slice worktree prepared at
  `/Users/nckrtl/orbit/.worktrees/loop-analyzer-on-demand`.
  Next: hand off to Solo-managed Codex orchestrator for implementation,
  verification, merge-back, and cleanup.
- Tried: TDD red check for the architecture contract with
  `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php --filter='keeps post-feature analyzers on demand for compact loops'`.
  Result: failed as expected because `HARNESS.md` did not contain
  `Default compact loops do not run a standing post-feature analyzer.`
  Next: update harness, loop template, implementation skill, and guard text.
- Tried: TDD red check for finalization helper guidance with
  `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php --filter='blocks a merge when the fresh analyzer row is missing'`.
  Result: failed as expected because the helper advertised only
  `deferred - <reason>` and did not mention `not used - <rationale>`.
  Next: update finalization helper copy and regression tests.
- Tried: implementation pass across the owned harness docs, skill, loop
  template, signal guidance, finalization helper, and architecture/finalization
  tests.
  Result: focused analyzer contract checks are passing.
  Next: run full requested architecture Pest file, docs-lint, quality/final
  gates, analyzer, archive, commit, merge-back, and cleanup boundary checks.

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- 2026-07-08/Codex app: analyzer default appears heavier than the intended
  compact loop; current implementation skill still pressures routine analyzer
  use. Status: owned by this slice.

## Blockers

- none

## Evidence Links

- `bin/orbit-prepare-worktree loop-analyzer-on-demand`: passed; prepared
  worktree and baseline `composer test`.
- Solo source actor: `external-codex-app-loop-setup-simplification`.
- Red TDD:
  `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php --filter='keeps post-feature analyzers on demand for compact loops'`
  exited 1 with missing on-demand analyzer text in `HARNESS.md`.
- Red TDD:
  `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php --filter='blocks a merge when the fresh analyzer row is missing'`
  exited 1 with helper output missing `not used - <rationale>`.
- Green focused:
  `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php --filter='keeps post-feature analyzers on demand for compact loops'`
  passed 1 test / 10 assertions.
- Green focused:
  `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php --filter='fresh analyzer'`
  passed 3 tests / 8 assertions.
- Green focused:
  `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php`
  passed 22 tests / 241 assertions.
- Green focused:
  `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`
  passed 47 tests / 111 assertions.
- Docs lint:
  `composer docs-lint` passed; artifact
  `.orbit/quality-gates/docs-lint-2026-07-07T224028Z-8c15229693fb.json`.
- Formatting:
  `bin/orbit-gateway-vendor-bin mago format --check` initially found one
  unformatted test file; `bin/orbit-gateway-vendor-bin mago format` fixed it,
  and the follow-up format check passed.
- Diff hygiene: `git diff --check` passed.
- Broad quality:
  `composer quality-check` first hit Composer's default 300 second process
  timeout; `COMPOSER_PROCESS_TIMEOUT=0 composer quality-check` passed with
  artifact
  `.orbit/quality-gates/quality-check-2026-07-07T225236Z-6fe826e6a54c.json`.
- Final gate:
  `composer quality-gate:final-check` passed; warning-only timing drift was
  classified against existing quality-gate baseline/cache signals.
- Post-feature analyzer:
  Solo process 845 (`loop-analyzer-on-demand-post-feature-analyzer`) reported
  no findings and `VERDICT: yes`.
- Analyzer lane capture:
  `bin/orbit-agent-session-capture 845` staged
  `.orbit/agent-sessions/codex/loop-analyzer-on-demand-post-feature-analyzer-845`.
- Session archive: .orbit/sessions/2026-07-08-010023-loop-analyzer-on-demand

## Harness Signals

- Searched: memory and current harness references for `post-feature analyzer`,
  `Fresh analyzer`, loop ceremony simplification, and quality-gate timing
  baseline records.
- Created or updated: durable guardrails updated directly in `HARNESS.md`,
  `LOOP.md.example`, `.agents/skills/implementing-features/SKILL.md`,
  `HARNESS_SIGNALS.md`, `harness-signals/README.md`, and
  `bin/orbit-codex-pre-tool-use-hook`.
- Deferred follow-up: none.

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
default archive home is a tool-generated directory under the primary
checkout's `.orbit/sessions/`. `bin/orbit-session-archive`
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

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: not applicable - root harness docs/test contract
    change plus test/finalization-helper copy only; no topology, VM, CLI
    runtime, native host, or live-node behavior changed.
  - `composer quality-check`: passed -
    `COMPOSER_PROCESS_TIMEOUT=0 composer quality-check`; first plain
    `composer quality-check` hit Composer's 300 second wrapper timeout, then the
    timeout-disabled rerun passed with artifact
    `.orbit/quality-gates/quality-check-2026-07-07T225236Z-6fe826e6a54c.json`.
- Finalization gate fit:
  - Branch diff changes root harness docs/templates, implementation skill text,
    finalization-helper copy, and Pest architecture/finalization tests. The
    non-docs helper/test diff requires artifact-backed `composer quality-check`,
    which passed. Retained topology proof is not applicable because the PHP
    changes are tests only and the runtime helper change is merge-gate wording,
    not topology or live-node behavior.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - on-demand analyzer contract across
    harness docs/templates, implementation skill, signal guidance, finalization
    helper copy, and guard tests.
  - Includes worker/reviewer/terminal/evidence pointers: Solo orchestrator 844,
    post-feature analyzer 845, analyzer lane capture, baseline worktree prep,
    TDD red/green Pest, docs-lint, quality-check, and quality-gate final-check.
  - Includes orchestrator steering notes: approved on-demand analyzer direction
    and serial ownership reason; implementation was kept in the prepared
    single-slice worktree because the files describe one shared loop contract.
- Agent session capture waivers: none
- Fresh analyzer:
  - Persona: `.agents/review-personas/post-feature-analyzer.md`
  - Solo process or analyzer: Solo process 845,
    `loop-analyzer-on-demand-post-feature-analyzer`; lane capture staged at
    `.orbit/agent-sessions/codex/loop-analyzer-on-demand-post-feature-analyzer-845`.
  - Verdict: yes - no findings; analyzer-default promotion justified and
    quality timing warning already covered.
- Candidate signals:
  - analyzer default ceremony -> promote -> compact loops now record
    verification, obvious signal classification, archive/session evidence, and
    `Fresh analyzer: not used - compact loop rationale` unless explicit request or
    escalation trigger applies.
  - quality-check timing warning -> already-covered -> existing
    cold-worktree/cache and stale-baseline timing records cover this warning
    class; no new signal record from a single successful timeout-disabled run.
- Accepted durable updates:
  - Harness analyzer routing updated so compact loops skip the standing analyzer
    by default, while explicit requests and escalation triggers still run it.
  - Loop packet template and finalization-helper guidance now accept
    `not used - compact loop rationale` as a normal compact-loop
    `Fresh analyzer` result.
  - Architecture and finalization Pest coverage pin the on-demand analyzer
    contract.
- Rejected or already-covered signals:
  - Quality-check duration drift warning already has durable coverage in
    `harness-signals/2026-06-24-cold-worktree-quality-gate-cache.md`,
    `harness-signals/2026-06-24-subgate-baseline-jitter-floor.md`, and
    `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md`;
    this run passed and does not justify a new signal record.
- Deferred follow-ups:
  - none
- No-new-signal rationale:
  - not applicable; this slice promoted the analyzer-default signal through
    direct guardrail updates and tests.
