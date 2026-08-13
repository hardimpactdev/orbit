# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/88`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-update-all-lease-completeness`
- Branch: `fix/update-all-lease-completeness`

## Goal

An active `update:all` runner stops and records a durable terminal failure when
its heartbeat child dies, its reservation claim is invalid, or it receives
`SIGTERM`, before lease expiry can permit overlapping fleet mutation.

## Scope

- Owned: gateway update lease heartbeat monitoring, reservation-claim failure recording, focused tests, and update contract docs; primitive=one fenced fleet update lease; transitions=success:runner completes and releases its lease|failure:claim heartbeat or SIGTERM failure is durable and terminal|retry:a later start proceeds only after fenced release or bounded expiry|stop-restart:heartbeat failure stops the active runner|stale:invalid or stolen claims fail without releasing another owner lease
- Constraints: preserve owner-token fencing, start conflict UX, cleanup, and idempotency; use PCNTL already present in the gateway runtime; no new Doctor extraction.
- Out of scope: operation replay cursor work already landed; node-removal cleanup; release or publication; human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 26 focused lease/runner tests, 148 assertions
  - broader: passed - `composer quality-check`; candidate `e3f6600c3d392740d79d0b73e40adad635b37bce`; receipt `.orbit/quality-gates/quality-check-2026-08-13T153814Z-4d28f3de69d7.json`
  - runtime: passed - candidate=e3f6600c3d392740d79d0b73e40adad635b37bce; venue=retained-incus; environment=dev-fixture; target=dev-6b4aeb gateway; expected=invalid claim and heartbeat or SIGTERM loss stop active mutation and record a terminal state before lease expiry; observed=invalid claim recorded a durable terminal state heartbeat child death interrupted at 2.258 seconds while lease remained active and SIGTERM interrupted the callback; result=passed; evidence=`.orbit/evidence/update-all-lease-completeness-e3f6600c3.txt`
- Blast radius: complete - evidence=`rg -n "UpdateLeaseHeartbeatProcess|orbit:update-lease-heartbeat|claimFleetLease\\(|Update lease owner token mismatch|update_runner_failed" apps packages PRODUCT_DECISIONS.md`; result=the runtime boundary is gateway-local, all production callers and focused tests are covered, owner-token transport stays out of argv, and the update contract docs match
- Review: passed - reviewer=Claude Fable process 2389; human-judgment=not-required; verdict=PASS; one bounded duplicate-runner contract tradeoff and two non-blocking signal-window notes recorded
- Reviewed feature tip: e3f6600c3d392740d79d0b73e40adad635b37bce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e3f6600c3d392740d79d0b73e40adad635b37bce
- Accepted main tip: f4c0bd90731ab438d593c9a05c477d9ed2650f48

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
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
