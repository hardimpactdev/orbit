# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: production app route/runtime container mismatch found while deploying Hauzer
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-production-runtime-proxy
- Branch: codex/fix-production-runtime-proxy

## Goal

Production app proxy routes target the canonical app-instance runtime container
that Orbit actually creates, so re-registering Hauzer restores HTTP service.

## Scope

- Owned:
  - `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php`
  - `apps/gateway/tests/Feature/Actions/Apps/EnsureAppProxyRouteTest.php`
  - `apps/gateway/tests/Feature/Http/Api/AppStoreControllerTest.php`
- Constraints:
  - Preserve existing development bare-app route behavior when no canonical instance exists.
  - Reuse the existing app-instance proxy target abstractions.
  - Do not run automated E2E commands.
- Out of scope:
  - Slack notification implementation in Hauzer and Mealou.
  - Unrelated node bootstrap and fleet update baseline failures.

## Proof

- Verification:
  - focused: passed - exact-tip gateway slice 161 tests/905 assertions
  - broader: passed - `composer quality-check`; proof `.orbit/quality-gates/quality-check-2026-07-16T151044Z-ef7bdf3d3f4f.json`; baseline `composer test` failures remain confined to unrelated node bootstrap and fleet update tests
  - runtime: passed - retained Incus production topology `dev-f1a183` persisted `runtime-proxy-live.production`, rendered `http://orbit-app-runtime-proxy-live-production:8080`, and returned HTTP 200 through router -> app-prod Caddy -> exact instance container; proof `.orbit/evidence/runtime-proxy-incus.txt`
- Blast radius: complete - evidence=independent repository-wide target/app_instance/runtime_upstream search plus production, development, static, query, renderer, probe, fixer, and Doctor consumer review; result=no unaddressed consumers
- Review: passed - independent exact-tip review found no findings - human-judgment=not-required
- Reviewed feature tip: 8da4d45c3b4cd8c26ce9d178d5f32226ce09a34a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8da4d45c3b4cd8c26ce9d178d5f32226ce09a34a
- Accepted main tip: 4770546ab538d27bbe1a5ce68e703c8e5b71be03

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or `complete - evidence=repository-wide search, inventory, or lintable
check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
