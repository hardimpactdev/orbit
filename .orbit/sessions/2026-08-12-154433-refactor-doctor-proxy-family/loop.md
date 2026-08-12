# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-proxy-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-proxy-family`
- Branch: `refactor/doctor-proxy-family`

## Goal

Doctor delegates proxy observation to a focused family service while probe and adopt share one exact route-scope rule.

## Scope

- Owned: Doctor proxy family probe orchestration, shared proxy route inventory selection, direct coverage, and runner delegation architecture checks.
- Constraints: Preserve public output, route scope, progress counts and order, failure diagnostics, issue order, restore behavior, and adopt behavior.
- Out of scope: Proxy mutation extraction, other Doctor families, final node coordinator, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - 115 tests, 965 assertions; scoped Mago lint and analysis passed
  - broader: passed - `composer quality-check`
  - runtime: passed - candidate=a23b6ab2b69c8465e1160fec09afc1abe7eebf6c; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=proxy --json; expected=two successful stable proxy-family reports; observed=both runs exited 0 and the complete JSON matched byte-for-byte; result=passed; evidence=`.orbit/evidence/doctor-proxy-family-retained-incus.md`
- Blast radius: complete - evidence=repository-wide removed-symbol search and full Doctor suite; result=no dangling callers and 386 Doctor tests passed
- Review: passed - human-judgment=not-required; Claude Opus found no blockers and confirmed probe order, counts, payloads, scope, failure conversion, restore, and adopt are preserved
- Reviewed feature tip: a23b6ab2b69c8465e1160fec09afc1abe7eebf6c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a23b6ab2b69c8465e1160fec09afc1abe7eebf6c
- Accepted main tip: 91db0eca3ec67ead2d52f7f20921f4288f0b0cd3

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
