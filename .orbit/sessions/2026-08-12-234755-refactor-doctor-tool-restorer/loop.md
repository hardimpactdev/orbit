# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project `61`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-tool-restorer`
- Branch: `refactor/doctor-tool-restorer`

## Goal

Move DNS runtime recovery out of `DoctorReportRunner` and make fresh DNS deploy readiness deterministic without changing restore actions, failure actions, or convergence.

## Scope

- Owned: DNS runtime recovery delegation, bounded DNS task readiness, focused contract tests, and architecture checks
- Constraints: preserve DNS issue routing, existing action envelopes, post-restore verification, and current verify/restore behavior; cap readiness at 30 seconds
- Out of scope: normal tool recovery in `NodeConverger`, tool probing, tool adoption, tool command behavior, node recovery, proxy recovery, and product behavior changes

## Proof

- Verification:
  - focused: passed - DNS runtime probe and restorer suites: 26 tests, 82 assertions
  - broader: passed - Doctor service suites: 296 tests, 2117 assertions; full monorepo `composer quality-check` passed with receipt `.orbit/quality-gates/quality-check-2026-08-12T214637Z-f8230cfd8eb5.json`
  - runtime: passed - candidate=fdeb5ee70a052dd362a78a692d8ecc8a8d35dc9e; venue=retained-incus; environment=dev-fixture; target=gateway:tool.dns_container_missing; expected=Doctor restores missing orbit-dns and fresh verify is healthy; observed=fixed 1 with zero failures then converged and fresh verify healthy; result=passed; evidence=`.orbit/evidence/doctor-dns-runtime-retained-incus-dev-43ead1.json`
- Blast radius: complete - evidence=repository-wide search and Claude Opus review; result=DNS routing, restore envelopes, support source, container resolution, and documented behavior remain intact
- Review: passed - Claude Opus; human-judgment=not-required
- Reviewed feature tip: fdeb5ee70a052dd362a78a692d8ecc8a8d35dc9e
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: fdeb5ee70a052dd362a78a692d8ecc8a8d35dc9e
- Accepted main tip: b5f8c81a140817dc3df47ee123a8781085c6e27f

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
