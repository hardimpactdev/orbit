# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Claude Opus Solo review will use the committed feature tip.
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-fleet-coordinator`
- Branch: `refactor/doctor-fleet-coordinator`

## Goal

Move Doctor multi-node verify coordination into a small service without changing the public command, report, progress, ordering, worker limit, or fallback behavior.

## Scope

- Owned: fleet target selection, sequential and concurrent checks, progress and final report assembly, and compatibility delegates in `DoctorReportRunner`.
- Constraints: preserve the five-node worker limit, stable node and issue order, callback sequence, exact report shape, and all inline fallback paths.
- Out of scope: single-node check rules, worker subprocess protocol, restore and adopt behavior, command changes, and human-only E2E tests.

## Proof

- Verification:
  - focused: passed - 187 Doctor tests, 1,344 assertions; scoped Mago lint and format passed
  - broader: passed - full `composer quality-check`; evidence=`.orbit/quality-gates/profiles/2026-08-12T16-39-17Z-f53ddd9e267b/gateway_pest.junit.xml`
  - runtime: passed - candidate=f53ddd9e267b7ed6c98b54a3239e19f599206f73; venue=retained-incus; environment=dev-fixture; command=orbit doctor --all --json; expected=deterministic multi-node report with fixture drift exit; observed=two exit-1 reports were byte-identical with SHA-256 83ddafe5e04f42b68269951468f255f0804c1416b6e101ca0592082697f034c4 over beast LAN; result=passed; evidence=`.orbit/evidence/doctor-fleet-coordinator-retained-incus.json`
- Blast radius: complete - evidence=repository-wide search; result=old fleet coordinator methods remain only as negative architecture assertions and all new service references are scoped to Doctor code and tests
- Review: passed - Claude Opus Solo process 2315; human-judgment=not-required
- Reviewed feature tip: f53ddd9e267b7ed6c98b54a3239e19f599206f73
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f53ddd9e267b7ed6c98b54a3239e19f599206f73
- Accepted main tip: 01a2779be12df34d21d3d1dd71a6dc62a2fde107

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
