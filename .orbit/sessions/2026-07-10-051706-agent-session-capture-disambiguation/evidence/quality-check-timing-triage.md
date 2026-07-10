# Quality Gate Timing Triage

## Evidence

- Run: `.orbit/quality-gates/quality-check-2026-07-10T024256Z-7d482bfc2960.json`.
- Command: `composer quality-check`; exit 0; all subgates passed.
- Worktree/head at run: `agent-session-capture-disambiguation`, `1c24084e0bf02a37498f21e00693b65b695d48d3`, matching the current uncommitted-diff checkout.
- Changed test surface: one deterministic gateway fixture was added to `AgentSessionArchiveTest.php`; no sleeps, network, provider calls, E2E group, or slow group was added.
- Local baseline: `.orbit/quality-gates/baselines/quality-check.json`, 26s from 2026-06-26. Its named source artifact `quality-check-2026-06-26T141254Z-1dec81121c89.json` was not seeded into this worktree, so runner/cache compatibility cannot be independently confirmed.
- Latest duration: 100s. CLI Pest was 96.8s versus 23.1s baseline; gateway Pest was 32.3s versus 19.2s; several unrelated Mago, Rector, and docs lanes also slowed together.
- Exact-commit warmed run: `.orbit/quality-gates/quality-check-2026-07-10T031432Z-22a9975a3292.json`; commit `9edb2dbd4d0f9ab3e1b4f6937286a66ee0e9d1a0`; exit 0; every subgate passed.
- Warmed duration: 82s, improved 18% from the first 100s run. CLI Pest improved from 96.8s to 79.1s and gateway Pest from 32.3s to 22.2s. The prepared-worktree baseline had already shown the unchanged CLI suite taking about 69s, so the remaining 79.1s is consistent with this environment and not attributable to the gateway-only fixture.

## Classification

- Primary: `stale/missing baseline`.
- Secondary: cold-cache or host/environment load remains plausible.
- Confidence: high for stale/missing baseline; medium for secondary host/environment load.
- Reasoning: both runs passed completely, warming improved the aggregate without approaching the 26s historical value, and the unchanged CLI suite dominates both measurements. The tiny gateway-only fixture cannot explain CLI, docs, and static-tool timing together. The source artifact needed to prove the historical baseline's runner/cache compatibility remains missing, while the current preparation and exact-commit measurements agree on a much slower CLI runtime.

## Next Command And Owner

- Next command: complete; the exact-commit warmed diagnostic above is the required final rerun. Do not spend another aggregate run on this slice.
- Owner: feature orchestrator.
- Baseline action: do not refresh from these incompatible measurements. Keep the warning visible until a separately owned baseline slice can compare or reseed a provenance-complete artifact.
- Durable signal recommendation: no new signal or code change in this slice. Existing quality-gate triage correctly routed the warning, required one exact-commit comparison, and blocked premature baseline refresh. The missing source artifact is already the decisive evidence boundary; widening this harness-capture slice would not improve correctness.

## Hard Stops

- Aggregate provision/E2E run: no.
- Live nodes mutated: no.
- Product fix attempted: no.
