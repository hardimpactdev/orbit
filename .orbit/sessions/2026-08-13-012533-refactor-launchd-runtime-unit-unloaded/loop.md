# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: User-approved Orbit stabilization sequence in this Codex thread
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-launchd-runtime-unit-unloaded
- Branch: refactor/launchd-runtime-unit-unloaded

## Goal

Doctor reports `process.runtime_unit_unloaded` when an expected Orbit-owned
launchd plist exists but its label is not loaded in the current user GUI
domain, without starting or restoring the process.

## Scope

- Owned: Launchd runtime-unit comparison, its focused Pest coverage, the process Doctor contract, and the dated product decision; primitive=launchd loaded state; transitions=success:emit one runtime incident for each existing unloaded expected unit|failure:leave missing, mismatched, and unavailable checks unchanged|retry:repeat Doctor observes current launchd state|stop-restart:Doctor never starts or restarts the unit|stale:loaded state comes only from the current launchd probe
- Constraints: Keep `process:start` as the explicit lifecycle command; do not add Doctor restore or adopt support; preserve existing process issue order and details.
- Out of scope: Crash-notification documentation cleanup and other process-family refactors.

## Proof

- Verification:
  - focused: passed - 100 Pest tests / 992 assertions; changed PHP Mago lint and analysis; docs-lint
  - broader: passed - `composer quality-check`; exit 0; all app and package subgates passed; artifact `.orbit/quality-gates/quality-check-2026-08-12T231618Z-d2779a02f057.json`
  - runtime: passed - candidate=7c9564087a1188f0cdf3a29cfc9112a0ea0aeceb; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=process --json; expected=source-mounted candidate reports the prepared app-dev node healthy with zero process issues; observed=healthy true with zero issues and zero runtime incidents; result=passed; evidence=`.orbit/evidence/launchd-runtime-unit-unloaded-retained-incus.json`
- Blast radius: complete - evidence=repository-wide search; result=all three launchd load decisions use LaunchdProcessRuntimePolicy, no other launchd duplicate remains, and the catalog, restore, adopt, docs, and ledger contracts agree
- Review: passed - human-judgment=not-required - Claude Opus Solo process 2342 found no actionable issue after reviewing the exact diff, tests, contracts, full quality artifact, and retained Incus receipt
- Reviewed feature tip: 7c9564087a1188f0cdf3a29cfc9112a0ea0aeceb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7c9564087a1188f0cdf3a29cfc9112a0ea0aeceb
- Accepted main tip: 0e4b735c6539663be87787644e0c7c315e193de0

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
