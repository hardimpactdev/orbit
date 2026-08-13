# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 69; approved design `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-13-doctor-outcome-reconciler-design.md`
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-outcome-reconciler
- Branch: refactor/doctor-outcome-reconciler

## Goal

Move Doctor action-to-issue resolution and post-restore verification into one pure outcome reconciler without changing Doctor behavior.

## Scope

- Owned: successful action receipt resolution, database connection target matching, persistent DNS tool/node DNS/proxy/WebSocket drift annotation, shared Doctor issue resolution identity, direct tests, and the `DoctorReportRunner` delegation boundary.
- Constraints: Preserve public Doctor output, action and issue order, family-specific failure payloads, fresh observation authority, and all current restore/adopt/fix semantics. Do not run human-only E2E lanes.
- Out of scope: Restore convergence passes, family repair implementation, node and DNS projection mutation, command/API changes, and product documentation behavior changes.

## Proof

- Verification:
  - focused: passed - 159 tests, 1227 assertions; scoped Mago lint and format passed
  - broader: passed - composer quality-check
  - runtime: passed - candidate=b75f2a063dc31d5633c2f7a1b7625a5a0c784286; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=gateway --family=node --key=node.dns_mapping_mismatch --restore --json; expected=one completed Doctor action converges in one pass and the final fresh probe is healthy; observed=one completed node DNS action fixed the disposable drift in one pass then exact-key verify returned zero issues and both exits were 0; result=passed; evidence=`.orbit/evidence/doctor-outcome-reconciler-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide search for DoctorReportRunner construction, service-provider bindings, and stale moved-symbol references; result=all consumers use container injection, no positional binding exists, and NodeConverger keeps its separate selector-aware identity helper
- Review: passed - Claude Opus general review; human-judgment=not-required
- Reviewed feature tip: b75f2a063dc31d5633c2f7a1b7625a5a0c784286
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b75f2a063dc31d5633c2f7a1b7625a5a0c784286
- Accepted main tip: 85c029ed128056ac45c9c26ec3fa47798663295e

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
