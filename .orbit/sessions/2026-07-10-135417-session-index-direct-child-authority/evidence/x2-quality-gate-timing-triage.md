# X2 quality-gate timing triage

## Quality Gate Triage Report

Evidence:

- Run evidence: `.orbit/quality-gates/quality-check-2026-07-10T113832Z-230a6aec6af1.json`.
- Command output: `composer quality-check` passed all 43 subgates; `composer quality-gate:final-check` emitted warning-only timing comparisons.
- Changed files: the four-file X2 parser/test/generated-index/recurrence patch; no CLI code or CLI test changed.
- Feature context: enforce Fresh-analyzer direct-child authority without widening the parser or verification surface.
- Expected lane: `composer quality-check` at the exact final commit.
- Actual command: `composer quality-check` at `53d16f55e427eff41e2f1c153caf52f6abe46003`.

Classification:

- Primary: `stale/missing baseline`.
- Secondary: current runner/suite-shape drift; cold-cache/host state remains possible but is not needed to explain the warning.
- Confidence: high that this is not an X2 product or test regression; medium on the exact cache/host contribution.
- Reasoning: the seeded baseline is dated 2026-06-26, claims 26 seconds, and names a source artifact that is absent from both the worktree and primary checkout. Fifteen unique passing July 10 quality artifacts provide the compatible current range: aggregate 77-131 seconds (median 89), CLI Pest 74.7-127 seconds (median 85.4), and gateway Pest 22-48.7 seconds (median 27.1). X2's 103-second aggregate, 100.1-second CLI Pest, and 33.6-second gateway Pest are inside those current ranges. The only changed Pest file adds two in-memory string fixtures; its focused six-test run completes in about 0.8 seconds. CLI Pest is untouched.

Next command:

- No rerun for classification. Existing repeated same-day compatible evidence is stronger than another warm run and avoids changing analyzer evidence mid-review.

Owner:

- Quality-baseline owner after the current feature program, using a primary-checkout compatible artifact rather than an isolated X2 change.

Baseline action:

- Keep the warning-only result; do not refresh from one feature artifact. Reassess the machine-local baseline from repeated compatible current-runner evidence in a separately owned baseline action.

Durable signal recommendation:

- None. `harness-signals/2026-06-24-cold-worktree-quality-gate-cache.md` and `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md` already route cache and CLI-lane timing questions. The current evidence identifies stale baseline data, not missing X2 guidance.

Hard stops honored:

- Aggregate provision not run: yes.
- Live nodes not mutated: yes.
- Product fix deferred until assigned: yes.
- `composer test:e2e*` not run: yes.
