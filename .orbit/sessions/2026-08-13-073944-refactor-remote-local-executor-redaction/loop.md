# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/76/scratchpad/remotelocalexecutor--426`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-remote-local-executor-redaction`
- Branch: `refactor/remote-local-executor-redaction`

## Goal

Make local command options deterministic and keep all executor output redaction rules behind one tested service without changing remote execution behavior.

## Scope

- Owned: `RemoteLocalExecutor` option parsing, safe output, exception redaction, dependency wiring, and focused contract tests.
- Constraints: Preserve transport choice, operation records, activity records, exact redaction markers, redact-before-truncate order, and scrub-and-continue behavior.
- Out of scope: Transport selection, retries, operation lifecycle changes, activity event shape, strict typed-result rejection, and E2E commands.

## Proof

- Verification:
  - focused: passed - 84 tests, 484 assertions across options, output-redaction, executor, and architecture tests
  - broader: passed - full gateway suite: 6,188 passed, 4 skipped, 51,154 assertions; full `composer quality-check` receipt `.orbit/quality-gates/quality-check-2026-08-13T053238Z-03bdd9c3d0c6.json`
  - runtime: passed - candidate=a1baa5d95bea8f8cb459b64dd57d8f1a65eb213f; venue=retained-incus; environment=dev-fixture; command=internal:executor:verify through gateway local executor on topology dev-08af90; expected=command succeeds and the activity record stores the minted operation token only as redacted; observed=exit 0 with one succeeded local operation, two activity entries, one redacted token marker, and zero unsafe token lines; result=passed; evidence=`.orbit/evidence/remote-local-executor-redaction-retained-incus.json`
- Blast radius: complete - evidence=repository-wide `new RemoteLocalExecutor` inventory; result=all 26 construction sites use the explicit redactor dependency and the full gateway suite passes
- Review: passed - Claude Opus found no actionable findings and confirmed the redaction and transport contracts; human-judgment=not-required
- Reviewed feature tip: a1baa5d95bea8f8cb459b64dd57d8f1a65eb213f
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a1baa5d95bea8f8cb459b64dd57d8f1a65eb213f
- Accepted main tip: a01a4971fd7ce241b1e1482bb4f92482086ab7ff

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
