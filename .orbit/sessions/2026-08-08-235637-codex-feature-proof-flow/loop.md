# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo project 92 / feature proof-flow graph + narrow static-docs routing
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-feature-proof-flow
- Branch: codex/feature-proof-flow

## Goal

Ship a browser-usable feature-development graph at
`docs/orbit-feature-development-graph.html` that reflects the agreed
proof/release model (venue vs environment, current vs proposed PROVE order,
protection ladder, separate release lifecycle), and apply a narrow
repository-root static-HTML acceptance-routing adjustment so `docs/**.html`
derives `automated` while `apps/docs` runtime/frontend paths stay unchanged.

## Scope

- Owned: `docs/orbit-feature-development-graph.html`; `bin/orbit-loop-contract.php`; `apps/gateway/tests/Feature/E2ESupport/FeatureAcceptanceTest.php`; `.orbit/loop.md` (feature packet only)
- Constraints: only owned tracked files + loop packet for prior FIX work; this packet update owns the boundary-guard test + loop only; never run `composer test:e2e*`; do not amend prior commits; do not broaden apps/docs runtime or frontend routing; preserve unrelated work
- Out of scope: HARNESS/PRODUCT_DECISIONS/skills/product-docs changes; fleet canary/rollback; merge/push/accept/main edits

## Proof

- Verification:
  - focused: passed - venue dataset includes repository static HTML → automated and apps/docs resources HTML → browser; `bin/orbit-gateway-pest --compact --filter="derives the minimum acceptance venue from changed files"` → 22 passed (22 assertions)
  - broader: passed - `composer quality-check` exit 0 (artifact `.orbit/quality-gates/quality-check-2026-08-08T213905Z-1e17cf71f055.json`; all subgates 0 including gateway_pest, cli_pest, docs_*, mago/rector/cargo, sdk_typescript_typecheck/build). First attempt exit 127 failed only on missing local `tsc` until `packages/sdk-typescript` npm install; re-run clean. Full `FeatureAcceptanceTest.php` 125 passed (617 assertions). `FeatureFinalizationGate` filter 157 passed (400 assertions).
  - runtime: not applicable
  - browser: passed - prior FIX screenshots at 1600x1000; connector/layout verified correct by Claude re-review of 2952d275; no further graph HTML change this step
- Blast radius: complete - evidence=repository inventory of orbit-loop-contract consumers (7 bin consumers + 2 feature test consumers: FeatureAcceptanceTest, FeatureFinalizationGateTest); every tracked .html file in the repo was mapped; only docs/orbit-feature-development-graph.html is under root docs/ and changes to automated; the two .orbit/session HTML archives and apps/macos/frontend/index.html remain unaffected at their existing venues; boundary probes keep apps/docs/resources/index.html as browser and mixed/non-docs paths escalate retained-incus/browser/host-macos as before; FeatureAcceptanceTest 125 passed; FeatureFinalizationGateTest suite filter 157 passed; result=no unintended venue widening beyond repository-root docs/**.html
- Review: passed - human-judgment=required
- Reviewed feature tip: 32984ae6e0053f51925f868b55fae40bf888d563
- Acceptance venue: automated
- Acceptance: accepted - user @ codex://threads/019fe22a-ab3f-77b3-a9e1-3eeafc2f5684#accept-and-land
- Accepted feature tip: 32984ae6e0053f51925f868b55fae40bf888d563
- Accepted main tip: fdae738ca6ec65f614033bb564e03829edbd4fdb

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
