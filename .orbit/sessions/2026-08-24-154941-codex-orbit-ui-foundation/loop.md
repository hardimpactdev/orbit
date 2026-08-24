# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-orbit-ui-foundation
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-orbit-ui-foundation
- Branch: codex-orbit-ui-foundation

## Goal

Provide a fresh Launch-based Laravel/Inertia Orbit UI at apps/ui that runs through Orbit at https://orbit.nmbp, exposes Agentation during local development, and configures the existing PHP/Saloon SDK for the trusted gateway endpoint without migrating the native desktop dashboard.

## Scope

- Owned: `apps/ui`, root monorepo integration needed to install and verify it, Orbit product docs for the separate UI boundary, and the local `orbit.nmbp` development Instance; primitive=fresh browser UI foundation; transitions=success:HTTPS starter page and Agentation load|failure:explicit Laravel or Orbit runtime error|retry:normal browser reload after repair|stop-restart:Orbit-managed app and Vite processes restart cleanly|stale:n/a
- Constraints: copy the clean Launch starter at `9b76da62c67d5e5b7794572b66c0aa7c0703412e`; use Laravel 13, React 19, Inertia 3, VitePlus, Launch UI, shadcn, and the monorepo PHP SDK; browser input must not supply gateway or node identity. Producer: `apps/ui` Laravel routes and SDK binding. Consumers: local browser, Vite/Agentation, root quality tooling, and the configured gateway. Dangerous invariants: no native dashboard migration, no browser-controlled identity, no alternate `/api` endpoint, and no dependency on the gateway app's internal controllers.
- Out of scope: production `ui` role, `app.orbit`, delegated production request identity, Orbit dashboard screens, native macOS changes, and migration of `apps/macos/frontend`.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-fresh-ui-app.md` | complete | 0192321b0df2940905f51c79ac5728da53e9886a |
| `.orbit/slices/02-monorepo-ui-integration.md` | complete | bbf9f07e1c88f54bee9c4bf4a6367dd5d239f65a |
| `.orbit/slices/03-worker-bootstrap-reliability.md` | complete | e66111d1a59a17e0d2a41fc9808393365c5c2096 |
| `.orbit/slices/04-ui-browser-acceptance-route.md` | complete | 5c53eb54eb1b96513f9a708466fc49b97fb46264 |
| `.orbit/slices/05-review-corrections.md` | complete | adbc12e29d31f13ac8821b92a02ae8c79529a747 |
| `.orbit/slices/06-review-enumerations.md` | complete | e64ec55945d1cc8e88eff46a001d08380b221462 |
| `.orbit/slices/07-readme-enumeration.md` | complete | c2b598568db0e3cc8023d2beb1e29380f7f2df80 |

## Proof

- Verification:
  - focused: passed - corrected UI configuration tests (4 passed), gateway architecture and verification contracts (44 passed, 611 assertions), instruction-budget contract (43 passed, 595 assertions), UI Pint, and Librarian docs lint at candidate `adbc12e29d31f13ac8821b92a02ae8c79529a747`
  - broader: passed - `composer quality-check` passed all 51 subgates at candidate `c2b598568db0e3cc8023d2beb1e29380f7f2df80`; artifact `.orbit/quality-gates/quality-check-2026-08-24T134606Z-e1509655278a.json`; exact-candidate standalone docs lint passed; evidence-only `composer quality-gate:final-check` passed with warning-only local timing variance
  - runtime: passed - candidate=c2b598568db0e3cc8023d2beb1e29380f7f2df80; venue=browser; environment=dev-fixture; target=https://orbit.nmbp; expected=HTTP 200 with trusted TLS Vite assets and Agentation plus Laravel toolbar mounted without browser errors; observed=Orbit instance source is this worktree, HTTP 200 with TLS verify result 0, Vite client returned 200 and Vite client plus app asset loaded, both Agentation roots and the Laravel toolbar root mounted, no browser errors, and FrankenPHP/Vite processes running; result=passed; evidence=`.orbit/evidence/ui-runtime/receipt.md`
- Blast radius: complete - evidence=`.orbit/workers/logs/ui-general-review.log` exhaustive tracked non-vendor monorepo-unit enumeration sweep; result=all nine governance surfaces route `apps/ui`, while release payload, E2E topology, command-catalog, per-app ignore, gateway Boost, and other excluded lists are inapplicable
- Review: passed - same Claude general reviewer closed all nine findings on exact candidate `c2b598568db0e3cc8023d2beb1e29380f7f2df80`; consumed the exact full-gate, docs-lint, browser runtime, and feature receipt evidence; VERDICT=PASS; BLAST_RADIUS=complete; human-judgment=not-required
- Reviewed feature tip: c2b598568db0e3cc8023d2beb1e29380f7f2df80
- Acceptance venue: browser
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c2b598568db0e3cc8023d2beb1e29380f7f2df80
- Accepted main tip: fc3df72073122adb440615bffc66f320d900c8ea

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
