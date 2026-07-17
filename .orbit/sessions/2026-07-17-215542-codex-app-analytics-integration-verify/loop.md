# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: source task `codex://threads/019f70ba-5591-7c71-92de-837647763115`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-app-analytics-integration-verify`
- Branch: `codex/app-analytics-integration-verify`

## Goal

`app:analytics enable` returns an exact app integration contract and provider-neutral DNS expectation without claiming public readiness, while a new read-only `app:analytics verify` command reports stored route intent and caller-observed DNS, TLS, tracking-script, and dashboard-exposure readiness without provider, Plausible-site, or app-repository mutations.

## Scope

- Owned: app analytics command docs and catalog; gateway analytics binding response and read-only verification metadata; CLI enable/show rendering; new CLI verification command and caller-side probe service; focused gateway and CLI Pest coverage.
- Constraints: analytics remains app-level; preserve existing response fields; verification uses stored hosts only, follows no redirects, sends no event by default, and never repairs drift; docs, tests, and code stay aligned; no `composer test:e2e*` execution.
- Out of scope: provider DNS mutation, Cloudflare orchestration, Plausible credentials/site creation, app script or CSP edits, application deployment, browser automation, public dashboard access, event-persistence claims, and the separate generic deploy-stream fallback fix.

## Proof

- Verification:
  - focused: passed - exact tip `9d83270325140500414f5e0fc869af46d22545ba` CLI analytics command/readiness/classifier checks `45 passed (99 assertions)` plus classifier/verifier rerun `37 passed (61 assertions)`; gateway binding/API checks `26 passed (133 assertions)`; focused Mago format/lint/analyze, Rector, and `composer docs-lint` pass
  - broader: passed - exact tip `9d83270325140500414f5e0fc869af46d22545ba` passes `composer quality-check` with CPU budget 6 and its recorded exact-tip quality profile; exact-tip `composer docs-lint` and `composer quality-gate:final-check` pass with timing warnings only
  - runtime: passed - retained topology `dev-8b4bb8` (`operator_gateway_app-dev_app-prod`) proves launcher/source identity, human and JSON verification diagnostics, public not-ready behavior, explicit CGNAT rejection with HTTPS probes skipped, and restored binding state; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=bounded repository inventory of command, gateway route, OpenAPI entry, shared classifier consumers, lock/host-limit constants and tests, generated catalog, plus retained topology proof; result=one aligned implementation with no competing surface
- Review: passed - exact tip `9d83270325140500414f5e0fc869af46d22545ba`; findings=none; human-judgment=not-required
- Reviewed feature tip: 9d83270325140500414f5e0fc869af46d22545ba
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9d83270325140500414f5e0fc869af46d22545ba
- Accepted main tip: dbfbe89412d8b518a7dbfc8b917e09fcccb17289

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
an explicit rationale or `complete - evidence=repository-wide search, inventory,
or lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
