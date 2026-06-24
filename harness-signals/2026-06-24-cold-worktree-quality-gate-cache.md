# Signal: Cold Worktree Quality Gate Cache

Status: guarded
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24

## Signal

A fresh implementation worktree produced a slow `composer quality-check`
artifact and timing warnings for many subgates. Initial triage misread the run
as a scheduler over-parallelization regression because a later run with
`ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS=4` completed much faster.

The comparison was invalid because two variables changed at once: scheduler
fan-out and cache warmth. PHPStan caches are one visible worktree-local cache
signal under each app/package `build/phpstan/`, and a fresh worktree may not
have those caches until after the first run.

## Prior Occurrences

This appeared during quality-gate timing optimization in a newly prepared
worktree. It is related to the broader rule that timing baselines are
machine-local and environment-sensitive, but the specific missing check was
fresh-worktree cache state.

## Guardrail Change

`.agents/skills/quality-gate-triage/SKILL.md` now requires quality-check timing
triage to distinguish cold-cache first-run evidence from warmed evidence before
optimizing the scheduler or individual tools.

`apps/docs/content/testing/quality-gates.md` now documents that first
`composer quality-check` runs in a new worktree can be cold-cache evidence,
should inspect cache state first, and may use a same-command warmed rerun as a
diagnostic confirmation.

## Verification

- First default `composer quality-check` in
  `/Users/nckrtl/orbit/.worktrees/quality-gate-timing-optimization`: passed in
  61s, with large subgate timing warnings.
- `ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS=4 composer quality-check`: passed in
  20s, but this was not conclusive because caches had been warmed.
- Same-command warmed default `composer quality-check`: passed in 18s, matching
  the local baseline.
- PHPStan cache directories existed after warm-up as one visible cache signal
  under:
  - `apps/cli/build/phpstan`
  - `apps/docs/build/phpstan`
  - `apps/e2e/build/phpstan`
  - `apps/gateway/build/phpstan`
  - `packages/core/build/phpstan`
  - `packages/sdk/build/phpstan`

## Reappearance Check

If `composer quality-check` reports a broad timing regression in a fresh
worktree, first inspect app/package-local cache state. If evidence is still
ambiguous, rerun the same quality-check command once after warm-up. Do not use
a different scheduler cap or environment variable as the first comparison
unless the goal is explicitly to test scheduling.

## Curation Notes

Keep while timing baselines are still being tuned. Retire only after the
quality-gate triage workflow reliably separates cold-cache runs from real
regressions.
