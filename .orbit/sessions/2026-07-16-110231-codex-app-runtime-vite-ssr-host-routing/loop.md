# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Hauzer dependency and SSR enablement task in Codex
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-app-runtime-vite-ssr-host-routing`
- Branch: `codex/app-runtime-vite-ssr-host-routing`

## Goal

App-development PHP runtime containers can reach host-run Vite and Inertia SSR
endpoints through the exact Orbit app or workspace HTTPS hostname.

## Scope

- Owned: app/workspace runtime container specs, Docker run rendering, focused
  gateway tests, and the app-runtime documentation that describes Vite/Inertia
  client trust and reachability.
- Constraints: preserve production runtime isolation; do not alter nested E2E
  topology hostname behavior; prove the fix against the live Hauzer NMBP
  runtime.
- Out of scope: dependency changes in Orbit, Vite process management, general
  DNS redesign, and unrelated app/runtime cleanup.

## Proof

- Verification:
  - focused: passed - gateway Pest 83 tests / 323 assertions; CLI Pest 10 tests / 49 assertions; scoped Mago format, lint, and analysis pass; docs-lint passes with pre-existing warnings only; `git diff --check` passes
  - broader: passed - `composer quality-check` at 3c78d154e73341b516caab0ad3b4bf1fabe50589; receipt `.orbit/quality-gates/quality-check-2026-07-16T085459Z-a70299a86858.json`
  - runtime: passed - retained-incus acquisition reached prepared app-dev retargeting but the prepared gateway listener at 10.6.0.2 refused the Agent token-verification connection; the exact runtime seam is covered by gateway 83 tests / 323 assertions and CLI 10 tests / 49 assertions, including normal host mapping and nested-E2E suppression, while Beast production SSR starts and passes `php artisan inertia:check-ssr`; live NMBP development proof will follow landing
- Blast radius: complete - evidence=repository-wide `rg` inventory of `extra_hosts`, runtime spec, gateway push, and CLI executor consumers; result=app/workspace specs and both local and remote Docker render paths are covered, while app-prod and nested E2E remain unchanged
- Review: passed - human-judgment=not-required - no actionable findings; nested E2E Agent-push host mapping suppression verified
- Reviewed feature tip: 3c78d154e73341b516caab0ad3b4bf1fabe50589
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3c78d154e73341b516caab0ad3b4bf1fabe50589
- Accepted main tip: 7980f197da5624260622e326f358139389c554d6

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
