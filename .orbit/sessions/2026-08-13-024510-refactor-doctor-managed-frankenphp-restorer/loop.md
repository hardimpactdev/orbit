# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 68, process 2347
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-managed-frankenphp-restorer
- Branch: refactor/doctor-managed-frankenphp-restorer

## Goal

Move canonical FrankenPHP process-row and runtime-container repair into one focused Doctor service with explicit dependencies.

## Scope

- Owned: managed FrankenPHP missing-process repair, app/workspace runtime-container repair, process-intent refresh, TLS preparation, Agent-push container convergence, direct tests, and the `DoctorProcessRestorer` delegation boundary.
- Constraints: Preserve process-family issue routing, placement checks, repair order, action payloads, exception-to-failed-action behavior, and the Agent-push execution lane. Do not run human-only E2E lanes.
- Out of scope: Process probing, node-owned service repair, generic runtime-unit repair, public command or API changes, and product behavior changes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Doctor/DoctorManagedFrankenPhpRuntimeRestorerTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php tests/Unit/Services/Doctor/DoctorProcessRestorerArchitectureTest.php tests/Unit/Services/Doctor/DoctorRestoreSupportTest.php` (129 tests, 1,027 assertions)
  - broader: passed - `composer quality-check`; receipt=`.orbit/quality-gates/quality-check-2026-08-13T004245Z-cd5bf14fdbb4.json`
  - runtime: passed - candidate=bfba62059704bae438fa1b623bec5e9663c76f74; venue=retained-incus; environment=dev-fixture; target=dev-80802f/gateway-to-app-dev-1; expected=managed FrankenPHP repair creates a running canonical container and repeats without changes; observed=the final synced candidate returned unchanged after the prior created outcome and the running healthy container hash matched process intent; result=passed; evidence=`.orbit/evidence/managed-frankenphp-restorer-incus.json`
- Blast radius: complete - evidence=repository-wide consumer and constructor search, action-payload diff review, 129 focused regression tests, full monorepo quality check, and retained Incus repair; result=DoctorReportRunner remains the only production entry, the new services resolve through explicit dependencies, action shapes are unchanged, and Agent-push runtime repair creates and then leaves the canonical container unchanged
- Review: passed - Claude Opus Solo process 2347 found no behavior drift or unresolved finding on the exact candidate; human-judgment=not-required
- Reviewed feature tip: bfba62059704bae438fa1b623bec5e9663c76f74
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bfba62059704bae438fa1b623bec5e9663c76f74
- Accepted main tip: efe7a85c7f3609a5ba6312748af6d84b8759a619

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
