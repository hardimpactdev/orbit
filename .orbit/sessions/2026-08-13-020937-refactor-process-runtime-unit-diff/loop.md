# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 67, process 2345
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-process-runtime-unit-diff
- Branch: refactor/process-runtime-unit-diff

## Goal

Move safe removal of extra Orbit-managed process runtime units into one focused service without changing Doctor repair behavior.

## Scope

- Owned: `DoctorProcessRestorer` extra-runtime removal, ownership guards, direct tests, and architecture guard.
- Constraints: Preserve the `process.runtime_unit_extra` action contract, destructive safety checks, and exception-to-failed-action behavior. Use constructor injection. Do not run human-only E2E lanes.
- Out of scope: FrankenPHP restore flows, process probing, Agent push contracts, and product behavior changes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Doctor/DoctorProcessExtraRuntimeRemoverTest.php tests/Unit/Services/Doctor/DoctorProcessRestorerArchitectureTest.php` completed with 22 tests and 143 assertions.
  - broader: passed - `composer quality-check` completed with all monorepo gates at exit code 0; receipt `.orbit/quality-gates/quality-check-2026-08-13T000055Z-8050d29e8304.json`.
  - runtime: passed - candidate=24ac09172d778e5ade08e2c10b2f8967ac25de44; venue=retained-incus; environment=dev-fixture; target=dev-4d06d5/orbit-e2e-dev-4d06d5-gateway; expected=owned systemd unit removed and non-Orbit unit preserved; observed=completed action removed owned fixture and null action preserved non-Orbit fixture; result=passed; evidence=`.orbit/evidence/process-extra-runtime-remover-incus.json`
- Blast radius: complete - evidence=Claude Opus Solo review, repository diff, focused integration tests, and full monorepo quality gate; result=only Doctor process extra-runtime removal changed, with no CLI, SDK, core, documentation, probe, or Agent push contract change.
- Review: passed - human-judgment=not-required - Claude Opus verified exact behavior parity, destructive safety coverage, action shape, Laravel design, and focused proof; verdict PASS.
- Reviewed feature tip: 24ac09172d778e5ade08e2c10b2f8967ac25de44
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 24ac09172d778e5ade08e2c10b2f8967ac25de44
- Accepted main tip: 75be0b61b75a7417ba25aba01d7f3d083a2000fe

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
