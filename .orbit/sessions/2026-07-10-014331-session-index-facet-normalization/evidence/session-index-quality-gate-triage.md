# Quality Gate Triage Report

Evidence:
- Run evidence: `.orbit/quality-gates/quality-check-2026-07-09T232618Z-a7a02e868001.json`, `.orbit/quality-gates/quality-check-2026-07-09T233912Z-bd76684e58e2.json`, and exact-commit `.orbit/quality-gates/quality-check-2026-07-09T234213Z-c4740726bb03.json`
- Command output: three successful `composer quality-check` runs; cold pass 101s, same-command warm pass 81s, exact committed-tree pass 79s
- Changed files: `bin/orbit-session-index` and `apps/gateway/tests/Feature/E2ESupport/SessionIndexTest.php`; no CLI source or CLI test change
- Feature context: deterministic session-index facet parsing; quality timing optimization is out of scope
- Expected lane: `composer quality-check`
- Actual command: `composer quality-check`, unchanged scheduler and environment for both comparisons

Classification:
- Primary: stale/missing baseline
- Secondary: host/env drift; cold-cache contribution on the first run
- Confidence: medium
- Reasoning: The warm comparison improved aggregate time from 101s to 81s and restored most Mago, Rector, Cargo, and package subgates near baseline, confirming a cold-cache component. CLI Pest remained 79.3s versus the seeded 23.1s baseline, but this feature does not touch CLI code/tests, the June 26 baseline source artifact is not present in the prepared worktree, and the current CLI suite is 2171 tests / 9089 assertions versus the older guarded signal's 1606 / 6427. The evidence therefore cannot support a product or test-harness regression attribution for this diff.

Next command:
- No further rerun in this slice. During the targeted monorepo phase, compare the standalone current-main CLI wrapper against a newly established compatible observation before assigning timing work.

Owner:
- Feature-loop program owner, targeted monorepo audit phase

Baseline action:
- warning-only until stable; do not refresh from either single feature-worktree run

Durable signal recommendation:
- none; `harness-signals/2026-06-24-cold-worktree-quality-gate-cache.md` and `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md` already cover the safe diagnostic and the known CLI lane boundary

Hard stops honored:
- Aggregate provision not run: yes
- Live nodes not mutated: yes
- Product fix deferred until assigned: yes
