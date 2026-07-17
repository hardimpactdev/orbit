# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-app-analytics-public-route`
- Branch: `codex/app-analytics-public-route`

## Goal

Enabling app analytics enacts a public tracking-only `analytics.{app-domain}`
route through ingress and router, returns app-specific script and event endpoint
guidance, and disabling or changing hosts removes obsolete route artifacts.

## Scope

- Owned: app analytics binding enable/disable/show contracts, public analytics
  route enactment and cleanup, proxy drift repair for binding-owned routes,
  app-specific tracking endpoint output, focused gateway/CLI coverage, and
  analytics/app/proxy authority docs.
- Constraints: keep `analytics.orbit` private; expose only `/js/*` and
  `/api/event`; preserve forwarding identity; require public DNS/ACME readiness
  through normal ingress convergence; require a configured app domain and
  derive `analytics.{app-domain}` by default; keep enable failure-safe and
  cleanup explicit.
- Out of scope: Plausible site provisioning, Plausible credentials/API tokens,
  automatic application code injection, public dashboard access, and changing
  the singleton analytics backend model.

## Proof

- Verification:
  - focused: passed - post-rebase gateway diff suite 25 passed / 108
    assertions; post-review CLI analytics and compatibility suite 25 passed /
    75 assertions, including complete nested binding-frame assertions;
    gateway/CLI Mago format, docs lint, `git diff --check`, and secret scan
    passed
  - broader: passed - exact accepted candidate passed every component of
    `composer quality-check` with CPU budget 1; receipt
    `.orbit/quality-gates/quality-check-2026-07-17T152227Z-e83190eb00f5.json`
  - runtime: passed - retained Incus topology `dev-950d00`, kind
    `operator_gateway_app-prod_ingress`; proof
    `.orbit/evidence/analytics-public-route-runtime.txt`
- Blast radius: complete - evidence=bounded repository-wide analytics ownership, tracking endpoint, doctor-key, exclusion, and proxy-consumer search across product decisions, apps, packages, and bin; result=all owners and consumers align with no conflicting route owner or mutation surface
- Review: passed - findings=none; human-judgment=not-required
- Reviewed feature tip: afa8320dd651c0822ed7d61a68d571587c234f7d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: afa8320dd651c0822ed7d61a68d571587c234f7d
- Accepted main tip: 327a9a6eb3d190d7333dc7283e5810a691029495

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record either a
concrete not-required reason or complete evidence and result before acceptance;
`gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
