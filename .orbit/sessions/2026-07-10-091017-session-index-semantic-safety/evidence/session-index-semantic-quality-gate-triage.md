# Quality Gate Triage Report

## Evidence

- Run evidence: `.orbit/quality-gates/quality-check-2026-07-10T065657Z-4a13fc57c8b1.json` at final HEAD `13586275a6c731b6469a20a1fa742cab3fe224aa`.
- Command output: `composer quality-gate:final-check`; aggregate and subgate timing warnings were warning-only.
- Changed files: six final tracked files; no CLI test or CLI runner file changed in this slice.
- Feature context: session-index parser/test repair plus repository-harness handoff guardrail; no product runtime change.
- Expected lane: `composer quality-check` and exact-HEAD `composer docs-lint`.
- Actual command: `composer quality-check`; final run 89 seconds, exit 0, 43/43 recorded subgates exit 0.
- Local baseline: `.orbit/quality-gates/baselines/quality-check.json`, sourced from `/Users/nckrtl/orbit/.orbit/quality-gates/quality-check-2026-06-26T141254Z-1dec81121c89.json` at commit `ac4ed14b15c1dcee81f0d389b73057a51e67a175`.
- Baseline compatibility: baseline has 35 subgates and `cli_pest=23.1s`; current artifact has 43 subgates and `cli_pest=85.4s`. Since the baseline commit, `apps/cli/tests` changed in 83 commits across 80 files with 15,473 insertions and 369 deletions.
- Warmth evidence: the earlier same-worktree run was 131 seconds with `cli_pest=127s`; the later same-command final run fell to 89 seconds with `cli_pest=85.4s`, but remained incomparable with the older 35-subgate baseline.
- Host context: load averages during triage were approximately 4.06, 4.21, and 4.93.

## Classification

- Primary: `stale/missing baseline`.
- Secondary: `expected slower coverage`; `host/env drift` remains a possible contributor.
- Confidence: high.
- Reasoning: the final artifact is current, green, and warmed relative to the first run; the dominant slow lane is CLI Pest, which this slice did not change. The baseline predates eight current subgates and substantial CLI test-suite growth, so it is not a compatible product-regression comparator.

## Next Command

- None for this slice. Do not rerun the aggregate gate or optimize the CLI lane from this evidence.
- The exact-HEAD standalone docs artifact was refreshed separately with `composer docs-lint` and passed in 6 seconds.

## Owner

- Future quality-baseline maintenance owns collecting repeated compatible main-checkout timings before accepting a new baseline.

## Baseline Action

- Keep the existing warning warning-only until repeated compatible evidence exists. Do not refresh the baseline from this single feature worktree run.

## Durable Signal Recommendation

- None. Existing `harness-signals/2026-06-24-cold-worktree-quality-gate-cache.md` and `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md` already cover cache warmth, CLI timing, and the prohibition on speculative parallelization.

## Hard Stops Honored

- Aggregate provision not run: yes.
- Live nodes not mutated: yes.
- Product fix deferred until assigned: yes.
