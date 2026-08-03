# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none (delegated environment-lifecycle slice)
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-runtime-activation-progress`
- Branch: `codex/runtime-activation-progress`
- Feature tip: `3a1371e2e56987a344a7708f0875e8de48adcd68`
- Docs FIX commit: `3a1371e2e56987a344a7708f0875e8de48adcd68` — Align activation progress contracts
- Feature implementation commit: `3548db2df9917d2bdb7f27d7d8259d696784dd3a` — Show activation progress for soft wakes
- Merged main tip: `f6712518c6f5a67ade2fda5d150512484454ba91`

## Goal

Soft and cold development runtime wake both return the existing minimal 503
progress page immediately when activation work is required; process start and
dependency restore run only on the detached activation runner; already-awake
scopes still return 204 with no new operation. Soft runners only fence process
activation; cold runners restore/verify dependencies and clear cold markers.
Authority docs agree on that soft+cold shared page contract.

## Scope

- Owned: runtime activation behavior + authority docs alignment
- Constraints: UI logo+bar only; no push; no standing/live topology; no `composer test:e2e*`
- Out of scope: land (acceptance pending)

## Proof

- Verification:
  - focused: passed - 48 tests / 234 assertions (RuntimeHibernationTest + RuntimeColdActivationTest)
  - broader: passed - `composer quality-check` exit 0; receipt `.orbit/quality-gates/quality-check-2026-08-03T074841Z-05bcfaf3fd3a.json`
  - runtime: passed - retained topology `dev-b9f618` (`operator_gateway_app-dev`, provider=incus, host=beast) soft+cold first-hop 503 progress pages, exact original path/query meta refresh, detached runner completion, eventual same-URL 200; evidence=`.orbit/evidence/runtime-activation-retained-dev-b9f618.md`
- Blast radius: complete - evidence=`rg -n "cold scope creates|cold activation.*progress|original browser request continues only after activation succeeds|inline process start|soft.*wake|cold.*wake|runtime activation" PRODUCT_DECISIONS.md apps/docs/content apps/gateway/app apps/gateway/tests packages apps/cli --glob '!apps/docs/content/porting/**'`; result=only current unified soft+cold authority/implementation/tests and historical 2026-07-28 cold decision remain, no stale current contract
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 3a1371e2e56987a344a7708f0875e8de48adcd68
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3a1371e2e56987a344a7708f0875e8de48adcd68
- Accepted main tip: f6712518c6f5a67ade2fda5d150512484454ba91

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
