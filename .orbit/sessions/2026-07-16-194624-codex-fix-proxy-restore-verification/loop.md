# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-proxy-restore-verification`
- Branch: `codex/fix-proxy-restore-verification`

## Goal

A proxy restore is reported healthy only after a fresh scoped probe confirms the
repaired artifacts; remaining drift fails the exact action and stays visible.

## Scope

- Owned: gateway Doctor restore finalization, proxy restore regression coverage,
  Agent-push launcher selection, inner tool-script failure propagation, and the
  proxy Doctor/execution-lane behavior contracts.
- Constraints: preserve app/workspace scope; report the exact node, issue code,
  and failed operation; do not touch Hauzer on BEAST.
- Out of scope: Cloudflare edge certificate/API-token repair and unrelated
  fleet drift.

## Proof

- Verification:
  - focused: passed - gateway 506 tests / 3072 assertions; CLI 81 tests / 630 assertions; real gateway-container token round trip 1 test / 4 assertions
  - broader: passed - composer quality-check, docs-lint, and final-check at 55f76b22c1b723d6967abe09bdc5fc67a0e238e2; receipt `.orbit/quality-gates/quality-check-2026-07-16T173148Z-f9e2c3362d38.json`
  - runtime: passed - retained Incus topology `dev-2b9262` (`operator_gateway_app-prod_ingress` on `beast`) consumed gateway-local and Agent-push operation tokens, used `/home/orbit/.local/bin/orbit`, preserved partial backend enactment, reported `app-prod-1 / caddy.backend.install`, and did not report convergence after failed restore; live candidate `20260716T173535Z-55f76b22c` then re-applied Hauzer in backend -> router -> ingress order, fresh Doctor returned healthy, `proxy:list` returned artifact-backed `converged`, and all direct hops returned 200; evidence `.orbit/evidence/retained-incus-dev-2b9262.md`; evidence `.orbit/evidence/live-hauzer-main-20260716.md`
- Blast radius: complete - evidence=repository-wide Agent-push environment search and full monorepo quality gate; result=all subgates passed and the macOS exact-environment fixture now asserts the canonical launcher
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 55f76b22c1b723d6967abe09bdc5fc67a0e238e2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 55f76b22c1b723d6967abe09bdc5fc67a0e238e2
- Accepted main tip: 9f67ea554ab1e8a8139657c68946c9300183ec68

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
